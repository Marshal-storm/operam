<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use System\Core\Context;
use System\Core\Result;
use System\Support\ContractExigibleResolver;
use System\Support\FinancialCalculator;
use System\Support\Integrations\IntegroPayGateway;
use System\Support\Integrations\PaymentGatewayInterface;
use Throwable;

/**
 * Pago de saldo con tarjeta desde el Portal (IntegroPAY) + simulador sandbox.
 *
 *  - `POST portal/payments/create-link`            → liga por monto exigible, total o parcial.
 *  - `GET  portal/sandbox-pay/{externalLinkId}`     → datos de la liga simulada (solo sandbox).
 *  - `POST portal/sandbox-pay/{externalLinkId}/confirm` → aprueba la liga con el MISMO flujo
 *    transaccional del webhook verificado (`IntegroPayWebhookService::processTransaction`).
 */
final class PortalPaymentsService extends PortalScopedService
{
    private const MODES = ['exigible', 'total', 'custom'];

    private readonly PaymentGatewayInterface $gateway;

    /** @param array<string, list<\Closure>> $hooks */
    public function __construct(?PaymentGatewayInterface $gateway = null, array $hooks = [])
    {
        parent::__construct($hooks);
        $this->gateway = $gateway ?? new IntegroPayGateway();
    }

    public function handle(Context $context): Result
    {
        if (!$this->bootClaims($context)) {
            return Result::fail('Sesión del portal requerida.', 401);
        }

        // Se usa la URI cruda: el id externo de la liga distingue mayúsculas (`SBX-…`).
        $parts = explode('/', trim($context->getUri(), '/'));
        $section = strtolower($parts[1] ?? '');
        $method = $context->getMethod();

        if ($section === 'payments' && strtolower($parts[2] ?? '') === 'create-link' && $method === 'POST') {
            return $this->createLink($context->getRequest());
        }
        if ($section === 'sandbox-pay' && isset($parts[2]) && $parts[2] !== '') {
            $externalLinkId = $parts[2];
            $action = strtolower($parts[3] ?? '');
            if ($method === 'GET' && $action === '') {
                return $this->sandboxDetail($externalLinkId);
            }
            if ($method === 'POST' && $action === 'confirm') {
                return $this->sandboxConfirm($externalLinkId);
            }
        }

        return Result::fail('Recurso del portal no encontrado.', 404);
    }

