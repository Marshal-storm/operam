<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\Result;
use System\Support\AuditLogger;
use System\Support\FinancialCalculator;
use System\Support\SegmentProfitabilityCalculator;

/**
 * Utilidad por segmento (RF-10) + ingresos no recurrentes + reporte de excepciones (RF-11.5).
 * Endpoints bajo `/api/segment-profitability/*` con RBAC `segment_profitability` y alcance JWT.
 */
final class SegmentProfitabilityService extends CustomService
{
    private const MODULE_KEY = 'segment_profitability';

    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    private const COST_CATEGORIES = [
        'servers',
        'support_payroll',
        'implementation_payroll',
        'pac_folios',
        'other',
    ];

    /** @var array<string, mixed> */
    private array $authContext = [];

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery(
            $this->authContext,
            $context->getRequest(),
        );

        $uri = strtolower(trim($context->getUri(), '/'));
        $parts = $uri === '' ? [] : explode('/', $uri);
        // parts[0]=segment-profitability, parts[1]=section, parts[2]=id
        $section = isset($parts[1]) ? strtolower((string) $parts[1]) : 'summary';
        $resourceId = isset($parts[2]) && ctype_digit((string) $parts[2])
            ? (int) $parts[2]
            : null;
        $method = $context->getMethod();

