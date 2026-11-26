<?php

declare(strict_types=1);

namespace System\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use System\Core\Context;
use System\Core\Result;
use System\Support\AuditLogger;
use System\Support\CollectionRules;
use System\Support\FinancialCalculator;
use System\Support\Integrations\IncomingEventRecorder;
use System\Support\Integrations\IntegroPayGateway;
use System\Support\Integrations\PaymentGatewayInterface;
use System\Support\Integrations\SecretResolver;
use System\Support\PurchaseFulfillment;
use Throwable;

/**
 * Webhook verificado de IntegroPAY — `POST /api/webhooks/integropay/{issuerId}` (Pág. 36 / RNF-2).
 *
 * 1. Autenticación: token del emisor (`?token=` o cabecera `X-Webhook-Token`) comparado en tiempo
 *    constante (`hash_equals`) contra `SecretResolver::get(issuers.integropay_webhook_token_ref)`.
 * 2. Deduplicación: `incoming_events` (origin `integropay`, external_id = transaction_id). Un reenvío
 *    ya procesado responde HTTP 200 `{ idempotent_replay: true }` sin efectos.
 * 3. Verificación: JAMÁS se confía en el payload; se consulta la transacción por ID contra la
 *    pasarela (o `payment_links` en sandbox) y se exige estado aprobado + monto exacto de la liga.
 * 4. Aplicación en UNA transacción: liga pagada → compra de Tienda (documento pagado + bolsas) o
 *    abono a documentos (total/exigible/parcial) → `collection_statuses` → reactivación RF-6.3 →
 *    `incoming_events.processed = 1`.
 *
 * El simulador sandbox del Portal invoca `processTransaction()` con exactamente el mismo flujo.
 */
final class IntegroPayWebhookService extends CustomService
{
    public const ROUTE_PREFIX = 'webhooks/integropay/';

    private const EVENT_TYPE = 'payment.notification';

    private const AUDIT_MODULE = 'portal';

    private readonly PaymentGatewayInterface $gateway;

    /** @param array<string, list<\Closure>> $hooks */
    public function __construct(?PaymentGatewayInterface $gateway = null, array $hooks = [])
    {
        parent::__construct($hooks);
        $this->gateway = $gateway ?? new IntegroPayGateway();
    }

    public static function isWebhookRequest(string $uri): bool
    {
        return str_starts_with(strtolower(trim($uri, '/')), self::ROUTE_PREFIX);
    }

    public function handle(Context $context): Result
    {
        if ($context->getMethod() !== 'POST') {
            return Result::fail('Método no soportado.', 405);
        }

        $parts = explode('/', trim($context->getUri(), '/'));
        $issuerId = isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : 0;
        if ($issuerId < 1 || count($parts) !== 3) {
            return Result::fail('Webhook no encontrado.', 404);
        }

        // Mismo 401 para emisor inexistente, sin token configurado o token inválido (no revela ids).
        $issuer = Capsule::table('issuers')->where('id', $issuerId)->first(['id', 'integropay_webhook_token_ref']);
        $expected = $issuer !== null
            ? SecretResolver::get((string) ($issuer->integropay_webhook_token_ref ?? ''))
            : null;
        /** @var array<string, mixed> $query */
        $query = $context->getData('_query', []);
        $provided = trim((string) ($query['token'] ?? $context->getHeader('X-Webhook-Token') ?? ''));
        if ($expected === null || $provided === '' || !hash_equals($expected, $provided)) {
            return Result::fail('Webhook no autorizado.', 401);
        }

        $payload = $context->getRequest();
        $transactionId = self::extractTransactionId($payload);
        if ($transactionId === '') {
            return Result::fail('El webhook no incluye el identificador de la transacción.', 422);
        }

        return $this->processTransaction($issuerId, $transactionId, $payload);
    }