    /**
     * Liga de pago del saldo de un contrato. Los documentos se asignan FIFO (vencimiento más
     * antiguo primero — excepción documentada del orden por reciente: así se liquida primero
     * lo que más días de atraso acumula).
     *
     * @param array<string, mixed> $payload
     */
    private function createLink(array $payload): Result
    {
        $contract = $this->ownedContract(self::positiveInt($payload['contract_id'] ?? null) ?? 0);
        if ($contract === null) {
            return Result::fail('Este contrato no pertenece a tu cuenta.', 403);
        }
        $mode = strtolower(trim((string) ($payload['mode'] ?? '')));
        if (!in_array($mode, self::MODES, true)) {
            return Result::fail('Selecciona cómo deseas pagar: monto exigible, saldo total u otro monto.', 422);
        }

        $openQuery = Capsule::table('invoices')
            ->where('contract_id', (int) $contract->id)
            ->where('client_id', $this->clientId())
            ->whereIn('status', self::OPEN_INVOICE_STATUSES)
            ->where('balance', '>', 0)
            ->orderBy('due_date')
            ->orderBy('id');
        $requestedIds = $payload['invoice_ids'] ?? null;
        if (is_array($requestedIds) && $requestedIds !== []) {
            $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $requestedIds)));
            $openQuery->whereIn('id', $ids);
            $invoices = $openQuery->get(['id', 'folio', 'balance']);
            if ($invoices->count() !== count($ids)) {
                return Result::fail('Uno o más documentos seleccionados no están pendientes de pago en este contrato.', 422);
            }
        } else {
            $invoices = $openQuery->get(['id', 'folio', 'balance']);
        }

        $totalOpen = '0.00';
        foreach ($invoices as $invoice) {
            $totalOpen = bcadd($totalOpen, FinancialCalculator::normalizeMoney((string) $invoice->balance), 2);
        }
        if (bccomp($totalOpen, '0.00', 2) <= 0) {
            return Result::fail('No tienes saldo pendiente por pagar en este contrato.', 422);
        }

        try {
            $amount = $this->resolveAmount($mode, $payload, $contract, $totalOpen);
        } catch (InvalidArgumentException $e) {
            return Result::fail($e->getMessage(), 422);
        }

        // Asignación FIFO BCMath del monto a los documentos abiertos.
        $documents = [];
        $remaining = $amount;
        foreach ($invoices as $invoice) {
            if (bccomp($remaining, '0.00', 2) <= 0) {
                break;
            }
            $balance = FinancialCalculator::normalizeMoney((string) $invoice->balance);
            $portion = bccomp($remaining, $balance, 2) < 0 ? $remaining : $balance;
            $documents[] = ['invoice_id' => (int) $invoice->id, 'amount' => $portion, 'folio' => (string) $invoice->folio];
            $remaining = bcsub($remaining, $portion, 2);
        }

        $modeLabel = match ($mode) {
            'exigible' => 'monto exigible',
            'total' => 'saldo total',
            default => 'pago parcial',
        };
        try {
            $link = $this->gateway->createPaymentLink([
                'issuer_id' => (int) $contract->issuer_id,
                'client_id' => $this->clientId(),
                'contract_id' => (int) $contract->id,
                'purchase_id' => null,
                'amount' => $amount,
                'currency' => 'MXN',
                'concept' => sprintf('Pago de %s · Contrato %s', $modeLabel, (string) $contract->folio),
                'documents' => array_map(
                    static fn (array $doc): array => ['invoice_id' => $doc['invoice_id'], 'amount' => $doc['amount']],
                    $documents,
                ),
            ]);
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        return Result::ok($link + [
            'mode' => $mode,
            'documents' => array_map(
                static fn (array $doc): array => ['invoice_id' => $doc['invoice_id'], 'folio' => $doc['folio'], 'amount' => $doc['amount']],
                $documents,
            ),
        ], 'Liga de pago generada', 201);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws InvalidArgumentException
     */
    private function resolveAmount(string $mode, array $payload, object $contract, string $totalOpen): string
    {
        if ($mode === 'total') {
            return $totalOpen;
        }
        if ($mode === 'exigible') {
            $exigible = ContractExigibleResolver::forContractId((int) $contract->id);
            $exigibleAmount = FinancialCalculator::normalizeMoney(
                (string) ($exigible['result']['exigible_amount'] ?? '0.00'),
            );
            $amount = bccomp($exigibleAmount, $totalOpen, 2) < 0 ? $exigibleAmount : $totalOpen;
            if (bccomp($amount, '0.00', 2) <= 0) {
                throw new InvalidArgumentException('Tu contrato está al corriente: no hay monto exigible por pagar.');
            }

            return $amount;
        }

        $raw = trim(str_replace([',', ' ', '$'], '', (string) ($payload['amount'] ?? '')));
        if (preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
            throw new InvalidArgumentException('Ingresa un monto válido (máximo 2 decimales).');
        }
        $amount = FinancialCalculator::normalizeMoney($raw);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('El monto debe ser mayor a $0.00.');
        }
        if (bccomp($amount, $totalOpen, 2) > 0) {
            throw new InvalidArgumentException(sprintf('El monto no puede exceder tu saldo pendiente ($%s).', $totalOpen));
        }

        return $amount;
    }

    private function sandboxDetail(string $externalLinkId): Result
    {
        $link = $this->ownedSandboxLink($externalLinkId);
        if ($link === null) {
            return Result::fail('Liga de pago no encontrada.', 404);
        }

        $status = (string) $link->status;
        if ($status === 'created' && (string) $link->expires_at <= gmdate('Y-m-d H:i:s')) {
            $status = 'expired';
        }
        $documents = Capsule::table('payment_link_documents as d')
            ->join('invoices as i', 'i.id', '=', 'd.invoice_id')
            ->where('d.payment_link_id', (int) $link->id)
            ->orderBy('d.id')
            ->get(['i.folio', 'd.amount'])
            ->map(static fn (object $row): array => [
                'folio' => (string) $row->folio,
                'amount' => FinancialCalculator::normalizeMoney((string) $row->amount),
            ])
            ->all();

        return Result::ok([
            'external_link_id' => (string) $link->external_link_id,
            'status' => $status,
            'amount' => FinancialCalculator::normalizeMoney((string) $link->amount),
            'currency' => (string) $link->currency,
            'concept' => (string) $link->concept,
            'issuer_name' => (string) (Capsule::table('issuers')->where('id', (int) $link->issuer_id)->value('name') ?? ''),
            'contract_folio' => $link->contract_id !== null
                ? (string) (Capsule::table('contracts')->where('id', (int) $link->contract_id)->value('folio') ?? '')
                : null,
            'purpose' => $link->purchase_id !== null ? 'purchase' : 'balance',
            'expires_at' => (string) $link->expires_at,
            'paid_at' => $link->paid_at !== null ? (string) $link->paid_at : null,
            'documents' => $documents,
        ], 'Liga sandbox');
    }

    private function sandboxConfirm(string $externalLinkId): Result
    {
        $link = $this->ownedSandboxLink($externalLinkId);
        if ($link === null) {
            return Result::fail('Liga de pago no encontrada.', 404);
        }
        if (!$this->gateway->isSandboxForIssuer((int) $link->issuer_id)) {
            return Result::fail('Este emisor ya cobra en producción: el simulador no está disponible.', 403);
        }

        $transactionId = IntegroPayGateway::sandboxTransactionIdFor((string) $link->external_link_id);
        $result = (new IntegroPayWebhookService($this->gateway))->processTransaction(
            (int) $link->issuer_id,
            $transactionId,
            [
                'channel' => 'portal_sandbox_simulator',
                'external_link_id' => (string) $link->external_link_id,
                'confirmed_by' => (string) ($this->claims['contact_email'] ?? ''),
            ],
        );

        // El simulador nunca "aprueba" en silencio: si la verificación rechaza, se informa como error.
        $data = $result->getData();
        if ($result->isSuccess() && is_array($data) && ($data['applied'] ?? null) === false) {
            return Result::fail('La liga de pago ya no está vigente. Genera una nueva desde el portal.', 422);
        }

        return $result;
    }

    /** Liga sandbox propia del cliente; nunca disponible en `APP_ENV=production`. */
    private function ownedSandboxLink(string $externalLinkId): ?object
    {
        if (
            !IntegroPayGateway::isSandboxAllowed()
            || !str_starts_with($externalLinkId, IntegroPayGateway::SANDBOX_LINK_PREFIX)
            || mb_strlen($externalLinkId) > 120
        ) {
            return null;
        }

        return Capsule::table('payment_links')
            ->where('external_link_id', $externalLinkId)
            ->where('client_id', $this->clientId())
            ->first();
    }
}
