<?php

declare(strict_types=1);

namespace System\Support\Integrations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use RuntimeException;
use System\Support\FinancialCalculator;
use System\Support\PortalMagicLinkIssuer;

/**
 * Adaptador IntegroPAY (Pág. 36 del PDF) — cero hardcodeo (RNF-8).
 *
 * - Credenciales por emisor: `issuers.integropay_merchant_token_ref` / `integropay_device_token_ref`
 *   solo guardan NOMBRES de variables; el valor real se resuelve con `SecretResolver`.
 * - URL base, timeout y vigencia de ligas: servicio global `integropay` de `external_service_configs`.
 * - Modo efectivo por emisor: producción solo si el servicio global está habilitado en producción,
 *   tiene URL base y el emisor tiene ambas credenciales resueltas; en cualquier otro caso, sandbox.
 * - Sandbox: liga `SBX-{hex}` hacia `/portal/sandbox-pay/{id}` (simulación en 1 clic). Nunca se
 *   permite con `APP_ENV=production` para que nadie "pague" sin una pasarela real.
 */
final class IntegroPayGateway implements PaymentGatewayInterface
{
    public const SANDBOX_LINK_PREFIX = 'SBX-';

    public const SANDBOX_TRANSACTION_PREFIX = 'SBX-TX-';

    /** Vigencia fija de la liga sandbox (24 h). */
    public const SANDBOX_TTL_HOURS = 24;

    private const DEFAULT_TIMEOUT = 20;

    private const DEFAULT_TTL_HOURS = 24;

    /** Estados de la pasarela que confirman el cobro. */
    private const APPROVED_STATUSES = ['approved', 'paid'];

    public function __construct(
        private readonly IntegrationTransport $transport = new DefaultIntegrationTransport(),
    ) {
    }

    public function createPaymentLink(array $params): array
    {
        $issuerId = (int) ($params['issuer_id'] ?? 0);
        $clientId = (int) ($params['client_id'] ?? 0);
        if ($issuerId < 1 || $clientId < 1) {
            throw new InvalidArgumentException('La liga de pago requiere emisor y cliente.');
        }
        $amount = FinancialCalculator::normalizeMoney((string) ($params['amount'] ?? '0'));
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('El monto de la liga debe ser mayor a 0.00.');
        }
        $concept = mb_substr(trim((string) ($params['concept'] ?? '')), 0, 200);
        if ($concept === '') {
            throw new InvalidArgumentException('El concepto de la liga es obligatorio.');
        }
        $currency = in_array((string) ($params['currency'] ?? 'MXN'), ['MXN', 'USD'], true)
            ? (string) ($params['currency'] ?? 'MXN')
            : 'MXN';

