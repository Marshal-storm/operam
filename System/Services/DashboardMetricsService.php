<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\Result;
use System\Support\FinancialCalculator;

/**
 * Métricas del Dashboard / Puente de MRR — `GET /api/dashboard`.
 * Acepta `month=YYYY-MM` (CHAR(7) de `monthly_mrr.month`) y filtra por segmento RBAC.
 */
final class DashboardMetricsService extends CustomService
{
    private const MODULE_KEY = 'dashboard';

    private const DELTA_CATEGORIES = [
        'new',
        'expansion',
        'price_adjustment',
        'frequency_change',
        'contraction',
        'churn',
        'reactivation',
    ];

    private const MONTH_NAMES_ES = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        if ($method !== 'GET') {
            return Result::fail('Método no soportado.', 405);
        }

        $auth = $context->getData('auth', []);
        if (!RbacAuthHelper::can($auth, self::MODULE_KEY, 'view')) {
            return Result::fail('No tienes permiso para ver el dashboard.', 403);
        }

        $query = RbacAuthHelper::applySegmentFilterToQuery($auth, $context->getRequest());
        $month = $this->normalizeMonth((string) ($query['month'] ?? ''));
        if ($month === null) {
            return Result::fail('month debe ser YYYY-MM (ej. 2026-11).', 422);
        }

        $segmentFilter = $this->resolveSegmentFilter($auth, $query);
        $payload = $this->buildSnapshot($month, $segmentFilter);

        if ($payload['empty'] === true) {
            return Result::ok($payload, 'Sin datos para el periodo', 200);
        }

