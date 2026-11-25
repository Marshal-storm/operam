<?php

declare(strict_types=1);

namespace System\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;

/**
 * Motor de persistencia de bolsas/consumos (RF-4) — usado por BalancesService e InstanceApiService.
 */
final class BalanceEngine
{
    /**
     * @param array{
     *   contract_id:int,
     *   service_id:int,
     *   units:int,
     *   external_reference?:string|null,
     *   notes?:string|null,
     *   instance_id?:int|null,
     *   user_id?:int|null,
     *   allow_overdraft_tolerance?:bool
     * } $input
     * @return array<string, mixed>
     */
    public static function consume(array $input): array
    {
        $contractId = (int) $input['contract_id'];
        $serviceId = (int) $input['service_id'];
        $units = (int) $input['units'];
        $externalRef = self::normalizeExternalRef($input['external_reference'] ?? null);
        $notes = trim((string) ($input['notes'] ?? ''));
        $instanceId = isset($input['instance_id']) ? (int) $input['instance_id'] : null;
        $userId = isset($input['user_id']) ? (int) $input['user_id'] : null;
        $allowTolerance = (bool) ($input['allow_overdraft_tolerance'] ?? false);

        if ($units < 1) {
            throw new RuntimeException('Las unidades deben ser un entero mayor a 0.');
        }

        return Capsule::connection()->transaction(function () use (
            $contractId,
            $serviceId,
            $units,
            $externalRef,
            $notes,
            $instanceId,
            $userId,
            $allowTolerance,
        ): array {
            if ($externalRef !== null) {
                $existing = Capsule::table('consumptions')
                    ->where('external_reference', $externalRef)
                    ->first();
                if ($existing !== null) {
                    return [
                        'idempotent_replay' => true,
                        'consumption_id' => (int) $existing->id,
                        'balances' => self::snapshotBalances($contractId, $serviceId),
                    ];
                }
            }

            $included = self::lockBucket($contractId, $serviceId, 'included');
            $prepaid = self::lockBucket($contractId, $serviceId, 'prepaid');

            $plan = BalanceRules::planConsumption(
                $units,
                (int) ($included->granted_units ?? 0),
                (int) ($included->consumed_units ?? 0),
                (int) ($prepaid->granted_units ?? 0),
                (int) ($prepaid->consumed_units ?? 0),
                $allowTolerance,
            );
            if (!$plan['ok']) {
                throw new RuntimeException((string) $plan['message']);
            }

            $nowUtc = gmdate('Y-m-d H:i:s');
            $consumptionIds = [];
            $noteParts = [];
            if ($notes !== '') {
                $noteParts[] = $notes;
            }
            if ($plan['forgiven_units'] > 0) {
                $noteParts[] = 'Tolerancia de sobregiro: ' . $plan['forgiven_units'] . ' unidad(es)';
            }
            $finalNotes = $noteParts !== [] ? mb_substr(implode(' · ', $noteParts), 0, 2000) : null;

            if ($plan['from_included'] > 0) {
                Capsule::table('balances')->where('id', (int) $included->id)->update([
                    'consumed_units' => $plan['included_consumed_after'],
                    'updated_at' => $nowUtc,
                ]);
                $consumptionIds[] = self::insertConsumption([
                    'balance_id' => (int) $included->id,
                    'contract_id' => $contractId,
                    'service_id' => $serviceId,
                    'units' => $plan['from_included'],
                    'external_reference' => $externalRef,
                    'instance_id' => $instanceId,
                    'user_id' => $userId,
                    'notes' => $finalNotes,
                    'consumed_at' => $nowUtc,
                    'created_at' => $nowUtc,
                ]);
                // Solo la primera fila lleva el external_reference único.
                $externalRef = null;
            }

            if ($plan['from_prepaid'] > 0) {
                Capsule::table('balances')->where('id', (int) $prepaid->id)->update([
                    'consumed_units' => $plan['prepaid_consumed_after'],
                    'updated_at' => $nowUtc,
                ]);
                $consumptionIds[] = self::insertConsumption([
                    'balance_id' => (int) $prepaid->id,
                    'contract_id' => $contractId,
                    'service_id' => $serviceId,
                    'units' => $plan['from_prepaid'],
                    'external_reference' => $externalRef,
                    'instance_id' => $instanceId,
                    'user_id' => $userId,
                    'notes' => $finalNotes,
                    'consumed_at' => $nowUtc,
                    'created_at' => $nowUtc,
                ]);
            }

            // Sobregiro tolerado sin unidades reales: aún registramos evidencia si hay reference.
            if (
                $plan['from_included'] === 0
                && $plan['from_prepaid'] === 0
                && $plan['forgiven_units'] > 0
            ) {
                $target = (int) ($prepaid->id ?? $included->id);
                $consumptionIds[] = self::insertConsumption([
                    'balance_id' => $target,
                    'contract_id' => $contractId,
                    'service_id' => $serviceId,
                    'units' => $plan['forgiven_units'],
                    'external_reference' => $externalRef,
                    'instance_id' => $instanceId,
                    'user_id' => $userId,
                    'notes' => $finalNotes,
                    'consumed_at' => $nowUtc,
                    'created_at' => $nowUtc,
                ]);
            }

            return [
                'idempotent_replay' => false,
                'consumption_ids' => $consumptionIds,
                'from_included' => $plan['from_included'],
                'from_prepaid' => $plan['from_prepaid'],
                'forgiven_units' => $plan['forgiven_units'],
                'balances' => self::snapshotBalances($contractId, $serviceId),
            ];
        });
    }

