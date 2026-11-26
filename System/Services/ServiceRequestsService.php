<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\ServiceRequestsModel;
use System\Support\AuditLogger;
use System\Support\FinancialCalculator;
use System\Support\Integrations\OutboxPublisher;
use System\Support\PriceListResolver;
use System\Support\ServiceRequestRules;
use Throwable;

/**
 * Bandeja de Solicitudes — `/api/requests` (Módulo 4 Paso 1).
 * RBAC `requests` + alcance de segmento + BCMath + audit + expansiones vía movements append-only.
 */
final class ServiceRequestsService extends ModelService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    private const MODULE_KEY = 'requests';

    private const STATUSES = ['pending', 'quoted', 'approved', 'rejected', 'cancelled'];

    private const REQUEST_TYPES = ['one_time', 'consumption', 'recurring_expansion', 'plan_change'];

    /** @var array<string, mixed> */
    private array $authContext = [];

    protected function modelClass(): string
    {
        return ServiceRequestsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('contract_id', DataTypes::Integer, true)
            ->setField('notes', DataTypes::String, false)
            ->setField('resolution_notes', DataTypes::String, false)
            ->setField('with_exception', DataTypes::Boolean, false, false);
    }

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $request = RbacAuthHelper::applySegmentFilterToQuery(
            $this->authContext,
            $context->getRequest(),
        );

        $uri = strtolower(trim($context->getUri(), '/'));
        $parts = $uri === '' ? [] : explode('/', $uri);
        $second = isset($parts[1]) ? strtolower((string) $parts[1]) : null;
        $action = isset($parts[2]) ? strtolower((string) $parts[2]) : null;

        // GET /api/requests/pending-count — badge Sidebar en tiempo real.
        if ($method === 'GET' && $second === 'pending-count') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                return Result::fail('Sin permiso para consultar solicitudes.', 403);
            }

            return Result::ok(
                ['pending_count' => $this->countPending($request)],
                'Conteo de pendientes',
            );
        }

        if ($method === 'GET') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                return Result::fail('Sin permiso para consultar solicitudes.', 403);
            }

            if ($id === null) {
                return Result::ok($this->listPaginated($request), 'Solicitudes');
            }

            $row = $this->findEnriched((int) $id, true);
            if ($row === null) {
                return Result::fail('Solicitud no encontrada.', 404);
            }
            if (!$this->isWithinSegmentScope((int) $row['segment_id'])) {
                return Result::fail('Solicitud fuera de tu alcance de segmento.', 403);
            }

            return Result::ok($row, 'Solicitud');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                return Result::fail('Sin permiso para crear solicitudes.', 403);
            }

            return $this->createRequest($request);
        }

        if ($id === null) {
            return Result::fail('Se requiere id de solicitud.', 400);
        }

        $requestId = (int) $id;
        $existing = Capsule::table('service_requests')->where('id', $requestId)->first();
        if ($existing === null) {
            return Result::fail('Solicitud no encontrada.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $existing->segment_id)) {
            return Result::fail('Solicitud fuera de tu alcance de segmento.', 403);
        }

        if ($method === 'POST' && $action === 'quote') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para cotizar solicitudes.', 403);
            }

            return $this->quoteRequest($existing, $request);
        }

        if ($method === 'POST' && $action === 'approve') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para aprobar solicitudes.', 403);
            }

            return $this->approveRequest($existing, $request);
        }

        if ($method === 'POST' && $action === 'reject') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para rechazar solicitudes.', 403);
            }

            return $this->rejectRequest($existing, $request);
        }

        if (
            ($method === 'POST' && $action === 'cancel')
            || ($method === 'DELETE' && $action === null)
        ) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'delete')) {
                return Result::fail('Sin permiso para cancelar solicitudes.', 403);
            }

            return $this->cancelRequest($existing, $request);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int,
     *   kpis: array{
     *     pending_count: int,
     *     quoted_count: int,
     *     approved_month_count: int,
     *     approved_one_time_total_mxn: string
     *   }
     * }
     */
    private function listPaginated(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $builder = $this->baseEnrichedQuery();
        $this->applyListFilters($builder, $query);

        $total = (int) (clone $builder)->count('sr.id');

        $rows = $builder
            ->orderByRaw('COALESCE(sr.updated_at, sr.created_at) DESC, sr.id DESC')
            ->forPage($page, $perPage)
            ->get($this->selectColumns());

        $items = $rows->map(fn (object $row): array => $this->mapListRow($row))->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil(max(1, $total) / $perPage)),
            'kpis' => $this->buildKpis($query),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   pending_count: int,
     *   quoted_count: int,
     *   approved_month_count: int,
     *   approved_one_time_total_mxn: string
     * }
     */
    private function buildKpis(array $query): array
    {
        $base = Capsule::table('service_requests as sr');
        $this->applySegmentScope($base, $query);

        $pending = (int) (clone $base)->where('sr.status', 'pending')->count();
        $quoted = (int) (clone $base)->where('sr.status', 'quoted')->count();

        $monthStart = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC')))
            ->setTime(0, 0, 0)
            ->format('Y-m-d H:i:s');
        $monthEnd = (new \DateTimeImmutable('first day of next month', new \DateTimeZone('UTC')))
            ->setTime(0, 0, 0)
            ->format('Y-m-d H:i:s');

        $approvedMonth = (int) (clone $base)
            ->where('sr.status', 'approved')
            ->whereNotNull('sr.resolved_at')
            ->where('sr.resolved_at', '>=', $monthStart)
            ->where('sr.resolved_at', '<', $monthEnd)
            ->count();

        $oneTimeTotal = (clone $base)
            ->where('sr.status', 'approved')
            ->whereIn('sr.request_type', ['one_time', 'consumption'])
            ->whereNotNull('sr.resolved_at')
            ->where('sr.resolved_at', '>=', $monthStart)
            ->where('sr.resolved_at', '<', $monthEnd)
            ->sum('sr.subtotal');

        return [
            'pending_count' => $pending,
            'quoted_count' => $quoted,
            'approved_month_count' => $approvedMonth,
            'approved_one_time_total_mxn' => FinancialCalculator::normalizeMoney((string) ($oneTimeTotal ?? '0')),
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function countPending(array $query): int
    {
        $builder = Capsule::table('service_requests as sr')->where('sr.status', 'pending');
        $this->applySegmentScope($builder, $query);

        return (int) $builder->count();
    }

    private function baseEnrichedQuery(): Builder
    {
        // Una sola factura activa por solicitud (evita duplicar filas en el listado).
        $activeInvoicePick = Capsule::table('invoices')
            ->selectRaw('request_id, MAX(id) as id')
            ->whereNotNull('request_id')
            ->where('status', '!=', 'cancelled')
            ->groupBy('request_id');

        return Capsule::table('service_requests as sr')
            ->leftJoin('clients as cl', 'cl.id', '=', 'sr.client_id')
            ->leftJoin('contracts as c', 'c.id', '=', 'sr.contract_id')
            ->leftJoin('segments as s', 's.id', '=', 'sr.segment_id')
            ->leftJoin('issuers as i', 'i.id', '=', 'sr.issuer_id')
            ->leftJoin('collection_statuses as cs', 'cs.contract_id', '=', 'sr.contract_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'sr.requested_by_user_id')
            ->leftJoin('users as vu', 'vu.id', '=', 'sr.resolved_by_user_id')
            ->leftJoinSub($activeInvoicePick, 'inv_pick', 'inv_pick.request_id', '=', 'sr.id')
            ->leftJoin('invoices as inv', 'inv.id', '=', 'inv_pick.id');
    }

    /**
     * @return list<string>
     */
    private function selectColumns(): array
    {
        return [
            'sr.id',
            'sr.folio',
            'sr.contract_id',
            'sr.client_id',
            'sr.segment_id',
            'sr.issuer_id',
            'sr.origin',
            'sr.request_type',
            'sr.portal_request_type',
            'sr.status',
            'sr.currency',
            'sr.subtotal',
            'sr.mrr_impact_mxn',
            'sr.notes',
            'sr.resolution_notes',
            'sr.with_exception',
            'sr.exception_policy',
            'sr.requested_by_user_id',
            'sr.requested_by_name',
            'sr.resolved_by_user_id',
            'sr.resolved_at',
            'sr.created_at',
            'sr.updated_at',
            'cl.name as client_name',
            'cl.rfc as client_rfc',
            'c.folio as contract_folio',
            'c.status as contract_status',
            'c.price_list_id as contract_price_list_id',
            'c.mrr_mxn as contract_mrr_mxn',
            'c.frequency as contract_frequency',
            'c.exchange_rate as contract_exchange_rate',
            'c.currency as contract_currency',
            's.name as segment_name',
            'i.name as issuer_name',
            'cs.overdue_balance as collection_overdue_balance',
            'cs.overdue_days as collection_overdue_days',
            'ru.name as requested_by_user_name',
            'vu.name as resolved_by_user_name',
            'inv.id as invoice_id',
            'inv.folio as invoice_folio',
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function applyListFilters(Builder $builder, array $query): void
    {
        $this->applySegmentScope($builder, $query);

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('sr.folio', 'like', $like)
                    ->orWhere('cl.name', 'like', $like)
                    ->orWhere('cl.rfc', 'like', $like)
                    ->orWhere('sr.notes', 'like', $like)
                    ->orWhere('c.folio', 'like', $like);
            });
        }

        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if (in_array($status, self::STATUSES, true)) {
            $builder->where('sr.status', $status);
        }

        $type = strtolower(trim((string) ($query['request_type'] ?? $query['type'] ?? '')));
        if (in_array($type, self::REQUEST_TYPES, true)) {
            $builder->where('sr.request_type', $type);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function applySegmentScope(Builder $builder, array $query): void
    {
        $locked = $this->authContext['segment_id'] ?? null;
        if ($locked !== null) {
            $builder->where('sr.segment_id', (int) $locked);

            return;
        }

        $segmentId = $this->positiveIntOrNull($query['segment_id'] ?? null);
        if ($segmentId !== null) {
            $builder->where('sr.segment_id', $segmentId);
        }
    }

    private function isWithinSegmentScope(int $segmentId): bool
    {
        $locked = $this->authContext['segment_id'] ?? null;
        if ($locked === null) {
            return true;
        }

        return (int) $locked === $segmentId;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapListRow(object $row, bool $withItems = false): array
    {
        $overdueBalance = FinancialCalculator::normalizeMoney(
            (string) ($row->collection_overdue_balance ?? '0'),
        );
        $daysOverdue = (int) ($row->collection_overdue_days ?? 0);
        $isCurrent = bccomp($overdueBalance, '0.00', 2) <= 0 && $daysOverdue <= 0;

        $mapped = [
            'id' => (int) $row->id,
            'folio' => (string) $row->folio,
            'contract_id' => (int) $row->contract_id,
            'contract_folio' => (string) ($row->contract_folio ?? ''),
            'contract_status' => (string) ($row->contract_status ?? ''),
            'contract_mrr_mxn' => FinancialCalculator::normalizeMoney((string) ($row->contract_mrr_mxn ?? '0')),
            'client_id' => (int) $row->client_id,
            'client_name' => (string) ($row->client_name ?? ''),
            'client_rfc' => (string) ($row->client_rfc ?? ''),
            'segment_id' => (int) $row->segment_id,
            'segment_name' => (string) ($row->segment_name ?? ''),
            'issuer_id' => (int) $row->issuer_id,
            'issuer_name' => (string) ($row->issuer_name ?? ''),
            'origin' => (string) $row->origin,
            'request_type' => (string) $row->request_type,
            'portal_request_type' => isset($row->portal_request_type) && $row->portal_request_type !== null
                ? (string) $row->portal_request_type
                : null,
            'status' => (string) $row->status,
            'currency' => (string) $row->currency,
            'subtotal' => FinancialCalculator::normalizeMoney((string) $row->subtotal),
            'mrr_impact_mxn' => FinancialCalculator::normalizeMoney((string) $row->mrr_impact_mxn),
            'notes' => $row->notes !== null ? (string) $row->notes : null,
            'resolution_notes' => $row->resolution_notes !== null ? (string) $row->resolution_notes : null,
            'with_exception' => (bool) $row->with_exception,
            'exception_policy' => $row->exception_policy !== null ? (string) $row->exception_policy : null,
            'requested_by_user_id' => $row->requested_by_user_id !== null ? (int) $row->requested_by_user_id : null,
            'requested_by_name' => $row->requested_by_name !== null
                ? (string) $row->requested_by_name
                : ($row->requested_by_user_name !== null ? (string) $row->requested_by_user_name : null),
            'resolved_by_user_id' => $row->resolved_by_user_id !== null ? (int) $row->resolved_by_user_id : null,
            'resolved_by_name' => $row->resolved_by_user_name !== null ? (string) $row->resolved_by_user_name : null,
            'resolved_at' => $row->resolved_at !== null ? (string) $row->resolved_at : null,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
            'contract_is_current' => $isCurrent,
            'contract_overdue_balance' => $overdueBalance,
            'contract_days_overdue' => $daysOverdue,
            'invoice_id' => isset($row->invoice_id) && $row->invoice_id !== null
                ? (int) $row->invoice_id
                : null,
            'invoice_folio' => isset($row->invoice_folio) && $row->invoice_folio !== null
                ? (string) $row->invoice_folio
                : null,
            'services_summary' => '',
        ];

        if ($withItems) {
            $items = $this->loadItems((int) $row->id);
            $mapped['items'] = $items;
            $mapped['services_summary'] = $this->summarizeItemNames($items);
            $mapped['price_list_id'] = $row->contract_price_list_id !== null
                ? (int) $row->contract_price_list_id
                : null;
            $mapped['contract_frequency'] = (string) ($row->contract_frequency ?? 'monthly');
            $mapped['contract_exchange_rate'] = (string) ($row->contract_exchange_rate ?? '1.0000');
            $mapped['contract_currency'] = (string) ($row->contract_currency ?? 'MXN');
        } else {
            $mapped['services_summary'] = $this->loadServicesSummary((int) $row->id);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEnriched(int $id, bool $withItems = false): ?array
    {
        $row = $this->baseEnrichedQuery()
            ->where('sr.id', $id)
            ->first($this->selectColumns());

        if ($row === null) {
            return null;
        }

        return $this->mapListRow($row, $withItems);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadItems(int $requestId): array
    {
        $rows = Capsule::table('service_request_items as sri')
            ->leftJoin('services as sv', 'sv.id', '=', 'sri.service_id')
            ->where('sri.service_request_id', $requestId)
            ->orderBy('sri.id')
            ->get([
                'sri.id',
                'sri.service_request_id',
                'sri.service_id',
                'sri.service_type',
                'sri.quantity',
                'sri.unit_price',
                'sri.subtotal',
                'sri.mrr_impact_mxn',
                'sri.created_at',
                'sri.updated_at',
                'sv.name as service_name',
                'sv.code as service_code',
            ]);

        return $rows->map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'service_request_id' => (int) $row->service_request_id,
                'service_id' => (int) $row->service_id,
                'service_name' => (string) ($row->service_name ?? ''),
                'service_code' => (string) ($row->service_code ?? ''),
                'service_type' => (string) $row->service_type,
                'quantity' => (int) $row->quantity,
                'unit_price' => FinancialCalculator::normalizeMoney((string) $row->unit_price),
                'subtotal' => FinancialCalculator::normalizeMoney((string) $row->subtotal),
                'mrr_impact_mxn' => FinancialCalculator::normalizeMoney((string) $row->mrr_impact_mxn),
                'created_at' => (string) $row->created_at,
                'updated_at' => (string) $row->updated_at,
            ];
        })->all();
    }

    private function loadServicesSummary(int $requestId): string
    {
        $names = Capsule::table('service_request_items as sri')
            ->leftJoin('services as sv', 'sv.id', '=', 'sri.service_id')
            ->where('sri.service_request_id', $requestId)
            ->orderBy('sri.id')
            ->pluck('sv.name')
            ->filter()
            ->map(static fn ($n): string => (string) $n)
            ->all();

        if ($names === []) {
            return '';
        }

        return implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? '…' : '');
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function summarizeItemNames(array $items): string
    {
        $names = array_values(array_filter(array_map(
            static fn (array $i): string => trim((string) ($i['service_name'] ?? '')),
            $items,
        )));

        if ($names === []) {
            return '';
        }

        return implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? '…' : '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createRequest(array $payload): Result
    {
        $contractId = $this->positiveIntOrNull($payload['contract_id'] ?? null);
        if ($contractId === null) {
            return Result::fail('contract_id es obligatorio.', 422);
        }

        $rawItems = $payload['items'] ?? null;
        if (!is_array($rawItems) || $rawItems === []) {
            return Result::fail('Debes incluir al menos un servicio en items.', 422);
        }

        $notes = trim((string) ($payload['notes'] ?? ''));
        $notes = $notes !== '' ? mb_substr($notes, 0, 2000) : null;

        try {
            $prepared = $this->prepareItemsForContract($contractId, $rawItems);
        } catch (InvalidArgumentException $e) {
            return Result::fail($e->getMessage(), 422);
        }

        $contract = $prepared['contract'];
        if (!$this->isWithinSegmentScope((int) $contract->segment_id)) {
            return Result::fail('Contrato fuera de tu alcance de segmento.', 403);
        }

        $summary = ServiceRequestRules::summarizeRequest($prepared['lines']);
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $userName = (string) ($this->authContext['name'] ?? $this->authContext['user_name'] ?? '');
        $nowUtc = gmdate('Y-m-d H:i:s');

        try {
            $newId = (int) Capsule::connection()->transaction(function () use (
                $contract,
                $summary,
                $prepared,
                $notes,
                $userId,
                $userName,
                $nowUtc,
            ): int {
                $folio = self::nextFolio();
                $requestId = (int) Capsule::table('service_requests')->insertGetId([
                    'folio' => $folio,
                    'contract_id' => (int) $contract->id,
                    'client_id' => (int) $contract->client_id,
                    'segment_id' => (int) $contract->segment_id,
                    'issuer_id' => (int) $contract->issuer_id,
                    'origin' => 'internal',
                    'request_type' => $summary['request_type'],
                    'status' => 'pending',
                    'currency' => in_array((string) $contract->currency, ['MXN', 'USD'], true)
                        ? (string) $contract->currency
                        : 'MXN',
                    'subtotal' => $summary['subtotal'],
                    'mrr_impact_mxn' => $summary['mrr_impact_mxn'],
                    'notes' => $notes,
                    'resolution_notes' => null,
                    'with_exception' => 0,
                    'exception_policy' => null,
                    'requested_by_user_id' => $userId > 0 ? $userId : null,
                    'requested_by_name' => $userName !== '' ? mb_substr($userName, 0, 120) : null,
                    'resolved_by_user_id' => null,
                    'resolved_at' => null,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);

                foreach ($prepared['lines'] as $line) {
                    Capsule::table('service_request_items')->insert([
                        'service_request_id' => $requestId,
                        'service_id' => (int) $line['service_id'],
                        'service_type' => (string) $line['service_type'],
                        'quantity' => (int) $line['quantity'],
                        'unit_price' => (string) $line['unit_price'],
                        'subtotal' => (string) $line['subtotal'],
                        'mrr_impact_mxn' => (string) $line['mrr_impact_mxn'],
                        'created_at' => $nowUtc,
                        'updated_at' => $nowUtc,
                    ]);
                }

                return $requestId;
            });
        } catch (Throwable $e) {
            return Result::fail('No se pudo crear la solicitud: ' . $e->getMessage(), 500);
        }

        $enriched = $this->findEnriched($newId, true) ?? ['id' => $newId];
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'service_requests',
            (string) $newId,
            'create',
            'Se creó la solicitud ' . ($enriched['folio'] ?? ('#' . $newId)),
            null,
            [
                'folio' => $enriched['folio'] ?? null,
                'request_type' => $summary['request_type'],
                'subtotal' => $summary['subtotal'],
                'mrr_impact_mxn' => $summary['mrr_impact_mxn'],
                'contract_id' => (int) $contract->id,
            ],
        );

        return Result::ok($enriched, 'Solicitud creada', 201);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function quoteRequest(object $existing, array $payload): Result
    {
        if (!in_array((string) $existing->status, ['pending', 'quoted'], true)) {
            return Result::fail('Solo se pueden cotizar solicitudes pendientes o ya cotizadas.', 422);
        }

        $resolutionNotes = trim((string) ($payload['resolution_notes'] ?? $payload['notes'] ?? ''));
        $rawItems = $payload['items'] ?? null;
        $nowUtc = gmdate('Y-m-d H:i:s');

        try {
            Capsule::connection()->transaction(function () use (
                $existing,
                $rawItems,
                $resolutionNotes,
                $nowUtc,
            ): void {
                $lines = null;
                $summary = null;
                if (is_array($rawItems) && $rawItems !== []) {
                    $prepared = $this->prepareItemsForContract((int) $existing->contract_id, $rawItems);
                    $lines = $prepared['lines'];
                    $summary = ServiceRequestRules::summarizeRequest($lines);

                    Capsule::table('service_request_items')
                        ->where('service_request_id', (int) $existing->id)
                        ->delete();

                    foreach ($lines as $line) {
                        Capsule::table('service_request_items')->insert([
                            'service_request_id' => (int) $existing->id,
                            'service_id' => (int) $line['service_id'],
                            'service_type' => (string) $line['service_type'],
                            'quantity' => (int) $line['quantity'],
                            'unit_price' => (string) $line['unit_price'],
                            'subtotal' => (string) $line['subtotal'],
                            'mrr_impact_mxn' => (string) $line['mrr_impact_mxn'],
                            'created_at' => $nowUtc,
                            'updated_at' => $nowUtc,
                        ]);
                    }
                }

                $update = [
                    'status' => 'quoted',
                    'updated_at' => $nowUtc,
                ];
                if ($resolutionNotes !== '') {
                    $update['resolution_notes'] = mb_substr($resolutionNotes, 0, 2000);
                }
                if ($summary !== null) {
                    $update['subtotal'] = $summary['subtotal'];
                    $update['mrr_impact_mxn'] = $summary['mrr_impact_mxn'];
                    $update['request_type'] = $summary['request_type'];
                }

                Capsule::table('service_requests')->where('id', (int) $existing->id)->update($update);
            });
        } catch (InvalidArgumentException $e) {
            return Result::fail($e->getMessage(), 422);
        } catch (Throwable $e) {
            return Result::fail('No se pudo cotizar la solicitud: ' . $e->getMessage(), 500);
        }

        $enriched = $this->findEnriched((int) $existing->id, true) ?? ['id' => (int) $existing->id];
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'service_requests',
            (string) $existing->id,
            'status_change',
            'Se cotizó la solicitud ' . (string) $existing->folio,
            ['status' => (string) $existing->status],
            [
                'status' => 'quoted',
                'subtotal' => $enriched['subtotal'] ?? null,
                'resolution_notes' => $enriched['resolution_notes'] ?? null,
            ],
        );

        return Result::ok($enriched, 'Solicitud cotizada');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function approveRequest(object $existing, array $payload): Result
    {
        if (!in_array((string) $existing->status, ['pending', 'quoted'], true)) {
            return Result::fail('Solo se pueden aprobar solicitudes pendientes o cotizadas.', 422);
        }

        $withException = filter_var($payload['with_exception'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $resolutionNotes = trim((string) ($payload['resolution_notes'] ?? ''));
        $collection = $this->loadCollectionStatus((int) $existing->contract_id);

        // RF-11.4: permiso independiente can_authorize_exceptions (consulta MySQL).
        $authGate = \System\Support\ExceptionAuthorization::assertCanAuthorize(
            $this->authContext,
            $withException,
            $resolutionNotes,
            (int) $existing->contract_id,
        );
        if (!$authGate['allowed']) {
            $code = $authGate['message'] === \System\Support\ExceptionAuthorization::DENIED_MESSAGE
                ? 403
                : 422;

            return Result::fail((string) $authGate['message'], $code);
        }

        $gate = ServiceRequestRules::evaluateCollectionGate(
            $collection['overdue_balance'],
            $collection['days_overdue'],
            $withException,
            $resolutionNotes,
        );
        if (!$gate['allowed']) {
            return Result::fail((string) $gate['message'], 422);
        }

        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $nowUtc = gmdate('Y-m-d H:i:s');
        $requestType = (string) $existing->request_type;
        $exceptionPolicy = $gate['exception_policy'];
        $overdueAtException = $withException && $exceptionPolicy !== null
            ? $authGate['overdue_balance']
            : null;

        try {
            Capsule::connection()->transaction(function () use (
                $existing,
                $requestType,
                $withException,
                $exceptionPolicy,
                $resolutionNotes,
                $overdueAtException,
                $userId,
                $nowUtc,
            ): void {
                $locked = Capsule::table('service_requests')
                    ->where('id', (int) $existing->id)
                    ->lockForUpdate()
                    ->first();
                if ($locked === null) {
                    throw new \RuntimeException('Solicitud no encontrada.');
                }
                if (!in_array((string) $locked->status, ['pending', 'quoted'], true)) {
                    throw new \RuntimeException('La solicitud ya no está en estado aprobable.');
                }

                if (ServiceRequestRules::approvalTouchesContractMrr($requestType)) {
                    $this->applyRecurringExpansionFromRequest(
                        $locked,
                        $withException,
                        $exceptionPolicy,
                        $resolutionNotes,
                        $userId,
                        $nowUtc,
                        $overdueAtException,
                    );

                    // RF-8.4: el ajuste recurrente se propaga a ERP Master vía outbox transaccional.
                    OutboxPublisher::contractUsersAdjustment(
                        (int) $locked->contract_id,
                        'service_request_approved',
                        'adjust_users:request:' . (int) $locked->id,
                        (int) $locked->id,
                    );
                }

                // Puente Cobranza (M4 Paso 2): one_time / consumption → invoice FAC-XXXX.
                (new CollectionsService())->issueInvoiceFromApprovedRequest($locked, $userId, $nowUtc);

                // Bloque B: solicitudes de consumo acreditan bolsa prepaid.
                if ((string) $requestType === 'consumption') {
                    \System\Support\BalanceEngine::creditPrepaidFromApprovedRequest(
                        $locked,
                        $userId,
                    );
                }

                Capsule::table('service_requests')->where('id', (int) $locked->id)->update([
                    'status' => 'approved',
                    'with_exception' => $withException && $exceptionPolicy !== null ? 1 : 0,
                    'exception_policy' => $withException ? $exceptionPolicy : null,
                    'overdue_balance_at_exception' => $overdueAtException,
                    'resolution_notes' => $resolutionNotes !== ''
                        ? mb_substr($resolutionNotes, 0, 2000)
                        : ($locked->resolution_notes ?? null),
                    'resolved_by_user_id' => $userId > 0 ? $userId : null,
                    'resolved_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
            });
        } catch (Throwable $e) {
            $code = str_contains($e->getMessage(), 'saldo vencido') ? 422 : 422;

            return Result::fail($e->getMessage(), $code);
        }

        $enriched = $this->findEnriched((int) $existing->id, true) ?? ['id' => (int) $existing->id];
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'service_requests',
            (string) $existing->id,
            'status_change',
            'Se aprobó la solicitud ' . (string) $existing->folio
                . ($withException ? ' (con excepción de cartera)' : ''),
            [
                'status' => (string) $existing->status,
                'mrr_impact_mxn' => FinancialCalculator::normalizeMoney((string) $existing->mrr_impact_mxn),
            ],
            [
                'status' => 'approved',
                'with_exception' => $withException,
                'exception_policy' => $exceptionPolicy,
                'request_type' => $requestType,
                'mrr_impact_mxn' => $enriched['mrr_impact_mxn'] ?? null,
                'resolution_notes' => $enriched['resolution_notes'] ?? null,
            ],
        );

        return Result::ok($enriched, 'Solicitud aprobada');
    }

    /**
     * Expansión recurrente: contract_lines + movements (request_id) + monthly_mrr + contracts.mrr_mxn.
     */
    private function applyRecurringExpansionFromRequest(
        object $request,
        bool $withException,
        ?string $exceptionPolicy,
        string $resolutionNotes,
        int $userId,
        string $nowUtc,
        ?string $overdueBalanceAtException = null,
    ): void {
        $contract = Capsule::table('contracts')->where('id', (int) $request->contract_id)->lockForUpdate()->first();
        if ($contract === null) {
            throw new \RuntimeException('Contrato no encontrado.');
        }
        if ((string) $contract->status !== 'active') {
            throw new \RuntimeException('El contrato debe estar activo para expandir.');
        }

        $priceListId = $contract->price_list_id !== null ? (int) $contract->price_list_id : null;
        if ($priceListId === null) {
            throw new \RuntimeException('El contrato no tiene lista de precios asociada.');
        }

        $items = Capsule::table('service_request_items')
            ->where('service_request_id', (int) $request->id)
            ->orderBy('id')
            ->get();

        // Split mixto (RF): solo recurring → contract_lines / movements / MRR.
        // one_time y consumption quedan aprobados en la solicitud (facturación) con mrr=0.00.
        $recurringItems = $items->filter(static function (object $item): bool {
            return ServiceRequestRules::normalizeItemServiceType((string) $item->service_type) === 'recurring';
        });
        // Certifica con el helper puro que el tramo no recurrente aporta 0.00 al MRR.
        $partition = ServiceRequestRules::partitionMixedRequestLines(
            $items->map(static fn (object $item): array => [
                'service_type' => (string) $item->service_type,
                'subtotal' => (string) $item->subtotal,
                'mrr_impact_mxn' => (string) $item->mrr_impact_mxn,
            ])->all(),
        );
        if (bccomp($partition['non_recurring_mrr_impact_mxn'], '0.00', 2) !== 0) {
            throw new \RuntimeException('Los cargos únicos/consumo no deben aportar MRR.');
        }

        if ($recurringItems->isEmpty()) {
            throw new \RuntimeException('No hay líneas recurrentes para expandir el contrato.');
        }

        // Reconcilia MRR del contrato vs suma de líneas (evita deltas fantasma tipo $400 vs $395).
        $linesMrrBefore = $this->sumActiveRecurringMrrForContract((int) $contract->id);
        $mrrBefore = FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn);
        if (bccomp($linesMrrBefore, $mrrBefore, 2) !== 0) {
            Capsule::table('contracts')->where('id', (int) $contract->id)->update([
                'mrr_mxn' => $linesMrrBefore,
                'updated_at' => $nowUtc,
            ]);
            $mrrBefore = $linesMrrBefore;
        }

        $exchangeRate = (string) $contract->exchange_rate;
        $frequency = (string) $contract->frequency;
        $addedMrr = '0.00';
        $addedSubtotal = '0.00';
        $firstLineId = null;
        $firstServiceId = null;
        $qtyAfter = '0.00';

        foreach ($recurringItems as $item) {
            $serviceId = (int) $item->service_id;
            $quantity = max(1, (int) $item->quantity);
            $frozenSubtotal = FinancialCalculator::normalizeMoney((string) $item->subtotal);
            $frozenMrr = FinancialCalculator::normalizeMoney((string) $item->mrr_impact_mxn);
            $unitPrice = FinancialCalculator::normalizeMoney((string) $item->unit_price);

            $service = Capsule::table('services')
                ->select([
                    'id',
                    'name',
                    'service_type',
                    'is_active',
                    'allow_package_conversion',
                    'package_service_id',
                    'package_factor',
                ])
                ->where('id', $serviceId)
                ->first();
            if ($service === null || !(bool) $service->is_active) {
                throw new \RuntimeException('Servicio recurrente no encontrado o inactivo.');
            }

            // Re-expande RF-2 solo para estructura (paquete vs sueltos).
            // Si el total recalculado difiere del congelado en la solicitud, se honra el congelado
            // (evita desfaces tipo $400 subtotal cotizado vs $395 al re-preciar en approve).
            $allowPkg = (bool) $service->allow_package_conversion;
            $packageServiceId = $service->package_service_id !== null ? (int) $service->package_service_id : null;
            $packageFactor = max(0, (int) ($service->package_factor ?? 0));
            $packageName = '';
            $packageUnitPrice = '0.00';
            if ($allowPkg && $packageServiceId !== null && $packageFactor >= 2) {
                $pkgService = Capsule::table('services')
                    ->select(['id', 'name', 'is_active'])
                    ->where('id', $packageServiceId)
                    ->first();
                if ($pkgService !== null && (bool) $pkgService->is_active) {
                    $packageName = (string) $pkgService->name;
                    try {
                        $packageUnitPrice = $this->resolveListUnitPrice(
                            $priceListId,
                            $packageServiceId,
                            $frequency,
                            1,
                            'recurring',
                        );
                    } catch (InvalidArgumentException) {
                        $allowPkg = false;
                    }
                } else {
                    $allowPkg = false;
                }
            }

            $priced = ServiceRequestRules::priceLine(
                $serviceId,
                (string) $service->name,
                'recurring',
                $frequency,
                $quantity,
                $unitPrice,
                $exchangeRate,
                $allowPkg,
                $packageServiceId,
                $packageName,
                $packageFactor,
                $packageUnitPrice,
            );

            $useFrozen = bccomp($priced['mrr_impact_mxn'], $frozenMrr, 2) !== 0
                || bccomp($priced['subtotal'], $frozenSubtotal, 2) !== 0;

            if ($useFrozen || $priced['expanded_lines'] === []) {
                // Una sola línea con montos congelados de la solicitud (fuente de verdad cotizada).
                $freq = $frequency === 'annual' ? 'annual' : 'monthly';
                $insertedId = (int) Capsule::table('contract_lines')->insertGetId([
                    'contract_id' => (int) $contract->id,
                    'service_id' => $serviceId,
                    'description' => mb_substr((string) $service->name, 0, 200),
                    'service_type' => 'recurring',
                    'billing_frequency' => $freq,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => '0.00',
                    'subtotal' => $frozenSubtotal,
                    'mrr_mxn' => $frozenMrr,
                    'is_package_converted' => 0,
                    'active' => 1,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                $firstLineId ??= $insertedId;
                $firstServiceId ??= $serviceId;
                $addedMrr = bcadd($addedMrr, $frozenMrr, 2);
                $addedSubtotal = bcadd($addedSubtotal, $frozenSubtotal, 2);
            } else {
                foreach ($priced['expanded_lines'] as $exp) {
                    // Cupón quemado: discount_amount = 0.00 en expansiones.
                    $insertedId = (int) Capsule::table('contract_lines')->insertGetId([
                        'contract_id' => (int) $contract->id,
                        'service_id' => (int) $exp['service_id'],
                        'description' => mb_substr((string) $exp['description'], 0, 200),
                        'service_type' => (string) $exp['service_type'],
                        'billing_frequency' => (string) $exp['billing_frequency'],
                        'quantity' => (int) $exp['quantity'],
                        'unit_price' => (string) $exp['unit_price'],
                        'discount_amount' => '0.00',
                        'subtotal' => (string) $exp['subtotal'],
                        'mrr_mxn' => (string) $exp['mrr_mxn'],
                        'is_package_converted' => !empty($exp['is_package_converted']) ? 1 : 0,
                        'active' => 1,
                        'created_at' => $nowUtc,
                        'updated_at' => $nowUtc,
                    ]);
                    $firstLineId ??= $insertedId;
                    $firstServiceId ??= $serviceId;
                    $addedMrr = bcadd($addedMrr, (string) $exp['mrr_mxn'], 2);
                    $addedSubtotal = bcadd($addedSubtotal, (string) $exp['subtotal'], 2);
                }
            }
            $qtyAfter = bcadd($qtyAfter, number_format($quantity, 2, '.', ''), 2);
        }

        // Fuente de verdad: suma BCMath de líneas activas tras el INSERT.
        $newContractMrr = $this->sumActiveRecurringMrrForContract((int) $contract->id);
        $impact = [
            'mrr_before' => $mrrBefore,
            'mrr_after' => $newContractMrr,
            'mrr_delta_mxn' => bcsub($newContractMrr, $mrrBefore, 2),
        ];
        if (bccomp($impact['mrr_delta_mxn'], '0.00', 2) <= 0) {
            throw new \RuntimeException('La expansión no genera incremento de MRR.');
        }
        // Garantiza igualdad exacta: delta del movimiento = MRR de líneas nuevas.
        if (bccomp($impact['mrr_delta_mxn'], $addedMrr, 2) !== 0) {
            throw new \RuntimeException(
                'Inconsistencia MRR: delta calculado (' . $impact['mrr_delta_mxn']
                . ') ≠ MRR de líneas nuevas (' . $addedMrr . ').',
            );
        }

        Capsule::table('contracts')->where('id', (int) $contract->id)->update([
            'mrr_mxn' => $impact['mrr_after'],
            'updated_at' => $nowUtc,
        ]);

        $reason = $resolutionNotes !== ''
            ? $resolutionNotes
            : ('Aprobación de solicitud ' . (string) $request->folio);
        $effectiveDate = (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');

        $movementPayload = ServiceRequestRules::buildExpansionMovementPayload(
            (int) $contract->id,
            (int) $request->id,
            $impact,
            $effectiveDate,
            $exchangeRate,
            $userId > 0 ? $userId : null,
            $reason,
            $withException && $exceptionPolicy !== null,
            $exceptionPolicy,
            $firstLineId,
            $firstServiceId,
            '0.00',
            $qtyAfter,
            $nowUtc,
            $overdueBalanceAtException,
        );

        (new MovementsService())->recordWithinTransaction($movementPayload);

        // Congela impacto y subtotal al delta/monto real aplicado (coincide con movements).
        Capsule::table('service_requests')->where('id', (int) $request->id)->update([
            'mrr_impact_mxn' => $impact['mrr_delta_mxn'],
            'subtotal' => $addedSubtotal,
            'updated_at' => $nowUtc,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rejectRequest(object $existing, array $payload): Result
    {
        if (!in_array((string) $existing->status, ['pending', 'quoted'], true)) {
            return Result::fail('Solo se pueden rechazar solicitudes pendientes o cotizadas.', 422);
        }

        $resolutionNotes = trim((string) ($payload['resolution_notes'] ?? ''));
        if (mb_strlen($resolutionNotes) < 5) {
            return Result::fail('El motivo de rechazo (resolution_notes) es obligatorio (mínimo 5 caracteres).', 422);
        }

        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $nowUtc = gmdate('Y-m-d H:i:s');
        Capsule::table('service_requests')->where('id', (int) $existing->id)->update([
            'status' => 'rejected',
            'resolution_notes' => mb_substr($resolutionNotes, 0, 2000),
            'resolved_by_user_id' => $userId > 0 ? $userId : null,
            'resolved_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $enriched = $this->findEnriched((int) $existing->id, true) ?? ['id' => (int) $existing->id];
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'service_requests',
            (string) $existing->id,
            'status_change',
            'Se rechazó la solicitud ' . (string) $existing->folio,
            ['status' => (string) $existing->status],
            [
                'status' => 'rejected',
                'resolution_notes' => $resolutionNotes,
            ],
        );

        return Result::ok($enriched, 'Solicitud rechazada');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cancelRequest(object $existing, array $payload): Result
    {
        $gate = ServiceRequestRules::assertCanCancel((string) $existing->status);
        if (!$gate['allowed']) {
            return Result::fail((string) $gate['message'], 422);
        }

        $resolutionNotes = trim((string) ($payload['resolution_notes'] ?? ''));
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $nowUtc = gmdate('Y-m-d H:i:s');
        $notesToStore = $resolutionNotes !== ''
            ? mb_substr($resolutionNotes, 0, 2000)
            : ($existing->resolution_notes ?? null);

        Capsule::table('service_requests')->where('id', (int) $existing->id)->update([
            'status' => 'cancelled',
            'resolution_notes' => $notesToStore,
            'resolved_by_user_id' => $userId > 0 ? $userId : null,
            'resolved_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $enriched = $this->findEnriched((int) $existing->id, true) ?? ['id' => (int) $existing->id];
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'service_requests',
            (string) $existing->id,
            'status_change',
            'Se canceló la solicitud ' . (string) $existing->folio,
            ['status' => (string) $existing->status],
            [
                'status' => 'cancelled',
                'resolution_notes' => $notesToStore,
            ],
        );

        return Result::ok($enriched, 'Solicitud cancelada');
    }

    /**
     * Valida contrato activo + servicios de la lista; resuelve precios con BCMath.
     * Público para que el Portal del Cliente cotice con exactamente las mismas reglas (DRY).
     *
     * @param list<array<string, mixed>> $rawItems
     * @return array{contract:object,lines:list<array<string, mixed>>}
     */
    public function prepareItemsForContract(int $contractId, array $rawItems): array
    {
        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        if ($contract === null) {
            throw new InvalidArgumentException('Contrato no encontrado.');
        }
        if ((string) $contract->status !== 'active') {
            throw new InvalidArgumentException('Solo se pueden crear solicitudes sobre contratos activos.');
        }

        $priceListId = $contract->price_list_id !== null ? (int) $contract->price_list_id : null;
        if ($priceListId === null) {
            throw new InvalidArgumentException('El contrato no tiene lista de precios asociada.');
        }

        $exchangeRate = (string) $contract->exchange_rate;
        $frequency = (string) $contract->frequency;
        $lines = [];

        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Cada ítem debe ser un objeto con service_id y quantity.');
            }
            $serviceId = $this->positiveIntOrNull($raw['service_id'] ?? null);
            $quantity = max(0, (int) ($raw['quantity'] ?? 0));
            if ($serviceId === null || $quantity < 1) {
                throw new InvalidArgumentException('Cada ítem requiere service_id y quantity ≥ 1.');
            }

            $service = Capsule::table('services')
                ->select([
                    'id',
                    'name',
                    'segment_id',
                    'service_type',
                    'is_active',
                    'allow_package_conversion',
                    'package_service_id',
                    'package_factor',
                ])
                ->where('id', $serviceId)
                ->first();
            if ($service === null || !(bool) $service->is_active) {
                throw new InvalidArgumentException("Servicio #{$serviceId} no encontrado o inactivo.");
            }
            if ((int) $service->segment_id !== (int) $contract->segment_id) {
                throw new InvalidArgumentException(
                    "El servicio «{$service->name}» no pertenece al segmento del contrato.",
                );
            }

            $itemType = ServiceRequestRules::normalizeItemServiceType((string) $service->service_type);
            $customPrice = trim((string) ($raw['custom_unit_price'] ?? $raw['unit_price'] ?? ''));
            $hasCustom = $customPrice !== '' && is_numeric($customPrice);

            try {
                $listPrice = $this->resolveListUnitPrice(
                    $priceListId,
                    $serviceId,
                    $frequency,
                    $quantity,
                    $itemType === 'one_time' ? 'single' : $itemType,
                );
                $unitPrice = $listPrice;
            } catch (InvalidArgumentException $e) {
                if ($hasCustom && $itemType === 'one_time') {
                    $unitPrice = FinancialCalculator::normalizeMoney($customPrice);
                } else {
                    throw $e;
                }
            }

            if ($hasCustom && $itemType === 'one_time') {
                $unitPrice = FinancialCalculator::normalizeMoney($customPrice);
            }

            $allowPkg = (bool) $service->allow_package_conversion;
            $packageServiceId = $service->package_service_id !== null ? (int) $service->package_service_id : null;
            $packageFactor = max(0, (int) ($service->package_factor ?? 0));
            $packageName = '';
            $packageUnitPrice = '0.00';
            if ($itemType === 'recurring' && $allowPkg && $packageServiceId !== null && $packageFactor >= 2) {
                $pkgService = Capsule::table('services')
                    ->select(['id', 'name', 'is_active'])
                    ->where('id', $packageServiceId)
                    ->first();
                if ($pkgService !== null && (bool) $pkgService->is_active) {
                    $packageName = (string) $pkgService->name;
                    try {
                        $packageUnitPrice = $this->resolveListUnitPrice(
                            $priceListId,
                            $packageServiceId,
                            $frequency,
                            1,
                            'recurring',
                        );
                    } catch (InvalidArgumentException) {
                        $allowPkg = false;
                    }
                } else {
                    $allowPkg = false;
                }
            }

            $priced = ServiceRequestRules::priceLine(
                $serviceId,
                (string) $service->name,
                $itemType,
                $frequency,
                $quantity,
                $unitPrice,
                $exchangeRate,
                $allowPkg,
                $packageServiceId,
                $packageName,
                $packageFactor,
                $packageUnitPrice,
            );

            $lines[] = [
                'service_id' => $serviceId,
                'service_name' => (string) $service->name,
                'service_type' => $itemType,
                'quantity' => $priced['quantity'],
                'unit_price' => $priced['unit_price'],
                'subtotal' => $priced['subtotal'],
                'mrr_impact_mxn' => $priced['mrr_impact_mxn'],
                'rf2_applied' => $priced['rf2_applied'],
                'expanded_lines' => $priced['expanded_lines'],
            ];
        }

        return ['contract' => $contract, 'lines' => $lines];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function resolveListUnitPrice(
        int $priceListId,
        int $serviceId,
        string $contractFrequency,
        int $quantity,
        string $serviceType = 'recurring',
    ): string {
        return PriceListResolver::resolveUnitPrice($priceListId, $serviceId, $contractFrequency, $quantity, $serviceType);
    }

    /**
     * @return array{overdue_balance:string,days_overdue:int}
     */
    private function loadCollectionStatus(int $contractId): array
    {
        $row = Capsule::table('collection_statuses')->where('contract_id', $contractId)->first();
        if ($row === null) {
            return ['overdue_balance' => '0.00', 'days_overdue' => 0];
        }

        return [
            'overdue_balance' => FinancialCalculator::normalizeMoney((string) $row->overdue_balance),
            'days_overdue' => (int) $row->overdue_days,
        ];
    }

    /** Siguiente folio SOL-XXXX (bloqueo de lectura; invocar dentro de una transacción). */
    public static function nextFolio(): string
    {
        $max = Capsule::table('service_requests')
            ->where('folio', 'like', 'SOL-%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('folio');

        $next = 1;
        if (is_string($max) && preg_match('/SOL-(\d+)/', $max, $m) === 1) {
            $next = (int) $m[1] + 1;
        }

        return sprintf('SOL-%04d', $next);
    }

    private function sumActiveRecurringMrrForContract(int $contractId): string
    {
        $rows = Capsule::table('contract_lines')
            ->where('contract_id', $contractId)
            ->where('active', 1)
            ->where('service_type', 'recurring')
            ->get(['mrr_mxn']);

        $total = '0.00';
        foreach ($rows as $row) {
            $total = bcadd($total, FinancialCalculator::normalizeMoney((string) $row->mrr_mxn), 2);
        }

        return $total;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }
}
