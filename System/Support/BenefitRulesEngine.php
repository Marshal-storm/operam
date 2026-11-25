<?php

declare(strict_types=1);

namespace System\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Motor de reglas de beneficio (RF-2.6): otorga/ajusta bolsas `included`
 * según unidades equivalentes del contrato × units_per_unit.
 */
final class BenefitRulesEngine
{
    /**
     * Calcula unidades equivalentes de una línea (paquete × factor o quantity).
     */
    public static function equivalentUnits(int $quantity, int $packageFactor): int
    {
        $qty = max(0, $quantity);
        if ($packageFactor > 1) {
            return $qty * $packageFactor;
        }

        return $qty;
    }

    /**
     * Unidades de beneficio = equivalentes × units_per_unit.
     */
    public static function benefitUnits(int $equivalentUnits, int $unitsPerUnit): int
    {
        return max(0, $equivalentUnits) * max(0, $unitsPerUnit);
    }

    /**
     * Periodo de la bolsa included según reset_period en America/Mexico_City.
     *
     * @return array{period_start:string,period_end:string}
     */
    public static function resolvePeriod(
        string $resetPeriod,
        ?string $anniversaryDate = null,
        ?DateTimeImmutable $nowMx = null,
    ): array {
        $tz = new DateTimeZone('America/Mexico_City');
        $now = $nowMx ?? new DateTimeImmutable('now', $tz);

        if ($resetPeriod === 'anniversary' && $anniversaryDate !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $anniversaryDate)
        ) {
            $anni = DateTimeImmutable::createFromFormat('Y-m-d', $anniversaryDate, $tz);
            if ($anni instanceof DateTimeImmutable) {
                $day = (int) $anni->format('d');
                $start = $now->setDate((int) $now->format('Y'), (int) $now->format('m'), min($day, (int) $now->format('t')));
                if ($start > $now) {
                    $start = $start->modify('-1 month');
                }
                $end = $start->modify('+1 month')->modify('-1 day');

                return [
                    'period_start' => $start->format('Y-m-d'),
                    'period_end' => $end->format('Y-m-d'),
                ];
            }
        }

        // monthly (default)
        $start = $now->modify('first day of this month');
        $end = $now->modify('last day of this month');

