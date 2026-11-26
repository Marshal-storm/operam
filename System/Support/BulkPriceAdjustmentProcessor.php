<?php

declare(strict_types=1);

namespace System\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use System\Services\MovementsService;

/**
 * Ajuste Masivo de Precios (RF-3.6 / UI-9) sobre líneas recurrentes de contratos activos.
 *
 * `preview()` solo lee; `apply()` debe invocarse dentro de una transacción: actualiza líneas
 * (cupón quemado), `contracts.mrr_mxn`, inserta un `price_adjustment` inmutable por contrato
 * (sincroniza `monthly_mrr`) y registra el lote en `bulk_price_adjustments`.
 */
final class BulkPriceAdjustmentProcessor
{
    private const SCALE = 2;

    private const BUSINESS_TIMEZONE = 'America/Mexico_City';

    /**
     * Normaliza filtros opcionales. `segment_id` ya llega forzado por RbacAuthHelper si el
     * usuario tiene segmento fijo.
     *
     * @param array<string, mixed> $input
     * @return array{segment_id: int|null, issuer_id: int|null, service_id: int|null, anniversary_from: string|null, anniversary_to: string|null}
     */
    public static function normalizeFilters(array $input): array
    {
        $filters = [
            'segment_id' => self::positiveIntOrNull($input['segment_id'] ?? null),
            'issuer_id' => self::positiveIntOrNull($input['issuer_id'] ?? null),
            'service_id' => self::positiveIntOrNull($input['service_id'] ?? null),
            'anniversary_from' => self::dateOrNull($input['anniversary_from'] ?? null, 'anniversary_from'),
            'anniversary_to' => self::dateOrNull($input['anniversary_to'] ?? null, 'anniversary_to'),
        ];
        if ($filters['anniversary_from'] !== null && $filters['anniversary_to'] !== null
            && $filters['anniversary_from'] > $filters['anniversary_to']) {
            throw new InvalidArgumentException('El rango de aniversario es inválido (desde > hasta).');
        }

        return $filters;
    }

