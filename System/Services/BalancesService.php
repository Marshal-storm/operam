<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use System\Core\Context;
use System\Core\Result;
use System\Support\AuditLogger;
use System\Support\BalanceEngine;
use System\Support\BalanceRules;
use System\Support\BenefitRulesEngine;
use Throwable;

/**
 * Saldos y consumos internos — `/api/balances/*` (RF-4).
 * RBAC vía módulo `contracts` (detalle de contrato).
 */
final class BalancesService extends CustomService
{
    private const MODULE_KEY = 'contracts';

    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    /** @var array<string, mixed> */
    private array $authContext = [];

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery(
            $this->authContext,
            $context->getRequest(),
        );
        $method = $context->getMethod();
        $uri = strtolower(trim($context->getUri(), '/'));
        $parts = $uri === '' ? [] : explode('/', $uri);
        // balances | balances/contract/{id} | balances/consume | balances/grant
        $action = isset($parts[1]) ? strtolower((string) $parts[1]) : '';
        $resourceId = isset($parts[2]) && ctype_digit((string) $parts[2])
            ? (int) $parts[2]
            : null;

        return match ($action) {
            'contract' => $method === 'GET' && $resourceId !== null
                ? $this->requireView(fn (): Result => Result::ok(
                    $this->contractBalances($resourceId, $request),
                    'Saldos del contrato',
                ))
                : Result::fail('Método o contrato inválido.', 405),
            'consume' => $method === 'POST'
                ? $this->consumeManual($request)
                : Result::fail('Método no soportado.', 405),
            'grant' => $method === 'POST'
                ? $this->grantManual($request)
                : Result::fail('Método no soportado.', 405),
            default => Result::fail('Recurso de saldos no encontrado.', 404),
        };
    }

    /**
     * @param callable(): Result $callback
     */
    private function requireView(callable $callback): Result
    {
        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
            return Result::fail('Sin permiso para consultar saldos del contrato.', 403);
        }

        return $callback();
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function contractBalances(int $contractId, array $query): array
    {
        $contract = Capsule::table('contracts as c')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->where('c.id', $contractId)
            ->first(['c.id', 'c.folio', 'c.segment_id', 'c.client_id', 'cl.name as client_name']);
        if ($contract === null) {
            throw new RuntimeException('Contrato no encontrado.');
        }
        $this->assertSegmentScope((int) $contract->segment_id);

        $bags = Capsule::table('balances as b')
            ->leftJoin('services as s', 's.id', '=', 'b.service_id')
            ->where('b.contract_id', $contractId)
            ->orderBy('s.name')
            ->orderBy('b.bucket')
            ->orderByDesc('b.id')
            ->get([
                'b.id',
                'b.service_id',
                's.code as service_code',
                's.name as service_name',
                'b.bucket',
                'b.period_start',
                'b.period_end',
                'b.granted_units',
                'b.consumed_units',
                'b.updated_at',
            ]);

        $byService = [];
        foreach ($bags as $row) {
            $sid = (int) $row->service_id;
            if (!isset($byService[$sid])) {
                $byService[$sid] = [
                    'service_id' => $sid,
                    'service_code' => (string) ($row->service_code ?? ''),
                    'service_name' => (string) ($row->service_name ?? ''),
                    'buckets' => [],
                ];
            }
            $granted = (int) $row->granted_units;
            $consumed = (int) $row->consumed_units;
            $byService[$sid]['buckets'][] = [
                'id' => (int) $row->id,
                'bucket' => (string) $row->bucket,
                'period_start' => $row->period_start,
                'period_end' => $row->period_end,
                'granted_units' => $granted,
                'consumed_units' => $consumed,
                'available_units' => BalanceRules::availableUnits($granted, $consumed),
            ];
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $consumptionsBuilder = Capsule::table('consumptions as cns')
            ->leftJoin('services as s', 's.id', '=', 'cns.service_id')
            ->leftJoin('users as u', 'u.id', '=', 'cns.user_id')
            ->where('cns.contract_id', $contractId);
        $consumptionsTotal = (clone $consumptionsBuilder)->count();
        $consumptions = $consumptionsBuilder
            ->orderByDesc('cns.consumed_at')
            ->orderByDesc('cns.id')
            ->forPage($page, $perPage)
            ->get([
                'cns.id',
                'cns.balance_id',
                'cns.service_id',
                's.name as service_name',
                'cns.units',
                'cns.external_reference',
                'cns.instance_id',
                'cns.user_id',
                'u.name as user_name',
                'cns.notes',
                'cns.consumed_at',
                'cns.created_at',
            ]);

        $movements = Capsule::table('balance_movements as bm')
            ->join('balances as b', 'b.id', '=', 'bm.balance_id')
            ->leftJoin('services as s', 's.id', '=', 'b.service_id')
            ->leftJoin('users as u', 'u.id', '=', 'bm.created_by')
            ->where('b.contract_id', $contractId)
            ->orderByDesc('bm.created_at')
            ->orderByDesc('bm.id')
            ->limit(50)
            ->get([
                'bm.id',
                'bm.balance_id',
                'b.service_id',
                's.name as service_name',
                'b.bucket',
                'bm.movement_type',
                'bm.units',
                'bm.request_id',
                'bm.promotion_id',
                'bm.notes',
                'bm.created_by',
                'u.name as created_by_name',
                'bm.created_at',
            ]);

        return [
            'contract_id' => $contractId,
            'folio' => (string) $contract->folio,
            'client_name' => (string) ($contract->client_name ?? ''),
            'services' => array_values($byService),
            'consumptions' => [
                'items' => array_map(static fn (object $r): array => [
                    'id' => (int) $r->id,
                    'balance_id' => (int) $r->balance_id,
                    'service_id' => (int) $r->service_id,
                    'service_name' => (string) ($r->service_name ?? ''),
                    'units' => (int) $r->units,
                    'external_reference' => $r->external_reference,
                    'instance_id' => $r->instance_id !== null ? (int) $r->instance_id : null,
                    'user_id' => $r->user_id !== null ? (int) $r->user_id : null,
                    'user_name' => $r->user_name !== null ? (string) $r->user_name : null,
                    'notes' => $r->notes,
                    'consumed_at' => $r->consumed_at,
                    'created_at' => $r->created_at,
                ], $consumptions->all()),
                'total' => $consumptionsTotal,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) max(1, (int) ceil($consumptionsTotal / max(1, $perPage))),
            ],
            'balance_movements' => array_map(static fn (object $r): array => [
                'id' => (int) $r->id,
                'balance_id' => (int) $r->balance_id,
                'service_id' => (int) $r->service_id,
                'service_name' => (string) ($r->service_name ?? ''),
                'bucket' => (string) $r->bucket,
                'movement_type' => (string) $r->movement_type,
                'units' => (int) $r->units,
                'request_id' => $r->request_id !== null ? (int) $r->request_id : null,
                'promotion_id' => $r->promotion_id !== null ? (int) $r->promotion_id : null,
                'notes' => $r->notes,
                'created_by' => $r->created_by !== null ? (int) $r->created_by : null,
                'created_by_name' => $r->created_by_name !== null ? (string) $r->created_by_name : null,
                'created_at' => $r->created_at,
            ], $movements->all()),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function consumeManual(array $payload): Result
    {
        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
            return Result::fail('Sin permiso para registrar consumos.', 403);
        }

        $contractId = (int) ($payload['contract_id'] ?? 0);
        $serviceId = (int) ($payload['service_id'] ?? 0);
        $units = (int) ($payload['units'] ?? 0);
        if ($contractId < 1 || $serviceId < 1 || $units < 1) {
            return Result::fail('contract_id, service_id y units (> 0) son obligatorios.', 422);
        }

        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        if ($contract === null) {
            return Result::fail('Contrato no encontrado.', 404);
        }
        $this->assertSegmentScope((int) $contract->segment_id);

        try {
            $result = BalanceEngine::consume([
                'contract_id' => $contractId,
                'service_id' => $serviceId,
                'units' => $units,
                'external_reference' => $payload['external_reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'user_id' => (int) ($this->authContext['user_id'] ?? 0) ?: null,
                'allow_overdraft_tolerance' => false,
            ]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $code = str_contains(mb_strtolower($msg), 'saldo insuficiente') ? 422 : 422;

            return Result::fail($msg, $code);
        }

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'consumptions',
            (string) ($result['consumption_ids'][0] ?? $contractId),
            'create',
            'Se registró consumo manual de ' . $units . ' unidad(es)',
            null,
            [
                'contract_id' => $contractId,
                'service_id' => $serviceId,
                'units' => $units,
                'idempotent_replay' => $result['idempotent_replay'] ?? false,
            ],
        );

        return Result::ok($result, ($result['idempotent_replay'] ?? false)
            ? 'Consumo ya registrado (idempotente)'
            : 'Consumo registrado');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function grantManual(array $payload): Result
    {
        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
            return Result::fail('Sin permiso para acreditar bolsas.', 403);
        }

        $contractId = (int) ($payload['contract_id'] ?? 0);
        $serviceId = (int) ($payload['service_id'] ?? 0);
        $bucket = strtolower(trim((string) ($payload['bucket'] ?? 'prepaid')));
        $units = (int) ($payload['units'] ?? 0);
        $movementType = strtolower(trim((string) ($payload['movement_type'] ?? 'grant')));
        if ($movementType === 'adjustment' && $units === 0) {
            return Result::fail('El ajuste no puede ser 0.', 422);
        }
        if ($movementType !== 'adjustment' && $units < 1) {
            return Result::fail('Las unidades deben ser mayores a 0.', 422);
        }
        if ($contractId < 1 || $serviceId < 1) {
            return Result::fail('contract_id y service_id son obligatorios.', 422);
        }

        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        if ($contract === null) {
            return Result::fail('Contrato no encontrado.', 404);
        }
        $this->assertSegmentScope((int) $contract->segment_id);

        try {
            $result = BalanceEngine::grant([
                'contract_id' => $contractId,
                'service_id' => $serviceId,
                'bucket' => $bucket,
                'units' => $units,
                'movement_type' => in_array($movementType, ['grant', 'adjustment'], true)
                    ? $movementType
                    : 'grant',
                'period_start' => $payload['period_start'] ?? null,
                'period_end' => $payload['period_end'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => (int) ($this->authContext['user_id'] ?? 0) ?: null,
            ]);
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'balances',
            (string) ($result['balance_id'] ?? $contractId),
            'create',
            'Se acreditó bolsa ' . $bucket . ' con ' . $units . ' unidad(es)',
            null,
            [
                'contract_id' => $contractId,
                'service_id' => $serviceId,
                'bucket' => $bucket,
                'units' => $units,
                'movement_type' => $movementType,
            ],
        );

        return Result::ok($result, 'Bolsa actualizada');
    }

    private function assertSegmentScope(int $segmentId): void
    {
        $locked = $this->authContext['segment_id'] ?? null;
        $lockedName = (string) ($this->authContext['segment_name'] ?? 'Todos');
        if ($locked === null || $lockedName === '' || $lockedName === 'Todos') {
            return;
        }
        if ((int) $locked !== $segmentId) {
            throw new RuntimeException('El contrato está fuera de tu alcance de segmento.');
        }
    }

    /**
     * Helper RF-2.6 — sincroniza bolsas included con reglas de beneficio.
     *
     * @return list<array<string, mixed>>
     */
    public function syncContractBenefitBalances(int $contractId, ?int $userId = null): array
    {
        return BenefitRulesEngine::syncContractBenefitBalances($contractId, $userId);
    }
}