        return match ($section) {
            'summary' => $method === 'GET'
                ? $this->requireView(fn (): Result => Result::ok(
                    $this->buildSummary($request),
                    'Resumen de utilidad por segmento',
                ))
                : Result::fail('Método no soportado.', 405),
            'costs' => $this->handleCosts($method, $resourceId, $request),
            'non-recurring' => $method === 'GET'
                ? $this->requireView(fn (): Result => Result::ok(
                    $this->listNonRecurring($request),
                    'Ingresos no recurrentes',
                ))
                : Result::fail('Método no soportado.', 405),
            'exceptions' => $method === 'GET'
                ? $this->requireView(fn (): Result => Result::ok(
                    $this->listExceptions($request),
                    'Reporte de excepciones',
                ))
                : Result::fail('Método no soportado.', 405),
            default => Result::fail('Recurso de utilidad por segmento no encontrado.', 404),
        };
    }

    /**
     * @param callable(): Result $callback
     */
    private function requireView(callable $callback): Result
    {
        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
            return Result::fail('Sin permiso para consultar utilidad por segmento.', 403);
        }

        return $callback();
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleCosts(string $method, ?int $resourceId, array $request): Result
    {
        if ($method === 'GET' && $resourceId === null) {
            return $this->requireView(fn (): Result => Result::ok(
                $this->listCosts($request),
                'Costos mensuales',
            ));
        }

        if ($method === 'POST' && $resourceId === null) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                return Result::fail('Sin permiso para registrar costos.', 403);
            }

            return $this->createCost($request);
        }

        if ($resourceId === null) {
            return Result::fail('Se requiere id de costo.', 400);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar costos.', 403);
            }

            return $this->updateCost($resourceId, $request);
        }

        if ($method === 'DELETE') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'delete')) {
                return Result::fail('Sin permiso para eliminar costos.', 403);
            }

            return $this->deleteCost($resourceId);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function buildSummary(array $query): array
    {
        $month = $this->resolveMonth((string) ($query['month'] ?? ''));
        $segmentFilter = $this->resolveSegmentFilter($query);
        $segments = $this->loadSegments($segmentFilter);

        $bySegment = [];
        $consolRecurring = '0.00';
        $consolNonRecurring = '0.00';
        $consolCost = '0.00';
        $consolContracts = 0;
        $consolUsers = 0;

        foreach ($segments as $segment) {
            $segmentId = (int) $segment['id'];
            $metrics = $this->metricsForSegmentMonth($segmentId, $month);
            $unit = SegmentProfitabilityCalculator::computeUnitEconomics(
                $metrics['total_revenue_mxn'],
                $metrics['total_cost_mxn'],
                $metrics['gross_profit_mxn'],
                $metrics['active_contracts_count'],
                $metrics['active_concurrent_users_count'],
            );

            $bySegment[] = array_merge(
                [
                    'segment_id' => $segmentId,
                    'segment_name' => (string) $segment['name'],
                ],
                $metrics,
                $unit,
            );

            $consolRecurring = bcadd($consolRecurring, $metrics['recurring_revenue_mxn'], 2);
            $consolNonRecurring = bcadd($consolNonRecurring, $metrics['non_recurring_revenue_mxn'], 2);
            $consolCost = bcadd($consolCost, $metrics['total_cost_mxn'], 2);
            $consolContracts += $metrics['active_contracts_count'];
            $consolUsers += $metrics['active_concurrent_users_count'];
        }

        $consolGross = SegmentProfitabilityCalculator::computeGrossMetrics(
            $consolRecurring,
            $consolNonRecurring,
            $consolCost,
        );
        $consolUnit = SegmentProfitabilityCalculator::computeUnitEconomics(
            $consolGross['total_revenue_mxn'],
            $consolGross['total_cost_mxn'],
            $consolGross['gross_profit_mxn'],
            $consolContracts,
            $consolUsers,
        );

        $monthKeys = SegmentProfitabilityCalculator::lastNMonthKeys($month, 6);
        $monthlyTrend = [];
        foreach ($monthKeys as $key) {
            $rec = '0.00';
            $non = '0.00';
            $cost = '0.00';
            foreach ($segments as $segment) {
                $m = $this->metricsForSegmentMonth((int) $segment['id'], $key);
                $rec = bcadd($rec, $m['recurring_revenue_mxn'], 2);
                $non = bcadd($non, $m['non_recurring_revenue_mxn'], 2);
                $cost = bcadd($cost, $m['total_cost_mxn'], 2);
            }
            $g = SegmentProfitabilityCalculator::computeGrossMetrics($rec, $non, $cost);
            $monthlyTrend[] = [
                'month' => $key,
                'total_revenue_mxn' => $g['total_revenue_mxn'],
                'total_cost_mxn' => $g['total_cost_mxn'],
                'gross_profit_mxn' => $g['gross_profit_mxn'],
            ];
        }

        return [
            'month' => $month,
            'segment_filter' => $segmentFilter,
            'consolidated' => array_merge($consolGross, $consolUnit),
            'by_segment' => $bySegment,
            'monthly_trend' => $monthlyTrend,
        ];
    }

    /**
     * @return array{
     *   recurring_revenue_mxn:string,
     *   non_recurring_revenue_mxn:string,
     *   total_revenue_mxn:string,
     *   total_cost_mxn:string,
     *   gross_profit_mxn:string,
     *   gross_margin_pct:string,
     *   active_contracts_count:int,
     *   active_concurrent_users_count:int
     * }
     */
    private function metricsForSegmentMonth(int $segmentId, string $month): array
    {
        // Ingresos recurrentes: facturas del mes → MRR fin de mes → MRR de contratos activos.
        $recurring = $this->sumInvoiceSubtotal($segmentId, $month, ['recurring']);
        if (bccomp($recurring, '0.00', 2) === 0) {
            $recurring = $this->sumMonthlyMrrEnd($segmentId, $month);
        }
        if (bccomp($recurring, '0.00', 2) === 0) {
            $recurring = $this->sumActiveContractMrr($segmentId, $month);
        }

        $nonRecurring = $this->sumInvoiceSubtotal($segmentId, $month, ['one_time', 'consumption']);
        $cost = $this->sumCosts($segmentId, $month);
        $gross = SegmentProfitabilityCalculator::computeGrossMetrics($recurring, $nonRecurring, $cost);
        $contracts = $this->countActiveContracts($segmentId, $month);
        $users = $this->sumActiveConcurrentUsers($segmentId, $month);

        return array_merge($gross, [
            'active_contracts_count' => $contracts,
            'active_concurrent_users_count' => $users,
        ]);
    }

    /**
     * Subtotal de facturas no canceladas del mes (YYYY-MM), por segmento.
     * Cruza `invoices.segment_id` y, si hace falta, el segmento del contrato.
     *
     * @param list<string> $types
     */
    private function sumInvoiceSubtotal(int $segmentId, string $month, array $types): string
    {
        $raw = Capsule::table('invoices as i')
            ->leftJoin('contracts as c', 'c.id', '=', 'i.contract_id')
            ->whereIn('i.invoice_type', $types)
            ->where('i.status', '!=', 'cancelled')
            ->whereRaw("DATE_FORMAT(i.issue_date, '%Y-%m') = ?", [$month])
            ->where(static function (Builder $q) use ($segmentId): void {
                $q->where('i.segment_id', $segmentId)
                    ->orWhere('c.segment_id', $segmentId);
            })
            ->sum('i.subtotal');

        return FinancialCalculator::normalizeMoney((string) ($raw ?? '0'));
    }

    /** MRR de cierre del Puente (`monthly_mrr.category = end`) para el mes/segmento. */
    private function sumMonthlyMrrEnd(int $segmentId, string $month): string
    {
        $raw = Capsule::table('monthly_mrr')
            ->where('month', $month)
            ->where('segment_id', $segmentId)
            ->where('category', 'end')
            ->sum('amount');

        return FinancialCalculator::normalizeMoney((string) ($raw ?? '0'));
    }

    /**
     * Suma `mrr_mxn` de contratos activos del segmento vigentes al cierre del mes.
     */
    private function sumActiveContractMrr(int $segmentId, string $month): string
    {
        $raw = $this->activeContractsQuery($segmentId, $month)->sum('mrr_mxn');

        return FinancialCalculator::normalizeMoney((string) ($raw ?? '0'));
    }

    private function sumCosts(int $segmentId, string $month): string
    {
        $raw = Capsule::table('segment_costs')
            ->where('segment_id', $segmentId)
            ->where('month', $month)
            ->sum('amount');

        return FinancialCalculator::normalizeMoney((string) ($raw ?? '0'));
    }

    private function countActiveContracts(int $segmentId, string $month): int
    {
        return (int) $this->activeContractsQuery($segmentId, $month)->count();
    }

    /**
     * Contratos `active` del segmento ya existentes al cierre del mes (anniversary/created_at).
     */
    private function activeContractsQuery(int $segmentId, string $month): Builder
    {
        $lastDay = $this->monthLastDay($month);

        return Capsule::table('contracts')
            ->where('segment_id', $segmentId)
            ->where('status', 'active')
            ->whereRaw('DATE(COALESCE(anniversary_date, created_at)) <= ?', [$lastDay]);
    }

    /** Último día calendario del mes `YYYY-MM` (America/Mexico_City conceptual). */
    private function monthLastDay(string $month): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01');
        if ($dt === false) {
            return $month . '-28';
        }

        return $dt->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * Suma usuarios concurrentes equivalentes (paquetes × factor + individuales)
     * sobre líneas recurrentes activas de contratos vigentes en el mes.
     */
    private function sumActiveConcurrentUsers(int $segmentId, string $month): int
    {
        $lastDay = $this->monthLastDay($month);
        $rows = Capsule::table('contract_lines as cl')
            ->join('contracts as c', 'c.id', '=', 'cl.contract_id')
            ->leftJoin('services as s', 's.id', '=', 'cl.service_id')
            ->where('c.segment_id', $segmentId)
            ->where('c.status', 'active')
            ->whereRaw('DATE(COALESCE(c.anniversary_date, c.created_at)) <= ?', [$lastDay])
            ->where('cl.active', 1)
            ->where('cl.service_type', 'recurring')
            ->get([
                'cl.quantity',
                'cl.is_package_converted',
                'cl.service_id',
                's.package_factor',
            ]);

        $total = 0;
        foreach ($rows as $row) {
            $isPkg = (bool) $row->is_package_converted;
            $factor = (int) ($row->package_factor ?? 0);
            if ($isPkg && $factor < 2) {
                // El factor vive en el servicio individual que apunta al paquete.
                $factor = (int) (Capsule::table('services')
                    ->where('package_service_id', (int) $row->service_id)
                    ->max('package_factor') ?? 5);
            }
            $total += SegmentProfitabilityCalculator::concurrentUsersFromLine(
                (int) $row->quantity,
                $isPkg,
                $factor,
            );
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
     */
    private function listCosts(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $builder = Capsule::table('segment_costs as sc')
            ->leftJoin('segments as s', 's.id', '=', 'sc.segment_id')
            ->leftJoin('users as u', 'u.id', '=', 'sc.created_by_user_id');

        $month = trim((string) ($query['month'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $builder->where('sc.month', $month);
        }

        $segmentFilter = $this->resolveSegmentFilter($query);
        if ($segmentFilter !== null) {
            $builder->where('sc.segment_id', $segmentFilter);
        }

        $category = trim((string) ($query['category'] ?? ''));
        if ($category !== '' && in_array($category, self::COST_CATEGORIES, true)) {
            $builder->where('sc.category', $category);
        }

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('sc.concept', 'like', $like)
                    ->orWhere('sc.notes', 'like', $like)
                    ->orWhere('s.name', 'like', $like);
            });
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderByRaw('COALESCE(sc.updated_at, sc.created_at) DESC')
            ->orderByDesc('sc.id')
            ->forPage($page, $perPage)
            ->get([
                'sc.id',
                'sc.segment_id',
                's.name as segment_name',
                'sc.month',
                'sc.category',
                'sc.concept',
                'sc.amount',
                'sc.notes',
                'sc.created_by_user_id',
                'u.name as created_by_name',
                'sc.created_at',
                'sc.updated_at',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapCostRow($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createCost(array $payload): Result
    {
        $validated = $this->validateCostPayload($payload, true);
        if ($validated instanceof Result) {
            return $validated;
        }

        $scopeError = RbacAuthHelper::assertSegmentWriteAllowed($this->authContext, $validated);
        if ($scopeError !== null) {
            return Result::fail($scopeError, 403);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $id = (int) Capsule::table('segment_costs')->insertGetId([
            'segment_id' => $validated['segment_id'],
            'month' => $validated['month'],
            'category' => $validated['category'],
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'notes' => $validated['notes'],
            'created_by_user_id' => $userId > 0 ? $userId : null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $created = $this->findCost($id);
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'segment_costs',
            (string) $id,
            'create',
            sprintf('Se registró el costo "%s" (%s)', $validated['concept'], $validated['month']),
            null,
            $created,
        );

        return Result::ok($created, 'Costo registrado', 201);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateCost(int $id, array $payload): Result
    {
        $existing = Capsule::table('segment_costs')->where('id', $id)->first();
        if ($existing === null) {
            return Result::fail('Costo no encontrado.', 404);
        }
        if (!$this->isSegmentAllowed((int) $existing->segment_id)) {
            return Result::fail('Costo fuera de tu alcance de segmento.', 403);
        }

        $validated = $this->validateCostPayload($payload, false);
        if ($validated instanceof Result) {
            return $validated;
        }

        if (isset($validated['segment_id'])) {
            $scopeError = RbacAuthHelper::assertSegmentWriteAllowed($this->authContext, $validated);
            if ($scopeError !== null) {
                return Result::fail($scopeError, 403);
            }
        }

        $before = $this->findCost($id);
        $validated['updated_at'] = gmdate('Y-m-d H:i:s');
        Capsule::table('segment_costs')->where('id', $id)->update($validated);
        $after = $this->findCost($id);

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'segment_costs',
            (string) $id,
            'update',
            sprintf('Se actualizó el costo #%d', $id),
            $before,
            $after,
        );

        return Result::ok($after, 'Costo actualizado');
    }

    private function deleteCost(int $id): Result
    {
        $existing = Capsule::table('segment_costs')->where('id', $id)->first();
        if ($existing === null) {
            return Result::fail('Costo no encontrado.', 404);
        }
        if (!$this->isSegmentAllowed((int) $existing->segment_id)) {
            return Result::fail('Costo fuera de tu alcance de segmento.', 403);
        }

        $before = $this->findCost($id);
        Capsule::table('segment_costs')->where('id', $id)->delete();

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'segment_costs',
            (string) $id,
            'delete',
            sprintf('Se eliminó el costo "%s"', (string) ($before['concept'] ?? ('#' . $id))),
            $before,
            null,
        );

        return Result::ok(['id' => $id], 'Costo eliminado');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|Result
     */
    private function validateCostPayload(array $payload, bool $requireAll): array|Result
    {
        $out = [];

        if ($requireAll || array_key_exists('segment_id', $payload)) {
            $segmentId = (int) ($payload['segment_id'] ?? 0);
            if ($segmentId < 1 || !Capsule::table('segments')->where('id', $segmentId)->exists()) {
                return Result::fail('segment_id inválido.', 422);
            }
            if (!$this->isSegmentAllowed($segmentId)) {
                return Result::fail('Segmento fuera de tu alcance.', 403);
            }
            $out['segment_id'] = $segmentId;
        }

        if ($requireAll || array_key_exists('month', $payload)) {
            $month = trim((string) ($payload['month'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return Result::fail('month inválido (YYYY-MM).', 422);
            }
            $out['month'] = $month;
        }

        if ($requireAll || array_key_exists('category', $payload)) {
            $category = strtolower(trim((string) ($payload['category'] ?? 'other')));
            if (!in_array($category, self::COST_CATEGORIES, true)) {
                return Result::fail(
                    'category inválida. Usa: ' . implode(', ', self::COST_CATEGORIES) . '.',
                    422,
                );
            }
            $out['category'] = $category;
        }

        if ($requireAll || array_key_exists('concept', $payload)) {
            $concept = trim((string) ($payload['concept'] ?? ''));
            if ($concept === '' || mb_strlen($concept) > 160) {
                return Result::fail('concept es obligatorio (máx. 160 caracteres).', 422);
            }
            $out['concept'] = mb_substr($concept, 0, 160);
        }

        if ($requireAll || array_key_exists('amount', $payload)) {
            $rawAmount = trim((string) ($payload['amount'] ?? ''));
            if ($rawAmount === '' || !is_numeric(str_replace([',', ' '], '', $rawAmount))) {
                return Result::fail(
                    'El monto debe ser un número válido mayor a 0.',
                    422,
                );
            }
            $amount = FinancialCalculator::normalizeMoney($rawAmount);
            if (bccomp($amount, '0.00', 2) <= 0) {
                return Result::fail('El monto debe ser un número válido mayor a 0.', 422);
            }
            $out['amount'] = $amount;
        }

        if ($requireAll || array_key_exists('notes', $payload)) {
            $notes = $payload['notes'] ?? null;
            if ($notes === null || trim((string) $notes) === '') {
                $out['notes'] = null;
            } else {
                $out['notes'] = mb_substr(trim((string) $notes), 0, 4000);
            }
        }

        if ($out === []) {
            return Result::fail('No hay campos para actualizar.', 422);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findCost(int $id): ?array
    {
        $row = Capsule::table('segment_costs as sc')
            ->leftJoin('segments as s', 's.id', '=', 'sc.segment_id')
            ->leftJoin('users as u', 'u.id', '=', 'sc.created_by_user_id')
            ->where('sc.id', $id)
            ->first([
                'sc.id',
                'sc.segment_id',
                's.name as segment_name',
                'sc.month',
                'sc.category',
                'sc.concept',
                'sc.amount',
                'sc.notes',
                'sc.created_by_user_id',
                'u.name as created_by_name',
                'sc.created_at',
                'sc.updated_at',
            ]);

        return $row ? $this->mapCostRow($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCostRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'segment_id' => (int) $row->segment_id,
            'segment_name' => $row->segment_name !== null ? (string) $row->segment_name : null,
            'month' => (string) $row->month,
            'category' => (string) $row->category,
            'concept' => (string) $row->concept,
            'amount' => FinancialCalculator::normalizeMoney((string) $row->amount),
            'notes' => $row->notes !== null ? (string) $row->notes : null,
            'created_by_user_id' => $row->created_by_user_id !== null
                ? (int) $row->created_by_user_id
                : null,
            'created_by_name' => $row->created_by_name !== null
                ? (string) $row->created_by_name
                : null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
     */
    private function listNonRecurring(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $month = $this->resolveMonth((string) ($query['month'] ?? ''));
        $segmentFilter = $this->resolveSegmentFilter($query);

        // Agrupa por concepto de factura + segmento (evita duplicar montos con JOIN a ítems).
        $builder = Capsule::table('invoices as i')
            ->leftJoin('segments as s', 's.id', '=', 'i.segment_id')
            ->whereIn('i.invoice_type', ['one_time', 'consumption'])
            ->where('i.status', '!=', 'cancelled')
            ->whereRaw("DATE_FORMAT(i.issue_date, '%Y-%m') = ?", [$month])
            ->groupBy(
                'i.concept',
                'i.invoice_type',
                'i.segment_id',
                's.name',
            );

        if ($segmentFilter !== null) {
            $builder->where('i.segment_id', $segmentFilter);
        }

        $rows = (clone $builder)
            ->selectRaw(
                "0 as service_id, "
                . "i.concept as service_name, "
                . "i.invoice_type, "
                . "i.segment_id, s.name as segment_name, "
                . "? as month, "
                . "COUNT(i.id) as operations_count, "
                . "SUM(i.subtotal) as billed_amount_mxn",
                [$month],
            )
            ->orderByDesc('billed_amount_mxn')
            ->get();

        // RF-2.8 / Pág. 12 y 17: unidades regaladas (promotion) del mes por segmento+servicio.
        $giftedByKey = $this->giftedUnitsBySegmentService($month, $segmentFilter);
        $giftedBySegment = [];
        foreach ($giftedByKey as $key => $units) {
            $segPart = (int) explode(':', (string) $key)[0];
            $giftedBySegment[$segPart] = ($giftedBySegment[$segPart] ?? 0) + $units;
        }

        $all = [];
        foreach ($rows as $row) {
            $segId = $row->segment_id !== null ? (int) $row->segment_id : null;
            $svcId = (int) $row->service_id;
            $gifted = 0;
            if ($segId !== null) {
                $exactKey = $segId . ':' . $svcId;
                if ($svcId > 0 && isset($giftedByKey[$exactKey])) {
                    $gifted = (int) $giftedByKey[$exactKey];
                } else {
                    // Sin service_id en la factura: asigna el total del segmento (una sola vez
                    // se repartirá al primer renglón del segmento en la lista paginada).
                    $gifted = (int) ($giftedBySegment[$segId] ?? 0);
                    unset($giftedBySegment[$segId]);
                }
            }
            $all[] = [
                'service_id' => $svcId,
                'service_name' => (string) $row->service_name,
                'invoice_type' => (string) ($row->invoice_type ?? 'one_time'),
                'segment_id' => $segId,
                'segment_name' => $row->segment_name !== null ? (string) $row->segment_name : null,
                'month' => (string) $row->month,
                'operations_count' => (int) $row->operations_count,
                'billed_amount_mxn' => FinancialCalculator::normalizeMoney(
                    (string) ($row->billed_amount_mxn ?? '0'),
                ),
                'gifted_units' => $gifted,
            ];
        }

        $total = count($all);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($all, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) max(1, (int) ceil($total / max(1, $perPage))),
            'month' => $month,
        ];
    }

    /**
     * Suma unidades regaladas (balance_movements.movement_type = promotion)
     * del mes en America/Mexico_City, indexadas por "segment_id:service_id".
     *
     * @return array<string, int>
     */
    private function giftedUnitsBySegmentService(string $month, ?int $segmentFilter): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [];
        }
        [$year, $mon] = array_map('intval', explode('-', $month));
        $tz = new \DateTimeZone('America/Mexico_City');
        $startMx = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $mon), $tz);
        $endMx = $startMx->modify('last day of this month')->setTime(23, 59, 59);
        $startUtc = $startMx->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc = $endMx->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $builder = Capsule::table('balance_movements as bm')
            ->join('balances as b', 'b.id', '=', 'bm.balance_id')
            ->join('contracts as c', 'c.id', '=', 'b.contract_id')
            ->where('bm.movement_type', 'promotion')
            ->where('bm.units', '>', 0)
            ->whereBetween('bm.created_at', [$startUtc, $endUtc])
            ->groupBy('c.segment_id', 'b.service_id')
            ->selectRaw('c.segment_id, b.service_id, SUM(bm.units) as gifted_units');

        if ($segmentFilter !== null) {
            $builder->where('c.segment_id', $segmentFilter);
        }

        $map = [];
        foreach ($builder->get() as $row) {
            $key = (int) $row->segment_id . ':' . (int) $row->service_id;
            $map[$key] = (int) $row->gifted_units;
        }

        return $map;
    }

    /**
     * Reporte unificado de excepciones (movements + service_requests) — RF-11.5.
     *
     * @param array<string, mixed> $query
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
     */
    private function listExceptions(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $from = trim((string) ($query['from'] ?? ''));
        $to = trim((string) ($query['to'] ?? ''));
        $segmentFilter = $this->resolveSegmentFilter($query);

        $movementsSql = "
            SELECT
                CONCAT('movement-', m.id) AS exception_uid,
                'movement' AS source,
                m.id AS source_id,
                m.created_at AS exception_at,
                u.name AS user_name,
                u.id AS user_id,
                m.excepted_policy AS policy,
                m.movement_type AS action,
                m.reason AS reason,
                m.overdue_balance_at_exception,
                c.folio AS contract_folio,
                cl.name AS client_name,
                sg.name AS segment_name,
                c.segment_id AS segment_id
            FROM movements m
            LEFT JOIN users u ON u.id = m.user_id
            LEFT JOIN contracts c ON c.id = m.contract_id
            LEFT JOIN clients cl ON cl.id = c.client_id
            LEFT JOIN segments sg ON sg.id = c.segment_id
            WHERE m.has_exception = 1
        ";

        $requestsSql = "
            SELECT
                CONCAT('request-', sr.id) AS exception_uid,
                'service_request' AS source,
                sr.id AS source_id,
                COALESCE(sr.resolved_at, sr.updated_at, sr.created_at) AS exception_at,
                COALESCE(u.name, sr.requested_by_name) AS user_name,
                COALESCE(sr.resolved_by_user_id, sr.requested_by_user_id) AS user_id,
                sr.exception_policy AS policy,
                sr.request_type AS action,
                sr.resolution_notes AS reason,
                sr.overdue_balance_at_exception,
                c.folio AS contract_folio,
                cl.name AS client_name,
                sg.name AS segment_name,
                c.segment_id AS segment_id
            FROM service_requests sr
            LEFT JOIN users u ON u.id = COALESCE(sr.resolved_by_user_id, sr.requested_by_user_id)
            LEFT JOIN contracts c ON c.id = sr.contract_id
            LEFT JOIN clients cl ON cl.id = c.client_id
            LEFT JOIN segments sg ON sg.id = c.segment_id
            WHERE sr.with_exception = 1
        ";

        $bindings = [];
        $extraMov = '';
        $extraReq = '';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $extraMov .= ' AND DATE(m.created_at) >= ?';
            $extraReq .= ' AND DATE(COALESCE(sr.resolved_at, sr.updated_at, sr.created_at)) >= ?';
            $bindings[] = $from;
            $bindings[] = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $extraMov .= ' AND DATE(m.created_at) <= ?';
            $extraReq .= ' AND DATE(COALESCE(sr.resolved_at, sr.updated_at, sr.created_at)) <= ?';
            $bindings[] = $to;
            $bindings[] = $to;
        }
        if ($segmentFilter !== null) {
            $extraMov .= ' AND c.segment_id = ?';
            $extraReq .= ' AND c.segment_id = ?';
            $bindings[] = $segmentFilter;
            $bindings[] = $segmentFilter;
        }

        // Reordenar bindings: from/to/segment se aplican a ambos bloques en el mismo orden.
        $movBindings = [];
        $reqBindings = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $movBindings[] = $from;
            $reqBindings[] = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $movBindings[] = $to;
            $reqBindings[] = $to;
        }
        if ($segmentFilter !== null) {
            $movBindings[] = $segmentFilter;
            $reqBindings[] = $segmentFilter;
        }

        $unionSql = '(' . $movementsSql . $extraMov . ') UNION ALL (' . $requestsSql . $extraReq . ')';
        $countRow = Capsule::selectOne(
            'SELECT COUNT(*) AS total FROM (' . $unionSql . ') AS exceptions_union',
            array_merge($movBindings, $reqBindings),
        );
        $total = (int) ($countRow->total ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = Capsule::select(
            'SELECT * FROM (' . $unionSql . ') AS exceptions_union '
            . 'ORDER BY exception_at DESC, source_id DESC LIMIT ? OFFSET ?',
            array_merge($movBindings, $reqBindings, [$perPage, $offset]),
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'exception_uid' => (string) $row->exception_uid,
                'source' => (string) $row->source,
                'source_id' => (int) $row->source_id,
                'date' => (string) $row->exception_at,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_name' => $row->user_name !== null ? (string) $row->user_name : '—',
                'policy' => $row->policy !== null ? (string) $row->policy : null,
                'action' => $row->action !== null ? (string) $row->action : null,
                'reason' => $row->reason !== null ? (string) $row->reason : null,
                'overdue_balance_at_exception' => $row->overdue_balance_at_exception !== null
                    ? FinancialCalculator::normalizeMoney((string) $row->overdue_balance_at_exception)
                    : null,
                'contract_folio' => $row->contract_folio !== null ? (string) $row->contract_folio : null,
                'client_name' => $row->client_name !== null ? (string) $row->client_name : null,
                'segment_id' => $row->segment_id !== null ? (int) $row->segment_id : null,
                'segment_name' => $row->segment_name !== null ? (string) $row->segment_name : null,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    private function resolveMonth(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return $raw;
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function resolveSegmentFilter(array $query): ?int
    {
        $locked = $this->authContext['segment_id'] ?? null;
        $lockedName = (string) ($this->authContext['segment_name'] ?? 'Todos');
        if ($locked !== null && $lockedName !== '' && $lockedName !== 'Todos') {
            return (int) $locked;
        }

        if (isset($query['segment_id']) && $query['segment_id'] !== '' && $query['segment_id'] !== null) {
            $id = (int) $query['segment_id'];

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function loadSegments(?int $segmentFilter): array
    {
        $builder = Capsule::table('segments')->orderBy('id');
        if ($segmentFilter !== null) {
            $builder->where('id', $segmentFilter);
        }

        $out = [];
        foreach ($builder->get(['id', 'name']) as $row) {
            $out[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $out;
    }

    private function isSegmentAllowed(int $segmentId): bool
    {
        $locked = $this->authContext['segment_id'] ?? null;
        $lockedName = (string) ($this->authContext['segment_name'] ?? 'Todos');
        if ($locked === null || $lockedName === '' || $lockedName === 'Todos') {
            return true;
        }

        return (int) $locked === $segmentId;
    }
}
