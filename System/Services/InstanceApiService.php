<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use System\Core\Context;
use System\Core\Result;
use System\Support\BalanceEngine;
use System\Support\FinancialCalculator;
use Throwable;

/**
 * API entrante para instancias ERP — `/api/instance-api/*` (RF-4.4, RF-6.2, RF-7.1).
 * Autenticación por Bearer de instancia (SHA-256 vs `instances.api_token_hash`).
 */
final class InstanceApiService extends CustomService
{
    public function handle(Context $context): Result
    {
        $auth = $this->authenticateInstance($context);
        if ($auth instanceof Result) {
            return $auth;
        }

        $method = $context->getMethod();
        $uri = strtolower(trim($context->getUri(), '/'));
        $parts = $uri === '' ? [] : explode('/', $uri);
        // instance-api / {action}
        $action = isset($parts[1]) ? strtolower((string) $parts[1]) : '';
        $request = $context->getRequest();

        return match ($action) {
            'balance' => $method === 'GET'
                ? $this->getBalance($auth, $request)
                : Result::fail('Método no soportado.', 405),
            'consumption' => $method === 'POST'
                ? $this->postConsumption($auth, $request)
                : Result::fail('Método no soportado.', 405),
            'collection-status' => $method === 'GET'
                ? $this->getCollectionStatus($auth)
                : Result::fail('Método no soportado.', 405),
            'portal-link' => $method === 'GET'
                ? $this->getPortalLink($auth)
                : Result::fail('Método no soportado.', 405),
            default => Result::fail('Endpoint de instancia no encontrado.', 404),
        };
    }

    /**
     * @return array{instance:object,contract:object}|Result
     */
    private function authenticateInstance(Context $context): array|Result
    {
        $headers = $context->getHeaders();
        $token = $this->extractToken($headers);
        if ($token === null || $token === '') {
            return Result::fail('Token de instancia requerido.', 401);
        }

        $hash = hash('sha256', $token);
        $instance = Capsule::table('instances')
            ->where('api_token_hash', $hash)
            ->first();
        if ($instance === null) {
            return Result::fail('Token de instancia inválido.', 401);
        }

        $status = strtolower((string) $instance->status);
        $action = strtolower((string) (explode('/', trim($context->getUri(), '/'))[1] ?? ''));
        $allowedForSuspended = $action === 'collection-status';
        if (!in_array($status, ['operational', 'active', 'maintenance'], true)) {
            if (!($allowedForSuspended && $status === 'suspended')) {
                return Result::fail('La instancia no está habilitada para la API.', 403);
            }
        }

        $contract = Capsule::table('contracts')
            ->where('instance_id', (int) $instance->id)
            ->whereIn('status', ['active', 'suspended'])
            ->orderByDesc('id')
            ->first();
        if ($contract === null) {
            return Result::fail('No hay contrato vinculado a esta instancia.', 404);
        }

        return ['instance' => $instance, 'contract' => $contract];
    }

    /**
     * @param array<string, string> $headers
     */
    private function extractToken(array $headers): ?string
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        if (!empty($normalized['x-instance-token'])) {
            return trim($normalized['x-instance-token']);
        }