    /**
     * Flujo único (webhook real o simulador sandbox): dedup → verificación → aplicación atómica.
     *
     * @param array<string, mixed> $rawPayload Solo se guarda como evidencia; nunca decide nada.
     */
    public function processTransaction(int $issuerId, string $transactionId, array $rawPayload): Result
    {
        try {
            $recorded = IncomingEventRecorder::record('integropay', $transactionId, self::EVENT_TYPE, [
                'issuer_id' => $issuerId,
                'payload' => $rawPayload,
            ]);
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }
        $eventId = (int) $recorded['event']['id'];
        if ($recorded['is_duplicate'] && $recorded['event']['processed']) {
            return Result::ok(
                ['idempotent_replay' => true, 'transaction_id' => $transactionId],
                'Evento ya procesado (idempotente).',
            );
        }

        try {
            $verification = $this->gateway->verifyTransactionById($issuerId, $transactionId);
        } catch (Throwable $e) {
            IncomingEventRecorder::markProcessed($eventId, 'Verificación fallida: ' . $e->getMessage());

            return Result::fail('No se pudo verificar la transacción con IntegroPAY.', 502);
        }
        if (!$verification['approved']) {
            IncomingEventRecorder::markProcessed($eventId, 'Transacción no aprobada: ' . $verification['status']);

            return Result::ok(
                ['applied' => false, 'status' => $verification['status'], 'transaction_id' => $transactionId],
                'La transacción no está aprobada; no se aplicó ningún pago.',
            );
        }

        try {
            $outcome = Capsule::connection()->transaction(
                fn (): array => $this->applyVerifiedTransaction($issuerId, $transactionId, $verification, $eventId),
            );
        } catch (Throwable $e) {
            IncomingEventRecorder::markProcessed($eventId, $e->getMessage());

            return Result::fail($e->getMessage(), 422);
        }

        if ($outcome['idempotent_replay']) {
            return Result::ok(
                ['idempotent_replay' => true, 'transaction_id' => $transactionId],
                'Evento ya procesado (idempotente).',
            );
        }

        return Result::ok($outcome, $outcome['contract_reactivated']
            ? 'Pago aplicado · contrato reactivado automáticamente'
            : 'Pago aplicado');
    }