        return [
            'period_start' => $start->format('Y-m-d'),
            'period_end' => $end->format('Y-m-d'),
        ];
    }

    /**
     * Texto humano de vista previa (UI alta de contrato / catálogo).
     *
     * @param list<array{
     *   benefit_service_name:string,
     *   units:int,
     *   units_per_unit:int,
     *   equivalent_units:int,
     *   is_cumulative:bool,
     *   reset_period:string
     * }> $grants
     */
    public static function previewMessage(array $grants): string
    {
        if ($grants === []) {
            return '';
        }
        $parts = [];
        foreach ($grants as $g) {
            $units = number_format((int) $g['units'], 0, '.', ',');
            $upu = (int) $g['units_per_unit'];
            $eq = (int) $g['equivalent_units'];
            $name = (string) $g['benefit_service_name'];
            $period = ($g['reset_period'] ?? 'monthly') === 'anniversary'
                ? 'por aniversario'
                : 'al mes';
            $cumul = !empty($g['is_cumulative']) ? 'acumulables' : 'no acumulables';
            $parts[] = "{$units} {$name} {$period} ({$upu} por unidad × {$eq} unidades), {$cumul}";
        }

        return 'Incluye por beneficio: ' . implode('; ', $parts) . '.';
    }

    /**
     * Sincroniza bolsas `included` del contrato con las reglas activas.
     * Invocar tras alta de contrato y expansión/contracción.
     *
     * @return list<array<string,mixed>>
     */
    public static function syncContractBenefitBalances(int $contractId, ?int $userId = null): array
    {
        $contract = Capsule::table('contracts')->where('id', $contractId)->first([
            'id',
            'anniversary_date',
            'status',
        ]);
        if ($contract === null) {
            return [];
        }

        $lines = Capsule::table('contract_lines as cl')
            ->join('services as s', 's.id', '=', 'cl.service_id')
            ->where('cl.contract_id', $contractId)
            ->where('cl.active', 1)
            ->get([
                'cl.service_id',
                'cl.quantity',
                's.package_factor',
            ]);

        // Suma de unidades equivalentes por service_id origen.
        $equivalentsBySource = [];
        foreach ($lines as $line) {
            $sid = (int) $line->service_id;
            $eq = self::equivalentUnits(
                (int) $line->quantity,
                (int) ($line->package_factor ?? 0),
            );
            $equivalentsBySource[$sid] = ($equivalentsBySource[$sid] ?? 0) + $eq;
        }

        $rules = Capsule::table('benefit_rules as br')
            ->leftJoin('services as bs', 'bs.id', '=', 'br.benefit_service_id')
            ->where('br.active', 1)
            ->orderBy('br.id')
            ->get([
                'br.id',
                'br.source_service_id',
                'br.benefit_service_id',
                'br.units_per_unit',
                'br.reset_period',
                'br.is_cumulative',
                'bs.name as benefit_service_name',
            ]);

        $results = [];
        $anniversary = $contract->anniversary_date !== null
            ? (string) $contract->anniversary_date
            : null;

        foreach ($rules as $rule) {
            $sourceId = (int) $rule->source_service_id;
            $eq = (int) ($equivalentsBySource[$sourceId] ?? 0);
            $target = self::benefitUnits($eq, (int) $rule->units_per_unit);
            $benefitServiceId = (int) $rule->benefit_service_id;
            $period = self::resolvePeriod((string) $rule->reset_period, $anniversary);

            $bag = Capsule::table('balances')
                ->where('contract_id', $contractId)
                ->where('service_id', $benefitServiceId)
                ->where('bucket', 'included')
                ->orderByDesc('id')
                ->first();

            $currentGranted = $bag !== null ? (int) $bag->granted_units : 0;

            if ($target === 0 && $currentGranted === 0) {
                continue;
            }

            $delta = $target - $currentGranted;
            if ($delta === 0) {
                // Solo refresca periodo si ya está sincronizado.
                if ($bag !== null) {
                    Capsule::table('balances')->where('id', (int) $bag->id)->update([
                        'period_start' => $period['period_start'],
                        'period_end' => $period['period_end'],
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
                $results[] = [
                    'benefit_rule_id' => (int) $rule->id,
                    'benefit_service_id' => $benefitServiceId,
                    'equivalent_units' => $eq,
                    'target_units' => $target,
                    'delta' => 0,
                    'movement_type' => null,
                ];
                continue;
            }

            $movementType = $currentGranted === 0 && $delta > 0 ? 'grant' : 'adjustment';
            $grant = BalanceEngine::grant([
                'contract_id' => $contractId,
                'service_id' => $benefitServiceId,
                'bucket' => 'included',
                'units' => $delta,
                'movement_type' => $movementType,
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'notes' => sprintf(
                    'Beneficio RF-2.6: %d u. equivalentes × %d = %d (regla #%d)',
                    $eq,
                    (int) $rule->units_per_unit,
                    $target,
                    (int) $rule->id,
                ),
                'created_by' => $userId,
            ]);

            $results[] = [
                'benefit_rule_id' => (int) $rule->id,
                'benefit_service_id' => $benefitServiceId,
                'benefit_service_name' => (string) ($rule->benefit_service_name ?? ''),
                'equivalent_units' => $eq,
                'units_per_unit' => (int) $rule->units_per_unit,
                'target_units' => $target,
                'delta' => $delta,
                'movement_type' => $movementType,
                'is_cumulative' => (bool) $rule->is_cumulative,
                'reset_period' => (string) $rule->reset_period,
                'grant' => $grant,
            ];
        }

        return $results;
    }

    /**
     * Vista previa sin persistir (alta de contrato / UI).
     *
     * @param list<array{service_id:int,quantity:int,package_factor?:int}> $lines
     * @return list<array<string,mixed>>
     */
    public static function previewForLines(array $lines): array
    {
        $equivalentsBySource = [];
        foreach ($lines as $line) {
            $sid = (int) $line['service_id'];
            $eq = self::equivalentUnits(
                (int) ($line['quantity'] ?? 0),
                (int) ($line['package_factor'] ?? 0),
            );
            $equivalentsBySource[$sid] = ($equivalentsBySource[$sid] ?? 0) + $eq;
        }

        $rules = Capsule::table('benefit_rules as br')
            ->leftJoin('services as bs', 'bs.id', '=', 'br.benefit_service_id')
            ->where('br.active', 1)
            ->orderBy('br.id')
            ->get([
                'br.source_service_id',
                'br.benefit_service_id',
                'br.units_per_unit',
                'br.reset_period',
                'br.is_cumulative',
                'bs.name as benefit_service_name',
            ]);

        $grants = [];
        foreach ($rules as $rule) {
            $eq = (int) ($equivalentsBySource[(int) $rule->source_service_id] ?? 0);
            $units = self::benefitUnits($eq, (int) $rule->units_per_unit);
            if ($units <= 0) {
                continue;
            }
            $grants[] = [
                'benefit_service_id' => (int) $rule->benefit_service_id,
                'benefit_service_name' => (string) ($rule->benefit_service_name ?? 'beneficio'),
                'units' => $units,
                'units_per_unit' => (int) $rule->units_per_unit,
                'equivalent_units' => $eq,
                'is_cumulative' => (bool) $rule->is_cumulative,
                'reset_period' => (string) $rule->reset_period,
            ];
        }

        return $grants;
    }
}