        return Result::ok($payload, 'Dashboard MRR');
    }

    /**
     * Normaliza `2026-11` o `2026-11-01` → `YYYY-MM`.
     */
    private function normalizeMonth(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            // Default: mes actual America/Mexico_City.
            $tz = new \DateTimeZone('America/Mexico_City');
            return (new \DateTimeImmutable('now', $tz))->format('Y-m');
        }

        try {
            return FinancialCalculator::monthKeyFromEffectiveDate($trimmed);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $query
     * @return array{segment_id: int|null, segment_name: string}
     */
    private function resolveSegmentFilter(array $auth, array $query): array
    {
        $lockedId = $auth['segment_id'] ?? null;
        if ($lockedId !== null && (int) $lockedId > 0) {
            return [
                'segment_id' => (int) $lockedId,
                'segment_name' => (string) ($auth['segment_name'] ?? 'Todos'),
            ];
        }

        $segmentName = trim((string) ($query['segment'] ?? 'Todos'));
        if ($segmentName === '' || $segmentName === 'Todos') {
            return ['segment_id' => null, 'segment_name' => 'Todos'];
        }

        $segmentId = Capsule::table('segments')->where('name', $segmentName)->value('id');

        return [
            'segment_id' => $segmentId !== null ? (int) $segmentId : -1,
            'segment_name' => $segmentName,
        ];
    }

    /**
     * @param array{segment_id: int|null, segment_name: string} $segmentFilter
     * @return array<string, mixed>
     */
    private function buildSnapshot(string $month, array $segmentFilter): array
    {
        if ($segmentFilter['segment_id'] === -1) {
            return $this->emptyPayload($month, $segmentFilter['segment_name']);
        }

        $deltas = $this->aggregateDeltas($month, $segmentFilter['segment_id']);
        $mrrStart = $this->resolveMrrStart($month, $segmentFilter['segment_id']);
        $bridge = FinancialCalculator::calculateMrrBridge($mrrStart, $this->deltasAsMovements($deltas));
        $mrrEnd = $bridge['mrrEnd'];

        $movements = $this->listMovementsForMonth($month, $segmentFilter['segment_id']);
        $hasActivity =
            $movements !== []
            || bccomp($mrrStart, '0.00', 2) !== 0
            || bccomp($mrrEnd, '0.00', 2) !== 0
            || $this->hasNonZeroDelta($deltas);

        if (!$hasActivity) {
            return $this->emptyPayload($month, $segmentFilter['segment_name']);
        }

        $prevMonth = $this->shiftMonth($month, -1);
        $prevEnd = $this->resolvePreviousMonthEnd($prevMonth, $segmentFilter['segment_id']);
        $variationPct = $this->safePercentChange($prevEnd, $mrrEnd);
        $nrr12m = $this->safeNrr12m($month, $segmentFilter['segment_id'], $mrrEnd);
        $grr = $this->safeGrr($mrrStart, $deltas);

        $activeContracts = $this->countActiveContracts($segmentFilter['segment_id']);
        $newContracts = $this->countNewContractsInMonth($month, $segmentFilter['segment_id']);
        $churnedContracts = $this->countChurnedInMonth($month, $segmentFilter['segment_id']);
        $aging = $this->buildAging($segmentFilter['segment_id']);

        return [
            'empty' => false,
            'periodLabel' => $this->formatPeriodLabel($month),
            'mrrStart' => (float) $mrrStart,
            'deltasByCategory' => array_map(
                static fn (string $amount): float => (float) $amount,
                $deltas,
            ),
            'kpis' => [
                'mrrMxn' => (float) $mrrEnd,
                'mrrDeltaVsPrevPercent' => $variationPct,
                'activeContracts' => $activeContracts,
                'newContracts' => $newContracts,
                'churnedContracts' => $churnedContracts,
                'nrr12mPercent' => $nrr12m,
                'grrPercent' => $grr,
                'overduePortfolioMxn' => $aging['total_overdue'],
                'overdueOver60Count' => $aging['over_60_count'],
            ],
            'aging' => $aging['buckets'],
            'recentMovements' => array_slice($movements, 0, 3),
            'allMovements' => $movements,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function aggregateDeltas(string $month, ?int $segmentId): array
    {
        $deltas = [];
        foreach (self::DELTA_CATEGORIES as $category) {
            $deltas[$category] = '0.00';
        }

        // Primario: agregado monthly_mrr (CHAR(7) YYYY-MM).
        $builder = Capsule::table('monthly_mrr')
            ->selectRaw('category, SUM(amount) as total')
            ->where('month', $month)
            ->whereIn('category', self::DELTA_CATEGORIES)
            ->groupBy('category');
        $this->applySegmentId($builder, $segmentId, 'segment_id');

        foreach ($builder->get() as $row) {
            $cat = (string) $row->category;
            if (!isset($deltas[$cat])) {
                continue;
            }
            $deltas[$cat] = FinancialCalculator::normalizeMoney((string) ($row->total ?? '0.00'));
        }

        // Fallback: si no hay filas en monthly_mrr, suma desde movements del mes.
        if (!$this->hasNonZeroDelta($deltas)) {
            $fromMovements = $this->aggregateDeltasFromMovements($month, $segmentId);
            foreach ($fromMovements as $cat => $amount) {
                $deltas[$cat] = $amount;
            }
        }

        return $deltas;
    }

    /**
     * @return array<string, string>
     */
    private function aggregateDeltasFromMovements(string $month, ?int $segmentId): array
    {
        $deltas = [];
        foreach (self::DELTA_CATEGORIES as $category) {
            $deltas[$category] = '0.00';
        }

        [$from, $to] = $this->monthDateBounds($month);
        $builder = Capsule::table('movements as m')
            ->join('contracts as c', 'c.id', '=', 'm.contract_id')
            ->selectRaw('m.mrr_category, SUM(m.mrr_delta_mxn) as total')
            ->whereBetween('m.effective_date', [$from, $to])
            ->whereIn('m.mrr_category', self::DELTA_CATEGORIES)
            ->groupBy('m.mrr_category');
        $this->applySegmentId($builder, $segmentId, 'c.segment_id');

        foreach ($builder->get() as $row) {
            $cat = (string) $row->mrr_category;
            if (!isset($deltas[$cat])) {
                continue;
            }
            $deltas[$cat] = FinancialCalculator::normalizeMoney((string) ($row->total ?? '0.00'));
        }

        return $deltas;
    }

    /**
     * MRR de apertura: fila `start` de monthly_mrr, o Fin del mes anterior, o 0.00.
     */
    private function resolveMrrStart(string $month, ?int $segmentId): string
    {
        $builder = Capsule::table('monthly_mrr')
            ->where('month', $month)
            ->where('category', 'start');
        $this->applySegmentId($builder, $segmentId, 'segment_id');
        $startSum = $builder->sum('amount');
        if ($startSum !== null && bccomp((string) $startSum, '0.00', 2) !== 0) {
            return FinancialCalculator::normalizeMoney((string) $startSum);
        }

        $prevMonth = $this->shiftMonth($month, -1);

        return $this->resolvePreviousMonthEnd($prevMonth, $segmentId);
    }

    private function resolvePreviousMonthEnd(string $prevMonth, ?int $segmentId): string
    {
        $endBuilder = Capsule::table('monthly_mrr')
            ->where('month', $prevMonth)
            ->where('category', 'end');
        $this->applySegmentId($endBuilder, $segmentId, 'segment_id');
        $endSum = $endBuilder->sum('amount');
        if ($endSum !== null && (string) $endSum !== '') {
            return FinancialCalculator::normalizeMoney((string) $endSum);
        }

        // Sin fila end: reconstruye Fin = start_prev + deltas_prev (o 0).
        $prevDeltas = $this->aggregateDeltas($prevMonth, $segmentId);
        $prevStartBuilder = Capsule::table('monthly_mrr')
            ->where('month', $prevMonth)
            ->where('category', 'start');
        $this->applySegmentId($prevStartBuilder, $segmentId, 'segment_id');
        $prevStartRaw = $prevStartBuilder->sum('amount');
        $prevStart = $prevStartRaw !== null
            ? FinancialCalculator::normalizeMoney((string) $prevStartRaw)
            : '0.00';

        $bridge = FinancialCalculator::calculateMrrBridge(
            $prevStart,
            $this->deltasAsMovements($prevDeltas),
        );

        return $bridge['mrrEnd'];
    }

    /**
     * Variación % vs mes anterior. Si base = 0 → "—" (sin NaN/INF).
     */
    private function safePercentChange(string $previous, string $current): string
    {
        if (bccomp($previous, '0.00', 2) === 0) {
            return '—';
        }

        $diff = bcsub($current, $previous, 4);
        $ratio = bcmul(bcdiv($diff, $previous, 6), '100', 2);
        $sign = bccomp($ratio, '0.00', 2) > 0 ? '+' : '';

        return $sign . $ratio . '%';
    }

    /**
     * NRR 12m simplificado: Fin actual / MRR hace 12 meses.
     * Si el denominador es 0 → "—".
     */
    private function safeNrr12m(string $month, ?int $segmentId, string $mrrEnd): string
    {
        $baseMonth = $this->shiftMonth($month, -12);
        $base = $this->resolvePreviousMonthEnd($baseMonth, $segmentId);
        if (bccomp($base, '0.00', 2) === 0) {
            // Sin historial 12m: usa inicio del mes actual si > 0; si no, "—".
            $startBuilder = Capsule::table('monthly_mrr')
                ->where('month', $month)
                ->where('category', 'start');
            $this->applySegmentId($startBuilder, $segmentId, 'segment_id');
            $startRaw = $startBuilder->sum('amount');
            $start = $startRaw !== null
                ? FinancialCalculator::normalizeMoney((string) $startRaw)
                : '0.00';
            if (bccomp($start, '0.00', 2) === 0) {
                return '—';
            }
            $base = $start;
        }

        $ratio = bcmul(bcdiv($mrrEnd, $base, 6), '100', 1);

        return $ratio . '%';
    }

    /**
     * GRR aproximado: (Inicio + expansión - contracción - churn) / Inicio.
     * Si Inicio = 0 → "—".
     *
     * @param array<string, string> $deltas
     */
    private function safeGrr(string $mrrStart, array $deltas): string
    {
        if (bccomp($mrrStart, '0.00', 2) === 0) {
            return '—';
        }

        $retained = $mrrStart;
        $retained = bcadd($retained, $deltas['expansion'] ?? '0.00', 2);
        $retained = bcadd($retained, $deltas['contraction'] ?? '0.00', 2);
        $retained = bcadd($retained, $deltas['churn'] ?? '0.00', 2);
        if (bccomp($retained, '0.00', 2) < 0) {
            $retained = '0.00';
        }

        $ratio = bcmul(bcdiv($retained, $mrrStart, 6), '100', 1);

        return $ratio . '%';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listMovementsForMonth(string $month, ?int $segmentId): array
    {
        [$from, $to] = $this->monthDateBounds($month);

        $builder = Capsule::table('movements as m')
            ->join('contracts as c', 'c.id', '=', 'm.contract_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('segments as s', 's.id', '=', 'c.segment_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('service_requests as sr', 'sr.id', '=', 'm.request_id')
            ->whereBetween('m.effective_date', [$from, $to])
            ->whereIn('m.mrr_category', self::DELTA_CATEGORIES)
            ->orderBy('m.created_at', 'desc')
            ->orderBy('m.id', 'desc')
            ->limit(100)
            ->select([
                'm.id',
                'm.effective_date',
                'm.mrr_category',
                'm.mrr_before',
                'm.mrr_after',
                'm.mrr_delta_mxn',
                'm.reason',
                'c.folio as contract_folio',
                'cl.name as client_name',
                's.name as segment_name',
                'u.name as user_name',
                'sr.folio as request_folio',
            ]);
        $this->applySegmentId($builder, $segmentId, 'c.segment_id');

        $rows = [];
        foreach ($builder->get() as $row) {
            $requestFolio = $row->request_folio !== null && (string) $row->request_folio !== ''
                ? (string) $row->request_folio
                : null;
            $rows[] = [
                'id' => (string) $row->id,
                'effective_date' => (string) $row->effective_date,
                'contract_folio' => (string) ($row->contract_folio ?? ''),
                'client_name' => (string) ($row->client_name ?? '—'),
                'segment' => (string) ($row->segment_name ?? '—'),
                'mrr_category' => (string) $row->mrr_category,
                'description' => (string) ($row->reason ?? ''),
                'mrr_before' => FinancialCalculator::normalizeMoney((string) $row->mrr_before),
                'mrr_after' => FinancialCalculator::normalizeMoney((string) $row->mrr_after),
                'mrr_delta_mxn' => FinancialCalculator::normalizeMoney((string) $row->mrr_delta_mxn),
                'user' => (string) ($row->user_name ?? 'Sistema'),
                'reason' => (string) ($row->reason ?? ''),
                'request_folio' => $requestFolio,
                // Compat con frontend histórico del Dashboard.
                'request_origin' => $requestFolio,
            ];
        }

        return $rows;
    }

    private function countActiveContracts(?int $segmentId): int
    {
        $builder = Capsule::table('contracts')->where('status', 'active');
        $this->applySegmentId($builder, $segmentId, 'segment_id');

        return (int) $builder->count();
    }

    private function countNewContractsInMonth(string $month, ?int $segmentId): int
    {
        [$from, $to] = $this->monthDateBounds($month);
        $builder = Capsule::table('contracts')
            ->whereBetween('anniversary_date', [$from, $to])
            ->whereIn('status', ['active', 'draft']);
        $this->applySegmentId($builder, $segmentId, 'segment_id');

        return (int) $builder->count();
    }

    private function countChurnedInMonth(string $month, ?int $segmentId): int
    {
        [$from, $to] = $this->monthDateBounds($month);
        $builder = Capsule::table('movements as m')
            ->join('contracts as c', 'c.id', '=', 'm.contract_id')
            ->whereBetween('m.effective_date', [$from, $to])
            ->where('m.mrr_category', 'churn');
        $this->applySegmentId($builder, $segmentId, 'c.segment_id');

        return (int) $builder->selectRaw('COUNT(DISTINCT m.contract_id) as total')->value('total');
    }

    /**
     * Antigüedad real desde `invoices` + `collection_statuses` (BCMath, filtro segmento).
     *
     * @return array{buckets: list<array{label: string, amountMxn: int, color: string}>, total_overdue: float, over_60_count: int}
     */
    private function buildAging(?int $segmentId): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');

        $buckets = [
            'Por vencer' => ['amount' => '0.00', 'color' => '#10B981'],
            '1-30 días' => ['amount' => '0.00', 'color' => '#F59E0B'],
            '31-60 días' => ['amount' => '0.00', 'color' => '#F97316'],
            '61-90 días' => ['amount' => '0.00', 'color' => '#EF4444'],
            'Suspendidos (+90)' => ['amount' => '0.00', 'color' => '#7F1D1D'],
        ];

        // Fuente primaria: saldos abiertos en invoices (Módulo 4 Paso 2).
        if (Capsule::schema()->hasTable('invoices')) {
            $invBuilder = Capsule::table('invoices as i')
                ->leftJoin('collection_statuses as cs', 'cs.contract_id', '=', 'i.contract_id')
                ->whereIn('i.status', ['pending', 'partial', 'overdue'])
                ->whereRaw('i.balance > 0')
                ->select(['i.balance', 'i.due_date', 'cs.is_suspended']);
            $this->applySegmentId($invBuilder, $segmentId, 'i.segment_id');

            foreach ($invBuilder->get() as $row) {
                $balance = FinancialCalculator::normalizeMoney((string) $row->balance);
                $due = (string) $row->due_date;
                $suspended = (bool) ($row->is_suspended ?? false);
                if ($due >= $today && !$suspended) {
                    $key = 'Por vencer';
                } else {
                    $from = \DateTimeImmutable::createFromFormat('Y-m-d', $due);
                    $to = \DateTimeImmutable::createFromFormat('Y-m-d', $today);
                    $days = ($from !== false && $to !== false)
                        ? max(0, (int) $from->diff($to)->format('%r%a'))
                        : 0;
                    if ($suspended || $days >= 90) {
                        $key = 'Suspendidos (+90)';
                    } elseif ($days <= 30) {
                        $key = '1-30 días';
                    } elseif ($days <= 60) {
                        $key = '31-60 días';
                    } else {
                        $key = '61-90 días';
                    }
                }
                $buckets[$key]['amount'] = bcadd($buckets[$key]['amount'], $balance, 2);
            }
        } else {
            // Fallback histórico: solo collection_statuses (seeders demo).
            $builder = Capsule::table('collection_statuses as cs')
                ->join('contracts as c', 'c.id', '=', 'cs.contract_id')
                ->select(['cs.overdue_balance', 'cs.overdue_days', 'cs.is_suspended']);
            $this->applySegmentId($builder, $segmentId, 'c.segment_id');
            foreach ($builder->get() as $row) {
                $balance = FinancialCalculator::normalizeMoney((string) $row->overdue_balance);
                if (bccomp($balance, '0.00', 2) <= 0) {
                    continue;
                }
                $days = (int) $row->overdue_days;
                $suspended = (bool) $row->is_suspended;
                if ($suspended || $days >= 90) {
                    $key = 'Suspendidos (+90)';
                } elseif ($days <= 0) {
                    $key = 'Por vencer';
                } elseif ($days <= 30) {
                    $key = '1-30 días';
                } elseif ($days <= 60) {
                    $key = '31-60 días';
                } else {
                    $key = '61-90 días';
                }
                $buckets[$key]['amount'] = bcadd($buckets[$key]['amount'], $balance, 2);
            }
        }

        $statusBuilder = Capsule::table('collection_statuses as cs')
            ->join('contracts as c', 'c.id', '=', 'cs.contract_id')
            ->select(['cs.overdue_days', 'cs.is_suspended', 'c.status']);
        $this->applySegmentId($statusBuilder, $segmentId, 'c.segment_id');
        $over60 = 0;
        foreach ($statusBuilder->get() as $row) {
            $days = (int) $row->overdue_days;
            $suspended = (bool) $row->is_suspended || (string) $row->status === 'suspended';
            if ($days > 60 || $suspended) {
                $over60++;
            }
        }

        $totalOverdue = '0.00';
        foreach (['1-30 días', '31-60 días', '61-90 días', 'Suspendidos (+90)'] as $label) {
            $totalOverdue = bcadd($totalOverdue, $buckets[$label]['amount'], 2);
        }

        $out = [];
        foreach ($buckets as $label => $meta) {
            // amountMxn entero para compat UI histórica (sin float en aritmética BCMath).
            $whole = bcadd($meta['amount'], '0', 0);
            $out[] = [
                'label' => $label,
                'amountMxn' => (int) $whole,
                'color' => $meta['color'],
            ];
        }

        return [
            'buckets' => $out,
            'total_overdue' => (float) $totalOverdue,
            'over_60_count' => $over60,
        ];
    }

    /**
     * @param array<string, string> $deltas
     * @return list<array{mrr_category: string, mrr_delta_mxn: string}>
     */
    private function deltasAsMovements(array $deltas): array
    {
        $rows = [];
        foreach ($deltas as $category => $amount) {
            $rows[] = [
                'mrr_category' => $category,
                'mrr_delta_mxn' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, string> $deltas
     */
    private function hasNonZeroDelta(array $deltas): bool
    {
        foreach ($deltas as $amount) {
            if (bccomp($amount, '0.00', 2) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function applySegmentId(Builder $builder, ?int $segmentId, string $column): void
    {
        if ($segmentId !== null) {
            $builder->where($column, $segmentId);
        }
    }

    /** @return array{0: string, 1: string} */
    private function monthDateBounds(string $month): array
    {
        $from = $month . '-01';
        $tz = new \DateTimeZone('UTC');
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $from, $tz);
        if ($start === false) {
            return [$from, $from];
        }
        $to = $start->modify('last day of this month')->format('Y-m-d');

        return [$from, $to];
    }

    private function shiftMonth(string $month, int $deltaMonths): string
    {
        $tz = new \DateTimeZone('UTC');
        $base = \DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01', $tz);
        if ($base === false) {
            return $month;
        }
        $sign = $deltaMonths >= 0 ? '+' : '';

        return $base->modify("{$sign}{$deltaMonths} months")->format('Y-m');
    }

    private function formatPeriodLabel(string $month): string
    {
        [$year, $monthNum] = array_map('intval', explode('-', $month));
        $name = self::MONTH_NAMES_ES[$monthNum] ?? $month;

        return "Cierre de {$name} {$year} · Datos en vivo de MySQL";
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(string $month, string $segmentName): array
    {
        return [
            'empty' => true,
            'periodLabel' => $this->formatPeriodLabel($month),
            'segment' => $segmentName,
            'mrrStart' => 0,
            'deltasByCategory' => array_fill_keys(self::DELTA_CATEGORIES, 0),
            'kpis' => null,
            'aging' => [],
            'recentMovements' => [],
            'allMovements' => [],
        ];
    }
}