        /** @var list<array{invoice_id: int, amount: string}> $documents */
        $documents = $params['documents'] ?? [];
        $documentsTotal = '0.00';
        foreach ($documents as $document) {
            $documentsTotal = bcadd($documentsTotal, FinancialCalculator::normalizeMoney((string) $document['amount']), 2);
        }
        if ($documents !== [] && bccomp($documentsTotal, $amount, 2) !== 0) {
            throw new InvalidArgumentException('La suma de los documentos no coincide con el monto de la liga.');
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($this->isSandboxForIssuer($issuerId)) {
            self::assertSandboxAllowed();
            $externalLinkId = self::SANDBOX_LINK_PREFIX . bin2hex(random_bytes(8));
            $url = PortalMagicLinkIssuer::frontendBaseUrl() . '/portal/sandbox-pay/' . $externalLinkId;
            $expiresAt = $nowUtc->modify(sprintf('+%d hours', self::SANDBOX_TTL_HOURS));
            $isSandbox = true;
        } else {
            $ttlHours = (int) ExternalServiceRegistry::configValue('integropay', 'link_ttl_hours', self::DEFAULT_TTL_HOURS);
            $expiresAt = $nowUtc->modify(sprintf('+%d hours', max(1, $ttlHours)));
            $remote = $this->createRemoteLink($issuerId, $amount, $currency, $concept, $expiresAt);
            $externalLinkId = $remote['external_link_id'];
            $url = $remote['url'];
            $isSandbox = false;
        }

        $nowStr = $nowUtc->format('Y-m-d H:i:s');
        $linkId = (int) Capsule::connection()->transaction(function () use (
            $params,
            $issuerId,
            $clientId,
            $amount,
            $currency,
            $concept,
            $externalLinkId,
            $url,
            $expiresAt,
            $documents,
            $nowStr,
        ): int {
            $id = (int) Capsule::table('payment_links')->insertGetId([
                'issuer_id' => $issuerId,
                'client_id' => $clientId,
                'purchase_id' => isset($params['purchase_id']) && (int) $params['purchase_id'] > 0 ? (int) $params['purchase_id'] : null,
                'contract_id' => isset($params['contract_id']) && (int) $params['contract_id'] > 0 ? (int) $params['contract_id'] : null,
                'amount' => $amount,
                'currency' => $currency,
                'concept' => $concept,
                'gateway' => 'integropay',
                'external_link_id' => $externalLinkId,
                'transaction_id' => null,
                'url' => mb_substr($url, 0, 500),
                'status' => 'created',
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'payment_id' => null,
                'paid_at' => null,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
            foreach ($documents as $document) {
                Capsule::table('payment_link_documents')->insert([
                    'payment_link_id' => $id,
                    'invoice_id' => (int) $document['invoice_id'],
                    'amount' => FinancialCalculator::normalizeMoney((string) $document['amount']),
                ]);
            }

            return $id;
        });

        return [
            'payment_link_id' => $linkId,
            'external_link_id' => $externalLinkId,
            'url' => $url,
            'amount' => $amount,
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            'is_sandbox' => $isSandbox,
        ];
    }

    public function verifyTransactionById(int $issuerId, string $transactionId): array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '' || mb_strlen($transactionId) > 120) {
            throw new InvalidArgumentException('El identificador de la transacción es inválido.');
        }

        $isSandboxTx = str_starts_with($transactionId, self::SANDBOX_TRANSACTION_PREFIX);
        if ($this->isSandboxForIssuer($issuerId)) {
            self::assertSandboxAllowed();
            if (!$isSandboxTx) {
                return $this->rejected($transactionId, null, 'unknown', true);
            }

            return $this->verifySandboxTransaction($issuerId, $transactionId);
        }

        // Un emisor productivo jamás acepta transacciones simuladas.
        if ($isSandboxTx) {
            return $this->rejected($transactionId, null, 'rejected', false);
        }

        $credentials = $this->credentialsFor($issuerId);
        $response = $this->transport->getJson(
            $this->apiUrl() . '/transactions/' . rawurlencode($transactionId),
            $this->authHeaders($credentials),
            $this->timeout(),
        );
        $body = is_array($response['body']) ? $response['body'] : [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        $rawAmount = trim((string) ($data['amount'] ?? ''));
        $amount = preg_match('/^\d+(\.\d+)?$/', $rawAmount) === 1 ? FinancialCalculator::normalizeMoney($rawAmount) : '0.00';
        $linkId = trim((string) ($data['payment_link_id'] ?? $data['link_id'] ?? $data['external_link_id'] ?? ''));

        return [
            'transaction_id' => (string) ($data['id'] ?? $data['transaction_id'] ?? $transactionId),
            'external_link_id' => $linkId !== '' ? $linkId : null,
            'status' => $status !== '' ? $status : 'unknown',
            'approved' => in_array($status, self::APPROVED_STATUSES, true) && bccomp($amount, '0.00', 2) > 0,
            'amount' => $amount,
            'is_sandbox' => false,
        ];
    }

    public function isSandboxForIssuer(int $issuerId): bool
    {
        if (!ExternalServiceRegistry::isProduction('integropay') || $this->apiUrl() === '') {
            return true;
        }
        $credentials = $this->resolveCredentials($issuerId);

        return $credentials === null;
    }

    /** ID de transacción determinista de la simulación sandbox (un pago por liga). */
    public static function sandboxTransactionIdFor(string $externalLinkId): string
    {
        if (!str_starts_with($externalLinkId, self::SANDBOX_LINK_PREFIX)) {
            throw new InvalidArgumentException('La liga no es de sandbox.');
        }

        return self::SANDBOX_TRANSACTION_PREFIX . substr($externalLinkId, strlen(self::SANDBOX_LINK_PREFIX));
    }

    /** El simulador sandbox nunca opera en un entorno productivo. */
    public static function isSandboxAllowed(): bool
    {
        return strtolower((string) (getenv('APP_ENV') ?: '')) !== 'production';
    }

    private static function assertSandboxAllowed(): void
    {
        if (!self::isSandboxAllowed()) {
            throw new RuntimeException('El pago con tarjeta no está disponible: el emisor no tiene credenciales productivas de IntegroPAY.');
        }
    }

    /**
     * Sandbox: la "pasarela" es la propia tabla `payment_links` (liga vigente o ya pagada).
     *
     * @return array{transaction_id: string, external_link_id: string|null, status: string, approved: bool, amount: string, is_sandbox: bool}
     */
    private function verifySandboxTransaction(int $issuerId, string $transactionId): array
    {
        $externalLinkId = self::SANDBOX_LINK_PREFIX . substr($transactionId, strlen(self::SANDBOX_TRANSACTION_PREFIX));
        $link = Capsule::table('payment_links')
            ->where('external_link_id', $externalLinkId)
            ->where('issuer_id', $issuerId)
            ->first(['id', 'status', 'amount', 'expires_at']);
        if ($link === null) {
            return $this->rejected($transactionId, null, 'not_found', true);
        }

        $status = (string) $link->status;
        $expired = $status === 'created' && (string) $link->expires_at <= gmdate('Y-m-d H:i:s');
        if (!in_array($status, ['created', 'paid'], true) || $expired) {
            return $this->rejected($transactionId, $externalLinkId, $expired ? 'expired' : $status, true);
        }

        return [
            'transaction_id' => $transactionId,
            'external_link_id' => $externalLinkId,
            'status' => 'approved',
            'approved' => true,
            'amount' => FinancialCalculator::normalizeMoney((string) $link->amount),
            'is_sandbox' => true,
        ];
    }

    /**
     * @return array{external_link_id: string, url: string}
     */
    private function createRemoteLink(
        int $issuerId,
        string $amount,
        string $currency,
        string $concept,
        DateTimeImmutable $expiresAt,
    ): array {
        $credentials = $this->credentialsFor($issuerId);
        $response = $this->transport->postJson(
            $this->apiUrl() . '/payment-links',
            $this->authHeaders($credentials) + ['Idempotency-Key' => 'operam-link-' . bin2hex(random_bytes(12))],
            [
                'amount' => $amount,
                'currency' => $currency,
                'description' => $concept,
                'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            ],
            $this->timeout(),
        );
        $body = is_array($response['body']) ? $response['body'] : [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $externalId = trim((string) ($data['id'] ?? $data['link_id'] ?? $data['payment_link_id'] ?? ''));
        $url = trim((string) ($data['url'] ?? $data['payment_url'] ?? ''));
        if ($externalId === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('IntegroPAY no devolvió una liga de pago válida.');
        }

        return ['external_link_id' => mb_substr($externalId, 0, 120), 'url' => $url];
    }

    /** @return array{merchant_token: string, device_token: string} */
    private function credentialsFor(int $issuerId): array
    {
        $credentials = $this->resolveCredentials($issuerId);
        if ($credentials === null) {
            throw new RuntimeException('El emisor no tiene credenciales de IntegroPAY definidas en el entorno.');
        }

        return $credentials;
    }

    /** @return array{merchant_token: string, device_token: string}|null */
    private function resolveCredentials(int $issuerId): ?array
    {
        $issuer = Capsule::table('issuers')->where('id', $issuerId)->first([
            'integropay_merchant_token_ref',
            'integropay_device_token_ref',
        ]);
        if ($issuer === null) {
            return null;
        }
        $merchant = SecretResolver::get((string) ($issuer->integropay_merchant_token_ref ?? ''));
        $device = SecretResolver::get((string) ($issuer->integropay_device_token_ref ?? ''));
        if ($merchant === null || $device === null) {
            return null;
        }

        return ['merchant_token' => $merchant, 'device_token' => $device];
    }

    /**
     * @param array{merchant_token: string, device_token: string} $credentials
     * @return array<string, string>
     */
    private function authHeaders(array $credentials): array
    {
        return [
            'Authorization' => 'Bearer ' . $credentials['merchant_token'],
            'X-Device-Token' => $credentials['device_token'],
        ];
    }

    private function apiUrl(): string
    {
        return rtrim((string) ExternalServiceRegistry::configValue('integropay', 'api_url', ''), '/');
    }

    private function timeout(): int
    {
        return max(1, (int) ExternalServiceRegistry::configValue('integropay', 'timeout_seconds', self::DEFAULT_TIMEOUT));
    }

    /**
     * @return array{transaction_id: string, external_link_id: string|null, status: string, approved: bool, amount: string, is_sandbox: bool}
     */
    private function rejected(string $transactionId, ?string $externalLinkId, string $status, bool $isSandbox): array
    {
        return [
            'transaction_id' => $transactionId,
            'external_link_id' => $externalLinkId,
            'status' => $status,
            'approved' => false,
            'amount' => '0.00',
            'is_sandbox' => $isSandbox,
        ];
    }
}