    /**
     * Vista previa UI-9 sin tocar la base de datos.
     *
     * @param array{segment_id: int|null, issuer_id: int|null, service_id: int|null, anniversary_from: string|null, anniversary_to: string|null} $filters
     * @return array{summary: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function preview(array $filters, string $percentage): array
    {
        $plans = $this->buildPlans($filters, $percentage, false);

        return [
            'summary' => self::summarize($plans, $percentage),
            'items' => array_map(static fn (array $plan): array => self::publicItem($plan), $plans),
        ];
    }

    /**
     * Aplica el ajuste (requiere transacción abierta).
     *
     * @param array{segment_id: int|null, issuer_id: int|null, service_id: int|null, anniversary_from: string|null, anniversary_to: string|null} $filters
     * @return array{batch_id: int, batch_code: string, summary: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function apply(array $filters, string $percentage, string $reason, int $userId, ?string $todayOverride = null): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Se requiere un usuario válido para ejecutar el ajuste masivo.');
        }
        $today = $todayOverride ?? (new DateTimeImmutable('now', new DateTimeZone(self::BUSINESS_TIMEZONE)))->format('Y-m-d');
        $plans = $this->buildPlans($filters, $percentage, true);
        if ($plans === []) {
            throw new InvalidArgumentException('Ningún contrato activo con líneas recurrentes cumple los filtros.');
        }

        $summary = self::summarize($plans, $percentage);
        $year = (int) substr($today, 0, 4);
        $lastCode = Capsule::table('bulk_price_adjustments')
            ->where('batch_code', 'like', sprintf('AJ-%04d-%%', $year))
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('batch_code');
        $batchCode = BulkPriceAdjustmentRules::formatBatchCode(
            $year,
            BulkPriceAdjustmentRules::nextSequence(is_string($lastCode) ? $lastCode : null, $year),
        );
        $nowUtc = gmdate('Y-m-d H:i:s');

        $batchId = (int) Capsule::table('bulk_price_adjustments')->insertGetId([
            'batch_code' => $batchCode,
            'percentage' => $percentage,
            'filters_json' => json_encode($filters, JSON_UNESCAPED_UNICODE),
            'affected_contracts_count' => $summary['affected_contracts_count'],
            'mrr_delta_total_mxn' => $summary['total_mrr_delta_mxn'],
            'reason' => mb_substr($reason, 0, 255),
            'executed_by_user_id' => $userId,
            'created_at' => $nowUtc,
        ]);

        $movements = new MovementsService();
        foreach ($plans as $plan) {
            foreach ($plan['lines'] as $line) {
                Capsule::table('contract_lines')->where('id', $line['id'])->update([
                    'unit_price' => $line['new_unit_price'],
                    'discount_amount' => '0.00',
                    'subtotal' => $line['new_subtotal'],
                    'mrr_mxn' => $line['new_mrr_mxn'],
                    'updated_at' => $nowUtc,
                ]);
            }
            Capsule::table('contracts')->where('id', $plan['contract_id'])->update([
                'mrr_mxn' => $plan['new_mrr_mxn'],
                'updated_at' => $nowUtc,
            ]);

            $singleLine = count($plan['lines']) === 1 ? $plan['lines'][0] : null;
            $serviceIds = array_values(array_unique(array_map(static fn (array $l): int => $l['service_id'], $plan['lines'])));
            $quantity = $singleLine !== null ? number_format($singleLine['quantity'], 2, '.', '') : '0.00';
            $movements->recordWithinTransaction([
                'contract_id' => $plan['contract_id'],
                'contract_line_id' => $singleLine['id'] ?? null,
                'service_id' => count($serviceIds) === 1 ? $serviceIds[0] : null,
                'movement_type' => 'price_adjustment',
                'mrr_category' => 'price_adjustment',
                'effective_date' => $today,
                'quantity_before' => $quantity,
                'quantity_after' => $quantity,
                'mrr_before' => $plan['old_mrr_mxn'],
                'mrr_after' => $plan['new_mrr_mxn'],
                'mrr_delta_mxn' => $plan['mrr_delta_mxn'],
                'exchange_rate' => bcadd($plan['exchange_rate'], '0', 6),
                'user_id' => $userId,
                'request_id' => null,
                'reason' => mb_substr($batchCode . ': ' . $reason, 0, 255),
                'has_exception' => 0,
                'excepted_policy' => null,
                'reversed_movement_id' => null,
                'created_at' => $nowUtc,
            ]);
        }

        return [
            'batch_id' => $batchId,
            'batch_code' => $batchCode,
            'summary' => $summary,
            'items' => array_map(static fn (array $plan): array => self::publicItem($plan), $plans),
        ];
    }

    /**
     * Servicios recurrentes presentes en líneas activas de contratos activos (catálogo dinámico).
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public static function recurringServiceOptions(?int $segmentId): array
    {
        $builder = Capsule::table('contract_lines as cl')
            ->join('contracts as c', 'c.id', '=', 'cl.contract_id')
            ->join('services as s', 's.id', '=', 'cl.service_id')
            ->where('c.status', 'active')
            ->where('cl.active', 1)
            ->where('cl.service_type', 'recurring');
        if ($segmentId !== null) {
            $builder->where('c.segment_id', $segmentId);
        }

        return $builder
            ->select(['s.id', 's.code', 's.name'])
            ->distinct()
            ->orderBy('s.name')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * Plan por contrato: líneas recurrentes activas afectadas + impacto MRR (BCMath).
     *
     * @param array{segment_id: int|null, issuer_id: int|null, service_id: int|null, anniversary_from: string|null, anniversary_to: string|null} $filters
     * @return list<array<string, mixed>>
     */
    private function buildPlans(array $filters, string $percentage, bool $lockRows): array
    {
        $contractsQuery = Capsule::table('contracts as c')
            ->join('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('segments as seg', 'seg.id', '=', 'c.segment_id')
            ->leftJoin('issuers as iss', 'iss.id', '=', 'c.issuer_id')
            ->where('c.status', 'active');
        if ($filters['segment_id'] !== null) {
            $contractsQuery->where('c.segment_id', $filters['segment_id']);
        }
        if ($filters['issuer_id'] !== null) {
            $contractsQuery->where('c.issuer_id', $filters['issuer_id']);
        }
        // El rango aplica al próximo aniversario/corte (renovación donde entra el nuevo precio).
        if ($filters['anniversary_from'] !== null) {
            $contractsQuery->whereRaw('COALESCE(c.next_cutoff_date, c.anniversary_date) >= ?', [$filters['anniversary_from']]);
        }
        if ($filters['anniversary_to'] !== null) {
            $contractsQuery->whereRaw('COALESCE(c.next_cutoff_date, c.anniversary_date) <= ?', [$filters['anniversary_to']]);
        }
        $contractsQuery->whereExists(function (Builder $sub) use ($filters): void {
            $sub->selectRaw('1')
                ->from('contract_lines as l')
                ->whereColumn('l.contract_id', 'c.id')
                ->where('l.active', 1)
                ->where('l.service_type', 'recurring');
            if ($filters['service_id'] !== null) {
                $sub->where('l.service_id', $filters['service_id']);
            }
        });
        if ($lockRows) {
            $contractsQuery->lockForUpdate();
        }

        $contracts = $contractsQuery
            ->orderByRaw('COALESCE(c.updated_at, c.created_at) DESC')
            ->orderByDesc('c.id')
            ->get([
                'c.id',
                'c.folio',
                'c.mrr_mxn',
                'c.exchange_rate',
                'c.next_cutoff_date',
                'c.anniversary_date',
                'cl.name as client_name',
                'seg.name as segment_name',
                'iss.name as issuer_name',
            ]);
        if ($contracts->isEmpty()) {
            return [];
        }

        $linesQuery = Capsule::table('contract_lines')
            ->whereIn('contract_id', $contracts->pluck('id')->all())
            ->where('active', 1)
            ->where('service_type', 'recurring');
        if ($filters['service_id'] !== null) {
            $linesQuery->where('service_id', $filters['service_id']);
        }
        if ($lockRows) {
            $linesQuery->lockForUpdate();
        }
        $linesByContract = [];
        foreach ($linesQuery->orderBy('id')->get() as $line) {
            $linesByContract[(int) $line->contract_id][] = $line;
        }

        $plans = [];
        foreach ($contracts as $contract) {
            $contractId = (int) $contract->id;
            $exchangeRate = (string) $contract->exchange_rate;
            $affected = [];
            foreach ($linesByContract[$contractId] ?? [] as $line) {
                $adjusted = BulkPriceAdjustmentRules::adjustLine(
                    (int) $line->quantity,
                    (string) $line->unit_price,
                    (string) $line->billing_frequency,
                    $exchangeRate,
                    $percentage,
                );
                if (bccomp($adjusted['unit_price'], FinancialCalculator::normalizeMoney((string) $line->unit_price), self::SCALE) === 0) {
                    continue;
                }
                $affected[] = [
                    'id' => (int) $line->id,
                    'service_id' => (int) $line->service_id,
                    'quantity' => (int) $line->quantity,
                    'old_unit_price' => FinancialCalculator::normalizeMoney((string) $line->unit_price),
                    'new_unit_price' => $adjusted['unit_price'],
                    'new_subtotal' => $adjusted['subtotal'],
                    'old_mrr_mxn' => FinancialCalculator::normalizeMoney((string) $line->mrr_mxn),
                    'new_mrr_mxn' => $adjusted['mrr_mxn'],
                ];
            }
            if ($affected === []) {
                continue;
            }

            $impact = BulkPriceAdjustmentRules::contractImpact((string) $contract->mrr_mxn, $affected);
            $plans[] = [
                'contract_id' => $contractId,
                'folio' => (string) $contract->folio,
                'client_name' => (string) $contract->client_name,
                'segment_name' => (string) ($contract->segment_name ?? ''),
                'issuer_name' => (string) ($contract->issuer_name ?? ''),
                'next_billing_date' => $contract->next_cutoff_date !== null
                    ? (string) $contract->next_cutoff_date
                    : (string) $contract->anniversary_date,
                'exchange_rate' => $exchangeRate,
                'old_mrr_mxn' => $impact['mrr_before'],
                'new_mrr_mxn' => $impact['mrr_after'],
                'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
                'lines' => $affected,
            ];
        }

        return $plans;
    }

    /**
     * @param list<array<string, mixed>> $plans
     * @return array<string, mixed>
     */
    private static function summarize(array $plans, string $percentage): array
    {
        $current = '0.00';
        $new = '0.00';
        $lines = 0;
        foreach ($plans as $plan) {
            $current = bcadd($current, (string) $plan['old_mrr_mxn'], self::SCALE);
            $new = bcadd($new, (string) $plan['new_mrr_mxn'], self::SCALE);
            $lines += count($plan['lines']);
        }

        return [
            'percentage' => $percentage,
            'affected_contracts_count' => count($plans),
            'affected_lines_count' => $lines,
            'current_total_mrr_mxn' => $current,
            'new_total_mrr_mxn' => $new,
            'total_mrr_delta_mxn' => bcsub($new, $current, self::SCALE),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function publicItem(array $plan): array
    {
        return [
            'contract_id' => $plan['contract_id'],
            'folio' => $plan['folio'],
            'client_name' => $plan['client_name'],
            'segment_name' => $plan['segment_name'],
            'issuer_name' => $plan['issuer_name'],
            'next_billing_date' => $plan['next_billing_date'],
            'lines_count' => count($plan['lines']),
            'old_mrr_mxn' => $plan['old_mrr_mxn'],
            'new_mrr_mxn' => $plan['new_mrr_mxn'],
            'mrr_delta_mxn' => $plan['mrr_delta_mxn'],
        ];
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function dateOrNull(mixed $value, string $field): ?string
    {
        $str = trim((string) ($value ?? ''));
        if ($str === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $str);
        if ($date === false || $date->format('Y-m-d') !== $str) {
            throw new InvalidArgumentException($field . ' debe tener formato YYYY-MM-DD.');
        }

        return $str;
    }
}