    /**
     * Acredita unidades en bolsa included o prepaid.
     *
     * @param array{
     *   contract_id:int,
     *   service_id:int,
     *   bucket:string,
     *   units:int,
     *   movement_type?:string,
     *   period_start?:string|null,
     *   period_end?:string|null,
     *   request_id?:int|null,
     *   promotion_id?:int|null,
     *   notes?:string|null,
     *   created_by?:int|null
     * } $input
     * @return array<string, mixed>
     */
    public static function grant(array $input): array
    {
        $contractId = (int) $input['contract_id'];
        $serviceId = (int) $input['service_id'];
        $bucket = (string) $input['bucket'];
        $units = (int) $input['units'];
        $movementType = (string) ($input['movement_type'] ?? 'grant');
        $notes = trim((string) ($input['notes'] ?? ''));
        $createdBy = isset($input['created_by']) ? (int) $input['created_by'] : null;
        $requestId = isset($input['request_id']) ? (int) $input['request_id'] : null;
        $promotionId = isset($input['promotion_id']) ? (int) $input['promotion_id'] : null;

        if (!in_array($bucket, ['included', 'prepaid'], true)) {
            throw new RuntimeException('La bolsa debe ser included o prepaid.');
        }
        if (!in_array($movementType, ['grant', 'purchase', 'promotion', 'reset', 'adjustment'], true)) {
            throw new RuntimeException('Tipo de movimiento de bolsa inválido.');
        }
        if ($units === 0) {
            throw new RuntimeException('Las unidades no pueden ser 0.');
        }

        return Capsule::connection()->transaction(function () use (
            $contractId,
            $serviceId,
            $bucket,
            $units,
            $movementType,
            $notes,
            $createdBy,
            $requestId,
            $promotionId,
            $input,
        ): array {
            $row = self::lockOrCreateBucket(
                $contractId,
                $serviceId,
                $bucket,
                $input['period_start'] ?? null,
                $input['period_end'] ?? null,
            );

            $nowUtc = gmdate('Y-m-d H:i:s');
            $newGranted = max(0, (int) $row->granted_units + $units);
            // Evita que consumed > granted tras ajuste negativo extremo.
            $newConsumed = min((int) $row->consumed_units, $newGranted);

            $update = [
                'granted_units' => $newGranted,
                'consumed_units' => $newConsumed,
                'updated_at' => $nowUtc,
            ];
            if ($bucket === 'included') {
                if (!empty($input['period_start'])) {
                    $update['period_start'] = (string) $input['period_start'];
                }
                if (!empty($input['period_end'])) {
                    $update['period_end'] = (string) $input['period_end'];
                }
            }

            Capsule::table('balances')->where('id', (int) $row->id)->update($update);

            $movementId = (int) Capsule::table('balance_movements')->insertGetId([
                'balance_id' => (int) $row->id,
                'movement_type' => $movementType,
                'units' => $units,
                'request_id' => $requestId > 0 ? $requestId : null,
                'promotion_id' => $promotionId > 0 ? $promotionId : null,
                'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
                'created_by' => $createdBy > 0 ? $createdBy : null,
                'created_at' => $nowUtc,
            ]);

            return [
                'balance_id' => (int) $row->id,
                'movement_id' => $movementId,
                'balances' => self::snapshotBalances($contractId, $serviceId),
            ];
        });
    }