        $auth = $normalized['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @param array{instance:object,contract:object} $auth
     * @param array<string, mixed> $request
     */
    private function getBalance(array $auth, array $request): Result
    {
        $serviceCode = trim((string) ($request['service_code'] ?? ''));
        if ($serviceCode === '') {
            return Result::fail('service_code es obligatorio.', 422);
        }

        $service = Capsule::table('services')->where('code', $serviceCode)->first(['id', 'code', 'name']);
        if ($service === null) {
            return Result::fail('Servicio no encontrado.', 404);
        }

        $snap = BalanceEngine::snapshotBalances((int) $auth['contract']->id, (int) $service->id);

        return Result::ok([
            'service_code' => (string) $service->code,
            'service_name' => (string) $service->name,
            'contract_id' => (int) $auth['contract']->id,
            'included_available' => $snap['included_available'],
            'prepaid_available' => $snap['prepaid_available'],
            'total_available' => $snap['total_available'],
            'period_end' => $snap['period_end'],
        ], 'Saldo disponible');
    }

    /**
     * @param array{instance:object,contract:object} $auth
     * @param array<string, mixed> $request
     */
    private function postConsumption(array $auth, array $request): Result
    {
        $serviceCode = trim((string) ($request['service_code'] ?? ''));
        $units = (int) ($request['units'] ?? 1);
        $externalRef = trim((string) ($request['external_reference'] ?? ''));
        if ($serviceCode === '') {
            return Result::fail('service_code es obligatorio.', 422);
        }
        if ($externalRef === '') {
            return Result::fail('external_reference (UUID CFDI) es obligatorio.', 422);
        }
        if ($units < 1) {
            return Result::fail('units debe ser mayor a 0.', 422);
        }

        $service = Capsule::table('services')->where('code', $serviceCode)->first(['id', 'code']);
        if ($service === null) {
            return Result::fail('Servicio no encontrado.', 404);
        }

        try {
            $result = BalanceEngine::consume([
                'contract_id' => (int) $auth['contract']->id,
                'service_id' => (int) $service->id,
                'units' => $units,
                'external_reference' => $externalRef,
                'instance_id' => (int) $auth['instance']->id,
                'allow_overdraft_tolerance' => true,
                'notes' => 'Consumo reportado por instancia',
            ]);
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        $snap = $result['balances'];

        return Result::ok([
            'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
            'service_code' => (string) $service->code,
            'units' => $units,
            'external_reference' => $externalRef,
            'included_available' => (int) ($snap['included_available'] ?? 0),
            'prepaid_available' => (int) ($snap['prepaid_available'] ?? 0),
            'total_available' => (int) ($snap['total_available'] ?? 0),
            'forgiven_units' => (int) ($result['forgiven_units'] ?? 0),
        ], ($result['idempotent_replay'] ?? false)
            ? 'Consumo idempotente (sin nuevo descuento)'
            : 'Consumo registrado');
    }

    /**
     * @param array{instance:object,contract:object} $auth
     */
    private function getCollectionStatus(array $auth): Result
    {
        $contractId = (int) $auth['contract']->id;
        $row = Capsule::table('collection_statuses')
            ->where('contract_id', $contractId)
            ->orderByDesc('id')
            ->first();

        $overdue = FinancialCalculator::normalizeMoney((string) ($row->overdue_balance_mxn ?? '0'));
        $days = (int) ($row->days_overdue ?? 0);
        $status = (string) ($row->status ?? ((string) $auth['contract']->status));
        $isSuspended = strtolower((string) $auth['contract']->status) === 'suspended'
            || strtolower($status) === 'suspended';

        $warningLevel = 0;
        if ($days >= 90) {
            $warningLevel = 90;
        } elseif ($days >= 60) {
            $warningLevel = 60;
        } elseif ($days >= 30) {
            $warningLevel = 30;
        }

        $message = match (true) {
            $isSuspended => 'Tu servicio está suspendido por saldo vencido. Regulariza tu pago para reactivarlo.',
            $warningLevel >= 90 => 'Tienes más de 90 días de atraso. Evita la suspensión regularizando tu pago.',
            $warningLevel >= 60 => 'Tienes más de 60 días de atraso. Te recomendamos ponerte al corriente.',
            $warningLevel >= 30 => 'Tienes saldo vencido. Puedes pagar desde el portal de clientes.',
            default => 'Tu cuenta está al corriente.',
        };

        return Result::ok([
            'status' => $status !== '' ? $status : (string) $auth['contract']->status,
            'overdue_balance_mxn' => $overdue,
            'days_overdue' => $days,
            'warning_level' => $warningLevel,
            'is_suspended' => $isSuspended,
            'message' => $message,
        ], 'Estado de cobranza');
    }

    /**
     * @param array{instance:object,contract:object} $auth
     */
    private function getPortalLink(array $auth): Result
    {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expires = $now->modify('+5 minutes');

        Capsule::table('portal_access_tokens')->insert([
            'client_id' => (int) $auth['contract']->client_id,
            'contract_id' => (int) $auth['contract']->id,
            'contact_id' => null,
            'token_hash' => $hash,
            'origin' => 'instance',
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'used_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $frontend = rtrim((string) (getenv('FRONTEND_URL') ?: getenv('APP_FRONTEND_URL') ?: 'http://localhost:5173'), '/');
        $url = $frontend . '/portal/acceso?t=' . urlencode($plain);

        return Result::ok([
            'url' => $url,
            'expires_in_seconds' => 300,
            'expires_at' => $expires->format('c'),
        ], 'Liga de portal generada');
    }
}