    /**
     * @param array{transaction_id: string, external_link_id: string|null, status: string, approved: bool, amount: string, is_sandbox: bool} $verification
     * @return array<string, mixed>
     */
    private function applyVerifiedTransaction(int $issuerId, string $transactionId, array $verification, int $eventId): array
    {
        $linkQuery = Capsule::table('payment_links')->where('issuer_id', $issuerId)->lockForUpdate();
        $link = $verification['external_link_id'] !== null
            ? (clone $linkQuery)->where('external_link_id', $verification['external_link_id'])->first()
            : null;
        $link ??= (clone $linkQuery)->where('transaction_id', $transactionId)->first();
        if ($link === null) {
            throw new RuntimeException('La transacción no corresponde a ninguna liga de pago de este emisor.');
        }

        $linkStatus = (string) $link->status;
        if ($linkStatus === 'paid') {
            if ((string) ($link->transaction_id ?? '') !== $transactionId) {
                throw new RuntimeException('La liga ya fue pagada con otra transacción.');
            }
            IncomingEventRecorder::markProcessed($eventId);

            return ['idempotent_replay' => true];
        }
        if ($linkStatus === 'cancelled') {
            throw new RuntimeException('La liga de pago está cancelada.');
        }
        // Monto exacto verificado contra la pasarela (no contra el payload del webhook).
        $linkAmount = FinancialCalculator::normalizeMoney((string) $link->amount);
        if (bccomp($verification['amount'], $linkAmount, 2) !== 0) {
            throw new RuntimeException(sprintf(
                'El monto verificado (%s) no coincide con la liga (%s).',
                $verification['amount'],
                $linkAmount,
            ));
        }

        $actor = $this->webhookActor((int) $link->client_id);
        $today = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        $nowUtc = gmdate('Y-m-d H:i:s');

        $paymentIds = [];
        $contractIds = [];
        $unapplied = '0.00';
        if ($link->purchase_id !== null) {
            $settled = PurchaseFulfillment::settleCardPurchase((int) $link->purchase_id, $transactionId, $actor);
            $paymentIds[] = $settled['payment_id'];
            $contractId = (int) (Capsule::table('purchases')->where('id', (int) $link->purchase_id)->value('contract_id') ?? 0);
            if ($contractId > 0) {
                $contractIds[] = $contractId;
            }
        } else {
            $applied = $this->applyToDocuments($link, $transactionId, $actor, $today, $nowUtc);
            $paymentIds = $applied['payment_ids'];
            $contractIds = $applied['contract_ids'];
            $unapplied = $applied['unapplied'];
        }

        Capsule::table('payment_links')->where('id', (int) $link->id)->update([
            'status' => 'paid',
            'transaction_id' => mb_substr($transactionId, 0, 120),
            'payment_id' => $paymentIds[0] ?? null,
            'paid_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        // RF-6.3: recalcula cartera y, si se liquidó el adeudo >90 días, reactiva en esta transacción.
        $reactivated = false;
        $collections = (new CollectionsService())->withAuthContext($actor);
        foreach (array_values(array_unique($contractIds)) as $contractId) {
            $status = $collections->recalculateContractCollectionStatus($contractId, $paymentIds[0] ?? null);
            $reactivated = $reactivated || $status['reactivated'];
        }

        IncomingEventRecorder::markProcessed($eventId);

        AuditLogger::log(
            $actor,
            self::AUDIT_MODULE,
            'payment_links',
            (string) $link->id,
            'status_change',
            sprintf('Pago IntegroPAY verificado por $%s (transacción %s)', $linkAmount, $transactionId),
            ['status' => $linkStatus],
            [
                'status' => 'paid',
                'transaction_id' => $transactionId,
                'payment_ids' => $paymentIds,
                'is_sandbox' => $verification['is_sandbox'],
                'unapplied_amount' => $unapplied,
                'contract_reactivated' => $reactivated,
            ],
        );

        return [
            'idempotent_replay' => false,
            'applied' => true,
            'payment_link_id' => (int) $link->id,
            'transaction_id' => $transactionId,
            'amount' => $linkAmount,
            'payment_ids' => $paymentIds,
            'purchase_id' => $link->purchase_id !== null ? (int) $link->purchase_id : null,
            'contract_reactivated' => $reactivated,
            'is_sandbox' => $verification['is_sandbox'],
        ];
    }

    /**
     * Abona el monto de la liga a sus documentos (montos congelados al crear la liga). Si algún
     * documento ya se liquidó por otra vía, el remanente se aplica FIFO (vencimiento más antiguo
     * primero) al resto de documentos abiertos del contrato.
     *
     * @param array<string, mixed> $actor
     * @return array{payment_ids: list<int>, contract_ids: list<int>, unapplied: string}
     */
    private function applyToDocuments(object $link, string $transactionId, array $actor, string $today, string $nowUtc): array
    {
        $remaining = FinancialCalculator::normalizeMoney((string) $link->amount);
        $paymentIds = [];
        $contractIds = [];
        $touched = [];

        $documents = Capsule::table('payment_link_documents')
            ->where('payment_link_id', (int) $link->id)
            ->orderBy('id')
            ->get(['invoice_id', 'amount']);
        foreach ($documents as $document) {
            if (bccomp($remaining, '0.00', 2) <= 0) {
                break;
            }
            $target = FinancialCalculator::normalizeMoney((string) $document->amount);
            $cap = bccomp($target, $remaining, 2) < 0 ? $target : $remaining;
            $applied = $this->applyToInvoice((int) $document->invoice_id, (int) $link->client_id, $cap, $transactionId, $actor, $today, $nowUtc);
            $touched[(int) $document->invoice_id] = true;
            if ($applied !== null) {
                $paymentIds[] = $applied['payment_id'];
                $contractIds[] = $applied['contract_id'];
                $remaining = bcsub($remaining, $applied['amount'], 2);
            }
        }

        if (bccomp($remaining, '0.00', 2) > 0 && $link->contract_id !== null) {
            $spill = Capsule::table('invoices')
                ->where('contract_id', (int) $link->contract_id)
                ->where('client_id', (int) $link->client_id)
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->where('balance', '>', 0)
                ->orderBy('due_date')
                ->orderBy('id')
                ->pluck('id');
            foreach ($spill as $invoiceId) {
                if (bccomp($remaining, '0.00', 2) <= 0) {
                    break;
                }
                if (isset($touched[(int) $invoiceId])) {
                    continue;
                }
                $applied = $this->applyToInvoice((int) $invoiceId, (int) $link->client_id, $remaining, $transactionId, $actor, $today, $nowUtc);
                if ($applied !== null) {
                    $paymentIds[] = $applied['payment_id'];
                    $contractIds[] = $applied['contract_id'];
                    $remaining = bcsub($remaining, $applied['amount'], 2);
                }
            }
        }
        if ($link->contract_id !== null) {
            $contractIds[] = (int) $link->contract_id;
        }

        return ['payment_ids' => $paymentIds, 'contract_ids' => $contractIds, 'unapplied' => $remaining];
    }

    /**
     * Abona hasta `$maxAmount` al documento (nunca más que su saldo).
     *
     * @param array<string, mixed> $actor
     * @return array{payment_id: int, contract_id: int, amount: string}|null
     */
    private function applyToInvoice(
        int $invoiceId,
        int $clientId,
        string $maxAmount,
        string $transactionId,
        array $actor,
        string $today,
        string $nowUtc,
    ): ?array {
        $invoice = Capsule::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
        if (
            $invoice === null
            || (int) $invoice->client_id !== $clientId
            || in_array((string) $invoice->status, ['paid', 'cancelled'], true)
        ) {
            return null;
        }
        $balance = FinancialCalculator::normalizeMoney((string) $invoice->balance);
        $amount = bccomp($maxAmount, $balance, 2) < 0 ? $maxAmount : $balance;
        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        $applied = CollectionRules::applyPayment(
            (string) $invoice->paid_amount,
            $balance,
            $amount,
            (string) $invoice->due_date,
            $today,
        );
        $status = CollectionRules::resolveOpenInvoiceStatus($applied['paid_amount'], $applied['balance'], (string) $invoice->due_date, $today);

        $paymentId = (int) Capsule::table('payments')->insertGetId([
            'invoice_id' => $invoiceId,
            'contract_id' => (int) $invoice->contract_id,
            'client_id' => $clientId,
            'amount' => $amount,
            'payment_method' => 'card_integropay',
            'reference' => mb_substr($transactionId, 0, 120),
            'payment_date' => $today,
            'notes' => 'Pago con tarjeta desde el Portal del Cliente (IntegroPAY).',
            'recorded_by_user_id' => null,
            'is_reversed' => 0,
            'reversed_at' => null,
            'reversal_reason' => null,
            'reversed_by_user_id' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);
        Capsule::table('invoices')->where('id', $invoiceId)->update([
            'paid_amount' => $applied['paid_amount'],
            'balance' => $applied['balance'],
            'status' => $status,
            'updated_at' => $nowUtc,
        ]);

        // Documento SPEI de una compra de Tienda liquidado con tarjeta: acredita sus bolsas.
        if ($status === 'paid' && isset($invoice->purchase_id) && $invoice->purchase_id !== null) {
            PurchaseFulfillment::fulfill((int) $invoice->purchase_id, $actor);
        }

        return ['payment_id' => $paymentId, 'contract_id' => (int) $invoice->contract_id, 'amount' => $amount];
    }

    /** @return array<string, mixed> */
    private function webhookActor(int $clientId): array
    {
        $segmentId = Capsule::table('clients')->where('id', $clientId)->value('segment_id');

        return [
            'name' => 'IntegroPAY (webhook verificado)',
            'email' => null,
            'segment_id' => $segmentId !== null ? (int) $segmentId : null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function extractTransactionId(array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        foreach ([$payload['transaction_id'] ?? null, $data['transaction_id'] ?? null, $data['id'] ?? null, $payload['id'] ?? null] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return mb_substr(trim((string) $candidate), 0, 120);
            }
        }

        return '';
    }
}