    /**
     * Acredita líneas de solicitud tipo consumption en bolsa prepaid.
     * Compra (`purchase`) + unidades extra de promo (`promotion`) en movimientos separados.
     * No modifica precio ni MRR.
     */
    public static function creditPrepaidFromApprovedRequest(object $request, int $userId): void
    {
        if ((string) $request->request_type !== 'consumption') {
            return;
        }

        $contractId = (int) $request->contract_id;
        $clientId = (int) ($request->client_id ?? 0);
        $segmentId = (int) ($request->segment_id ?? 0);
        if ($clientId < 1 || $segmentId < 1) {
            $contract = Capsule::table('contracts')->where('id', $contractId)->first(['client_id', 'segment_id']);
            if ($contract !== null) {
                $clientId = (int) $contract->client_id;
                $segmentId = (int) $contract->segment_id;
            }
        }

        $items = Capsule::table('service_request_items')
            ->where('service_request_id', (int) $request->id)
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $serviceType = strtolower((string) ($item->service_type ?? ''));
            if (!in_array($serviceType, ['consumption', 'one_time', 'single'], true)) {
                // Solo líneas de consumo/cargos de bolsa.
                if ($serviceType === 'recurring') {
                    continue;
                }
            }
            $qty = (int) ($item->quantity ?? 0);
            if ($qty < 1) {
                continue;
            }
            $serviceId = (int) $item->service_id;
            $folio = (string) ($request->folio ?? $request->id);

            // 1) Unidades compradas — movement_type = purchase (precio/MRR intactos).
            self::grant([
                'contract_id' => $contractId,
                'service_id' => $serviceId,
                'bucket' => 'prepaid',
                'units' => $qty,
                'movement_type' => 'purchase',
                'request_id' => (int) $request->id,
                'notes' => 'Compra desde solicitud ' . $folio,
                'created_by' => $userId,
            ]);

            // 2) Unidades regaladas RF-2.8 — movement_type = promotion (separado).
            if ($clientId > 0 && $segmentId > 0) {
                $promo = PromotionCalculator::evaluateExtraUnitsPromo(
                    $clientId,
                    $segmentId,
                    $serviceId,
                    $qty,
                );
                if ($promo['applies'] && $promo['extra_units'] > 0) {
                    self::grant([
                        'contract_id' => $contractId,
                        'service_id' => $serviceId,
                        'bucket' => 'prepaid',
                        'units' => (int) $promo['extra_units'],
                        'movement_type' => 'promotion',
                        'request_id' => (int) $request->id,
                        'promotion_id' => (int) $promo['promotion_id'],
                        'notes' => sprintf(
                            'Unidades extra RF-2.8 (+%s%%) desde solicitud %s',
                            (string) ($promo['extra_units_pct'] ?? '0'),
                            $folio,
                        ),
                        'created_by' => $userId,
                    ]);
                }
            }
        }
    }

    /** @return object{id:int|string,granted_units:int|string,consumed_units:int|string,...} */
    private static function lockBucket(int $contractId, int $serviceId, string $bucket): object
    {
        $row = Capsule::table('balances')
            ->where('contract_id', $contractId)
            ->where('service_id', $serviceId)
            ->where('bucket', $bucket)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return self::lockOrCreateBucket($contractId, $serviceId, $bucket, null, null);
        }

        return $row;
    }

    private static function lockOrCreateBucket(
        int $contractId,
        int $serviceId,
        string $bucket,
        mixed $periodStart,
        mixed $periodEnd,
    ): object {
        $existing = Capsule::table('balances')
            ->where('contract_id', $contractId)
            ->where('service_id', $serviceId)
            ->where('bucket', $bucket)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $id = (int) Capsule::table('balances')->insertGetId([
            'contract_id' => $contractId,
            'service_id' => $serviceId,
            'bucket' => $bucket,
            'period_start' => $bucket === 'included' && is_string($periodStart) ? $periodStart : null,
            'period_end' => $bucket === 'included' && is_string($periodEnd) ? $periodEnd : null,
            'granted_units' => 0,
            'consumed_units' => 0,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $created = Capsule::table('balances')->where('id', $id)->lockForUpdate()->first();
        if ($created === null) {
            throw new RuntimeException('No se pudo crear la bolsa de saldo.');
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function insertConsumption(array $row): int
    {
        return (int) Capsule::table('consumptions')->insertGetId($row);
    }

    /**
     * @return array{included:array<string,mixed>|null,prepaid:array<string,mixed>|null,total_available:int}
     */
    public static function snapshotBalances(int $contractId, int $serviceId): array
    {
        $map = ['included' => null, 'prepaid' => null];
        $rows = Capsule::table('balances')
            ->where('contract_id', $contractId)
            ->where('service_id', $serviceId)
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            $bucket = (string) $row->bucket;
            if (!isset($map[$bucket]) || $map[$bucket] !== null) {
                continue;
            }
            $granted = (int) $row->granted_units;
            $consumed = (int) $row->consumed_units;
            $map[$bucket] = [
                'id' => (int) $row->id,
                'bucket' => $bucket,
                'granted_units' => $granted,
                'consumed_units' => $consumed,
                'available_units' => BalanceRules::availableUnits($granted, $consumed),
                'period_start' => $row->period_start,
                'period_end' => $row->period_end,
            ];
        }

        $total = ((int) ($map['included']['available_units'] ?? 0))
            + ((int) ($map['prepaid']['available_units'] ?? 0));

        return [
            'included' => $map['included'],
            'prepaid' => $map['prepaid'],
            'total_available' => $total,
            'included_available' => (int) ($map['included']['available_units'] ?? 0),
            'prepaid_available' => (int) ($map['prepaid']['available_units'] ?? 0),
            'period_end' => $map['included']['period_end'] ?? null,
        ];
    }

    private static function normalizeExternalRef(mixed $raw): ?string
    {
        $ref = trim((string) ($raw ?? ''));
        if ($ref === '') {
            return null;
        }

        return mb_substr($ref, 0, 120);
    }
}
