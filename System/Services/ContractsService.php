<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\ContractsModel;
use System\Support\AuditLogger;
use System\Support\ContractLifecycleEngine;
use System\Support\ContractPricingEngine;
use System\Support\FinancialCalculator;
use Throwable;

/**
 * Listado, detalle y motor de ciclo de vida — `/api/contracts` (alias `/api/contratos`).
 * Motor BCMath (RF-2 + promociones + MRR MXN) + bitácora inmutable + auditoría.
 * POST `/api/contracts/{id}/lifecycle` — operaciones expansion…reactivation.
 * POST `/api/contracts/{id}/movements/{movementId}/reverse` — reversión contable RNF-3.
 */
final class ContractsService extends ModelService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    private const MODULE_KEY = 'contracts';

    /** @var array<string, mixed> */
    private array $authContext = [];

    /**
     * Flags RF-11.4/11.5 propagados durante una operación de ciclo de vida.
     *
     * @var array{has_exception:int,excepted_policy:?string,overdue_balance_at_exception:?string}|null
     */
    private ?array $lifecycleExceptionFlags = null;

    protected function modelClass(): string
    {
        return ContractsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('client_id', DataTypes::Integer, true)
            ->setField('instance_id', DataTypes::Integer, false)
            ->setField('price_list_id', DataTypes::Integer, true)
            ->setField('promotion_id', DataTypes::Integer, false)
            ->setField('billing_frequency', DataTypes::String, true)
            ->setField('anniversary_date', DataTypes::Date, true)
            ->setField('credit_days', DataTypes::Integer, false)
            ->setField('stamp_on_issue', DataTypes::Boolean, false, true)
            ->setField('exchange_rate', DataTypes::String, false)
            ->setField('notes', DataTypes::String, false);
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
        // Soporta alias `contratos/{id}/lifecycle` y `contratos/{id}/movements/{mid}/reverse`.
        $action = isset($parts[2]) ? strtolower((string) $parts[2]) : null;

        // POST /api/contracts/{id}/movements/{movementId}/reverse — Reversión contable RNF-3.
        if (
            $method === 'POST'
            && $id !== null
            && $action === 'movements'
            && isset($parts[3], $parts[4])
            && ctype_digit((string) $parts[3])
            && strtolower((string) $parts[4]) === 'reverse'
        ) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para revertir movimientos del contrato.', 403);
            }

            return $this->reverseContractMovement((int) $id, (int) $parts[3], $request);
        }

        // POST /api/contracts/{id}/lifecycle — Motor de ciclo de vida.
        if ($method === 'POST' && $id !== null && $action === 'lifecycle') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para operar el ciclo de vida del contrato.', 403);
            }

            return $this->runLifecycleOperation((int) $id, $request);
        }

        if ($method === 'GET') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                return Result::fail('Sin permiso para consultar contratos.', 403);
            }

            if ($id === null) {
                return Result::ok($this->listPaginated($request), 'Contratos');
            }

            $row = $this->findEnriched((int) $id, true);
            if ($row === null) {
                return Result::fail('Contrato no encontrado.', 404);
            }
            if (!$this->isWithinSegmentScope((int) $row['segment_id'])) {
                return Result::fail('Contrato fuera de tu alcance de segmento.', 403);
            }

            return Result::ok($row, 'Contrato');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                return Result::fail('Sin permiso para crear contratos.', 403);
            }

            return $this->createContractTransactional($request);
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
     *   summary: array{
     *     total_contracts: int,
     *     active_contracts: int,
     *     total_mrr_mxn: string,
     *     suspended_contracts: int
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

        $total = (int) (clone $builder)->count('c.id');

        $rows = $builder
            ->orderByRaw('COALESCE(c.updated_at, c.created_at) DESC')
            ->orderByDesc('c.id')
            ->forPage($page, $perPage)
            ->get($this->selectColumns());

        $items = $rows->map(fn (object $row): array => $this->mapListRow($row))->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil(max(1, $total) / $perPage)),
            'summary' => $this->buildSummary($query),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   total_contracts: int,
     *   active_contracts: int,
     *   total_mrr_mxn: string,
     *   suspended_contracts: int
     * }
     */
    private function buildSummary(array $query): array
    {
        $builder = Capsule::table('contracts as c');
        $this->applySegmentScope($builder, $query);

        $issuerId = $this->positiveIntOrNull($query['issuer_id'] ?? null);
        if ($issuerId !== null) {
            $builder->where('c.issuer_id', $issuerId);
        }

        $row = $builder
            ->selectRaw(
                "COUNT(*) as total_contracts,
                 SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) as active_contracts,
                 COALESCE(SUM(CASE WHEN c.status = 'active' THEN c.mrr_mxn ELSE 0 END), 0) as total_mrr_mxn,
                 SUM(CASE WHEN c.status = 'suspended' THEN 1 ELSE 0 END) as suspended_contracts"
            )
            ->first();

        return [
            'total_contracts' => (int) ($row->total_contracts ?? 0),
            'active_contracts' => (int) ($row->active_contracts ?? 0),
            'total_mrr_mxn' => FinancialCalculator::normalizeMoney((string) ($row->total_mrr_mxn ?? '0')),
            'suspended_contracts' => (int) ($row->suspended_contracts ?? 0),
        ];
    }

    private function baseEnrichedQuery(): Builder
    {
        $linesCount = Capsule::table('contract_lines')
            ->selectRaw('contract_id, COUNT(*) as lines_count')
            ->where('active', 1)
            ->groupBy('contract_id');

        return Capsule::table('contracts as c')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('segments as s', 's.id', '=', 'c.segment_id')
            ->leftJoin('issuers as i', 'i.id', '=', 'c.issuer_id')
            ->leftJoin('instances as ins', 'ins.id', '=', 'c.instance_id')
            ->leftJoin('price_lists as pl', 'pl.id', '=', 'c.price_list_id')
            ->leftJoin('promotions as pr', 'pr.id', '=', 'c.promotion_id')
            ->leftJoin('collection_statuses as cs', 'cs.contract_id', '=', 'c.id')
            ->leftJoinSub($linesCount, 'lc', 'lc.contract_id', '=', 'c.id');
    }

    /**
     * @return list<string>
     */
    private function selectColumns(): array
    {
        return [
            'c.id',
            'c.folio',
            'c.client_id',
            'c.segment_id',
            'c.issuer_id',
            'c.instance_id',
            'c.price_list_id',
            'c.promotion_id',
            'c.frequency',
            'c.anniversary_date',
            'c.currency',
            'c.exchange_rate',
            'c.credit_days',
            'c.stamp_on_issue',
            'c.status',
            'c.mrr_mxn',
            'c.next_cutoff_date',
            'c.origin_contract_id',
            'c.termination_reason',
            'c.notes',
            'c.created_by',
            'c.created_at',
            'c.updated_at',
            'cl.name as client_name',
            'cl.legal_name as client_legal_name',
            'cl.rfc as client_rfc',
            's.name as segment_name',
            'i.name as issuer_name',
            'ins.name as instance_name',
            'pl.name as price_list_name',
            'pr.code as promotion_code',
            'lc.lines_count',
            'cs.overdue_balance as collection_overdue_balance',
            'cs.overdue_days as collection_overdue_days',
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
                $q->where('c.folio', 'like', $like)
                    ->orWhere('cl.name', 'like', $like)
                    ->orWhere('cl.legal_name', 'like', $like)
                    ->orWhere('cl.rfc', 'like', $like);
            });
        }

        $issuerId = $this->positiveIntOrNull($query['issuer_id'] ?? null);
        if ($issuerId !== null) {
            $builder->where('c.issuer_id', $issuerId);
        }

        $frequency = $this->normalizeBillingFrequency((string) ($query['billing_frequency'] ?? $query['frequency'] ?? ''));
        if ($frequency !== null && $frequency !== 'single') {
            $builder->where('c.frequency', $frequency);
        }

        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if (in_array($status, ['active', 'suspended', 'cancelled', 'draft'], true)) {
            $builder->where('c.status', $status);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function applySegmentScope(Builder $builder, array $query): void
    {
        $locked = $this->authContext['segment_id'] ?? null;
        if ($locked !== null) {
            $builder->where('c.segment_id', (int) $locked);

            return;
        }

        $segmentId = $this->positiveIntOrNull($query['segment_id'] ?? null);
        if ($segmentId !== null) {
            $builder->where('c.segment_id', $segmentId);
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
     * Detalle enriquecido del contrato.
     * Con `$fullDetail` incluye líneas (recientes primero) + bitácora `movements`.
     *
     * @return array<string, mixed>|null
     */
    private function findEnriched(int $id, bool $fullDetail = false): ?array
    {
        $row = $this->baseEnrichedQuery()
            ->where('c.id', $id)
            ->first($this->selectColumns());

        if ($row === null) {
            return null;
        }

        $mapped = $this->mapListRow($row);
        $mapped['lines'] = Capsule::table('contract_lines')
            ->where('contract_id', $id)
            ->orderByRaw('COALESCE(updated_at, created_at) DESC')
            ->orderByDesc('id')
            ->get()
            ->map(static function (object $line): array {
                return [
                    'id' => (int) $line->id,
                    'contract_id' => (int) $line->contract_id,
                    'service_id' => (int) $line->service_id,
                    'description' => (string) $line->description,
                    'service_type' => (string) $line->service_type,
                    'billing_frequency' => (string) $line->billing_frequency,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => FinancialCalculator::normalizeMoney((string) $line->unit_price),
                    'discount_amount' => FinancialCalculator::normalizeMoney((string) $line->discount_amount),
                    'subtotal' => FinancialCalculator::normalizeMoney((string) $line->subtotal),
                    'mrr_mxn' => FinancialCalculator::normalizeMoney((string) $line->mrr_mxn),
                    'is_package_converted' => (bool) $line->is_package_converted,
                    'active' => (bool) $line->active,
                    'created_at' => (string) $line->created_at,
                    'updated_at' => (string) $line->updated_at,
                ];
            })
            ->all();

        if ($fullDetail) {
            $mapped['movements'] = $this->listContractMovements($id);
            $mapped['active_lines_count'] = count(array_filter(
                $mapped['lines'],
                static fn (array $l): bool => (bool) $l['active'],
            ));
            $mapped['active_recurring_count'] = count(array_filter(
                $mapped['lines'],
                static fn (array $l): bool => (bool) $l['active'] && $l['service_type'] === 'recurring',
            ));
            $mapped['active_one_time_count'] = count(array_filter(
                $mapped['lines'],
                static fn (array $l): bool => (bool) $l['active'] && $l['service_type'] !== 'recurring',
            ));
        }

        return $mapped;
    }

    /**
     * Bitácora inmutable del contrato (created_at DESC, id DESC) con nombre de usuario.
     *
     * @return list<array<string, mixed>>
     */
    private function listContractMovements(int $contractId): array
    {
        // IDs ya revertidos: existe otro asiento con reversed_movement_id = este id.
        $reversedIds = Capsule::table('movements')
            ->where('contract_id', $contractId)
            ->whereNotNull('reversed_movement_id')
            ->pluck('reversed_movement_id')
            ->map(static fn ($value): int => (int) $value)
            ->all();
        $reversedSet = array_fill_keys($reversedIds, true);

        return Capsule::table('movements as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('service_requests as sr', 'sr.id', '=', 'm.request_id')
            ->where('m.contract_id', $contractId)
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->get([
                'm.id',
                'm.contract_id',
                'm.contract_line_id',
                'm.service_id',
                'm.movement_type',
                'm.mrr_category',
                'm.effective_date',
                'm.quantity_before',
                'm.quantity_after',
                'm.mrr_before',
                'm.mrr_after',
                'm.mrr_delta_mxn',
                'm.exchange_rate',
                'm.user_id',
                'm.request_id',
                'm.reason',
                'm.has_exception',
                'm.reversed_movement_id',
                'm.created_at',
                'u.name as user_name',
                'sr.folio as request_folio',
            ])
            ->map(static function (object $row) use ($reversedSet): array {
                $id = (int) $row->id;

                return [
                    'id' => $id,
                    'contract_id' => (int) $row->contract_id,
                    'contract_line_id' => $row->contract_line_id !== null ? (int) $row->contract_line_id : null,
                    'service_id' => $row->service_id !== null ? (int) $row->service_id : null,
                    'movement_type' => (string) $row->movement_type,
                    'mrr_category' => (string) $row->mrr_category,
                    'effective_date' => (string) $row->effective_date,
                    'quantity_before' => FinancialCalculator::normalizeMoney((string) $row->quantity_before),
                    'quantity_after' => FinancialCalculator::normalizeMoney((string) $row->quantity_after),
                    'mrr_before' => FinancialCalculator::normalizeMoney((string) $row->mrr_before),
                    'mrr_after' => FinancialCalculator::normalizeMoney((string) $row->mrr_after),
                    'mrr_delta_mxn' => FinancialCalculator::normalizeMoney((string) $row->mrr_delta_mxn),
                    'exchange_rate' => (string) $row->exchange_rate,
                    'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                    'user_name' => $row->user_name !== null ? (string) $row->user_name : null,
                    'request_id' => $row->request_id !== null ? (int) $row->request_id : null,
                    'request_folio' => $row->request_folio !== null ? (string) $row->request_folio : null,
                    'reason' => $row->reason !== null ? (string) $row->reason : null,
                    'has_exception' => (bool) $row->has_exception,
                    'reversed_movement_id' => $row->reversed_movement_id !== null
                        ? (int) $row->reversed_movement_id
                        : null,
                    'is_reversed' => isset($reversedSet[$id]),
                    'created_at' => (string) $row->created_at,
                ];
            })
            ->all();
    }

    /**
     * Reversión contable RNF-3 desde el detalle del contrato.
     * Delega el INSERT inmutable + sync MRR a MovementsService y audita el evento.
     *
     * @param array<string, mixed> $request
     */
    private function reverseContractMovement(int $contractId, int $movementId, array $request): Result
    {
        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        if ($contract === null) {
            return Result::fail('Contrato no encontrado.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $contract->segment_id)) {
            return Result::fail('Contrato fuera de tu alcance de segmento.', 403);
        }

        $reason = trim((string) ($request['reason'] ?? ''));
        $effectiveDate = isset($request['effective_date'])
            ? trim((string) $request['effective_date'])
            : '';
        if ($effectiveDate === '') {
            $effectiveDate = $this->todayInMexicoCity();
        }

        $userId = (int) ($this->authContext['user_id'] ?? $request['user_id'] ?? 0);
        if ($userId < 1) {
            return Result::fail('Sesión inválida: no se pudo resolver el usuario autenticado.', 401);
        }

        $mrrBefore = FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn);

        $movements = new MovementsService();
        $result = $movements->createReversalMovement(
            $movementId,
            $userId,
            $reason,
            $effectiveDate,
            $contractId,
        );

        if (!$result->isSuccess()) {
            return $result;
        }

        $enriched = $this->findEnriched($contractId, true) ?? ['id' => $contractId];
        $movementData = is_array($result->getData()) ? $result->getData() : [];

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'contracts',
            (string) $contractId,
            'status_change',
            'Reversión contable del movimiento #' . $movementId
                . ' en contrato ' . ($enriched['folio'] ?? ('#' . $contractId)),
            [
                'mrr_mxn' => $mrrBefore,
                'movement_id' => $movementId,
            ],
            [
                'operation' => 'movement_reversal',
                'original_movement_id' => $movementId,
                'reversal_movement_id' => $movementData['id'] ?? null,
                'mrr_mxn' => $enriched['mrr_mxn'] ?? null,
                'mrr_delta_mxn' => $movementData['mrr_delta_mxn'] ?? null,
                'reason' => $reason,
                'effective_date' => $effectiveDate,
            ],
        );

        return Result::ok($enriched, 'Reversión contable registrada');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapListRow(object $row): array
    {
        $frequency = (string) $row->frequency;

        return [
            'id' => (int) $row->id,
            'folio' => (string) $row->folio,
            'client_id' => (int) $row->client_id,
            'client_name' => (string) ($row->client_name ?? ''),
            'client_legal_name' => (string) ($row->client_legal_name ?? ''),
            'client_rfc' => (string) ($row->client_rfc ?? ''),
            'segment_id' => (int) $row->segment_id,
            'segment_name' => (string) ($row->segment_name ?? ''),
            'issuer_id' => (int) $row->issuer_id,
            'issuer_name' => (string) ($row->issuer_name ?? ''),
            'instance_id' => $row->instance_id !== null ? (int) $row->instance_id : null,
            'instance_name' => $row->instance_name !== null ? (string) $row->instance_name : null,
            'price_list_id' => $row->price_list_id !== null ? (int) $row->price_list_id : null,
            'price_list_name' => $row->price_list_name !== null ? (string) $row->price_list_name : null,
            'promotion_id' => $row->promotion_id !== null ? (int) $row->promotion_id : null,
            'promotion_code' => $row->promotion_code !== null ? (string) $row->promotion_code : null,
            'billing_frequency' => $frequency,
            'frequency' => $frequency,
            'anniversary_date' => (string) $row->anniversary_date,
            'currency' => (string) $row->currency,
            'exchange_rate' => (string) $row->exchange_rate,
            'credit_days' => (int) $row->credit_days,
            'stamp_on_issue' => (bool) $row->stamp_on_issue,
            'status' => (string) $row->status,
            'mrr_mxn' => FinancialCalculator::normalizeMoney((string) ($row->mrr_mxn ?? '0')),
            'next_billing_date' => $row->next_cutoff_date !== null ? (string) $row->next_cutoff_date : null,
            'next_cutoff_date' => $row->next_cutoff_date !== null ? (string) $row->next_cutoff_date : null,
            'origin_contract_id' => $row->origin_contract_id !== null ? (int) $row->origin_contract_id : null,
            'termination_reason' => $row->termination_reason !== null ? (string) $row->termination_reason : null,
            'notes' => $row->notes !== null ? (string) $row->notes : null,
            'lines_count' => (int) ($row->lines_count ?? 0),
            'created_by' => $row->created_by !== null ? (int) $row->created_by : null,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
            'contract_is_current' => bccomp(
                FinancialCalculator::normalizeMoney((string) ($row->collection_overdue_balance ?? '0')),
                '0.00',
                2,
            ) <= 0 && (int) ($row->collection_overdue_days ?? 0) <= 0,
            'contract_overdue_balance' => FinancialCalculator::normalizeMoney(
                (string) ($row->collection_overdue_balance ?? '0'),
            ),
            'contract_days_overdue' => (int) ($row->collection_overdue_days ?? 0),
        ];
    }

    /**
     * Alta atómica: folio + contracts + contract_lines + movements + monthly_mrr + audit + promo uses.
     *
     * @param array<string, mixed> $request
     */
    private function createContractTransactional(array $request): Result
    {
        try {
            $prepared = $this->prepareCreatePayload($request);
            if ($prepared instanceof Result) {
                return $prepared;
            }

            $created = Capsule::connection()->transaction(function () use ($prepared) {
                $folio = $this->nextFolio();
                $nowUtc = gmdate('Y-m-d H:i:s');

                $contractId = (int) Capsule::table('contracts')->insertGetId([
                    'folio' => $folio,
                    'client_id' => $prepared['client_id'],
                    'segment_id' => $prepared['segment_id'],
                    'issuer_id' => $prepared['issuer_id'],
                    'instance_id' => $prepared['instance_id'],
                    'price_list_id' => $prepared['price_list_id'],
                    'promotion_id' => $prepared['promotion_id'],
                    'frequency' => $prepared['frequency'],
                    'anniversary_date' => $prepared['anniversary_date'],
                    'currency' => $prepared['currency'],
                    'exchange_rate' => $prepared['exchange_rate'],
                    'credit_days' => $prepared['credit_days'],
                    'stamp_on_issue' => $prepared['stamp_on_issue'] ? 1 : 0,
                    'status' => 'active',
                    'mrr_mxn' => $prepared['mrr_mxn'],
                    'next_cutoff_date' => $prepared['next_cutoff_date'],
                    'notes' => $prepared['notes'],
                    'created_by' => $prepared['created_by'],
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);

                foreach ($prepared['lines'] as $line) {
                    Capsule::table('contract_lines')->insert([
                        'contract_id' => $contractId,
                        'service_id' => $line['service_id'],
                        'description' => $line['description'],
                        'service_type' => $line['service_type'],
                        'billing_frequency' => $line['billing_frequency'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount_amount' => $line['discount_amount'],
                        'subtotal' => $line['subtotal'],
                        'mrr_mxn' => $line['mrr_mxn'],
                        'is_package_converted' => $line['is_package_converted'] ? 1 : 0,
                        'active' => 1,
                        'created_at' => $nowUtc,
                        'updated_at' => $nowUtc,
                    ]);
                }

                if ($prepared['promotion_id'] !== null) {
                    $promoLocked = Capsule::table('promotions')
                        ->where('id', $prepared['promotion_id'])
                        ->lockForUpdate()
                        ->first();
                    if ($promoLocked === null || !(bool) $promoLocked->is_active) {
                        throw new \RuntimeException('Promoción no disponible al confirmar.');
                    }
                    if (
                        $promoLocked->max_uses !== null
                        && (int) $promoLocked->current_uses >= (int) $promoLocked->max_uses
                    ) {
                        throw new \RuntimeException('La promoción alcanzó el máximo de usos.');
                    }
                    Capsule::table('promotions')
                        ->where('id', $prepared['promotion_id'])
                        ->update([
                            'current_uses' => (int) $promoLocked->current_uses + 1,
                            'updated_at' => $nowUtc,
                        ]);
                }

                $movements = new MovementsService();
                $movements->recordWithinTransaction([
                    'contract_id' => $contractId,
                    'contract_line_id' => null,
                    'service_id' => null,
                    'movement_type' => 'contract_created',
                    'mrr_category' => 'new',
                    'effective_date' => $prepared['anniversary_date'],
                    'quantity_before' => '0.00',
                    'quantity_after' => '1.00',
                    'mrr_before' => '0.00',
                    'mrr_after' => $prepared['mrr_mxn'],
                    'mrr_delta_mxn' => $prepared['mrr_mxn'],
                    'exchange_rate' => $this->normalizeExchangeRateForMovement($prepared['exchange_rate']),
                    'user_id' => $prepared['created_by'],
                    'request_id' => null,
                    'reason' => 'Alta de contrato ' . $folio,
                    'has_exception' => 0,
                    'excepted_policy' => null,
                    'reversed_movement_id' => null,
                    'created_at' => $nowUtc,
                ]);

                return $contractId;
            });
        } catch (Throwable $exception) {
            return Result::fail(
                'No se pudo crear el contrato: ' . $exception->getMessage(),
                422,
            );
        }

        // RF-2.6: otorga bolsas included según reglas de beneficio activas.
        $this->syncContractBenefitBalances(
            (int) $created,
            (int) ($this->authContext['user_id'] ?? 0) ?: null,
        );

        $enriched = $this->findEnriched((int) $created) ?? ['id' => (int) $created];
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'contracts',
            (string) $created,
            'create',
            'Se creó el contrato ' . ($enriched['folio'] ?? ('#' . $created)),
            null,
            [
                'id' => (int) $created,
                'folio' => $enriched['folio'] ?? null,
                'client_id' => $enriched['client_id'] ?? null,
                'mrr_mxn' => $enriched['mrr_mxn'] ?? null,
                'status' => $enriched['status'] ?? 'active',
            ],
        );

        return Result::ok($enriched, 'Contrato creado', 201);
    }

    /**
     * Sincroniza bolsas included con reglas de beneficio (RF-2.6).
     *
     * @return list<array<string, mixed>>
     */
    public function syncContractBenefitBalances(int $contractId, ?int $userId = null): array
    {
        return \System\Support\BenefitRulesEngine::syncContractBenefitBalances($contractId, $userId);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|Result
     */
    private function prepareCreatePayload(array $request): array|Result
    {
        $clientId = $this->positiveIntOrNull($request['client_id'] ?? null);
        $priceListId = $this->positiveIntOrNull($request['price_list_id'] ?? null);
        $frequency = $this->normalizeBillingFrequency((string) ($request['billing_frequency'] ?? $request['frequency'] ?? ''));
        $anniversary = trim((string) ($request['anniversary_date'] ?? ''));

        if ($clientId === null) {
            return Result::fail('client_id es requerido.', 422);
        }
        if ($priceListId === null) {
            return Result::fail('price_list_id es requerido.', 422);
        }
        if ($frequency === null || !in_array($frequency, ['monthly', 'annual'], true)) {
            return Result::fail('billing_frequency debe ser monthly o annual.', 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anniversary)) {
            return Result::fail('anniversary_date inválida (YYYY-MM-DD).', 422);
        }

        /** @var list<mixed> $rawLines */
        $rawLines = is_array($request['lines'] ?? null) ? $request['lines'] : [];
        if ($rawLines === []) {
            return Result::fail('Debes agregar al menos una línea de servicio.', 422);
        }

        // clients.active (boolean) — columna real del directorio.
        $client = Capsule::table('clients')
            ->select(['id', 'segment_id', 'issuer_id', 'active', 'credit_days', 'stamp_on_issue'])
            ->where('id', $clientId)
            ->first();
        if ($client === null || !(bool) $client->active) {
            return Result::fail('Cliente no encontrado o inactivo.', 422);
        }

        $segmentId = (int) $client->segment_id;
        $issuerId = (int) $client->issuer_id;
        if (!$this->isWithinSegmentScope($segmentId)) {
            return Result::fail('Cliente fuera de tu alcance de segmento.', 403);
        }

        // price_lists.is_active — no confundir con `active`.
        $priceList = Capsule::table('price_lists')
            ->select(['id', 'segment_id', 'currency', 'is_active'])
            ->where('id', $priceListId)
            ->first();
        if ($priceList === null || !(bool) $priceList->is_active) {
            return Result::fail('Lista de precios no encontrada o inactiva.', 422);
        }
        if ((int) $priceList->segment_id !== $segmentId) {
            return Result::fail('La lista de precios no pertenece al segmento del cliente.', 422);
        }

        $currency = strtoupper((string) $priceList->currency);
        if (!in_array($currency, ['MXN', 'USD'], true)) {
            return Result::fail('Moneda de lista no soportada.', 422);
        }

        $exchangeRate = '1.0000';
        if ($currency === 'USD') {
            $incomingFx = trim((string) ($request['exchange_rate'] ?? ''));
            if ($incomingFx !== '' && is_numeric($incomingFx)) {
                $exchangeRate = bcadd($incomingFx, '0', 4);
            } else {
                $segmentFx = Capsule::table('segments')->where('id', $segmentId)->value('usd_exchange_rate');
                $exchangeRate = $segmentFx !== null ? bcadd((string) $segmentFx, '0', 4) : '1.0000';
            }
            if (bccomp($exchangeRate, '0', 4) <= 0) {
                return Result::fail('exchange_rate debe ser mayor a 0.', 422);
            }
        }

        $instanceId = $this->positiveIntOrNull($request['instance_id'] ?? null);
        if ($instanceId !== null) {
            // instances.status (enum) — no hay columna active/is_active.
            $instance = Capsule::table('instances')
                ->select(['id', 'segment_id', 'status'])
                ->where('id', $instanceId)
                ->first();
            if ($instance === null) {
                return Result::fail('Instancia técnica no encontrada.', 422);
            }
            if ((int) $instance->segment_id !== $segmentId) {
                return Result::fail('La instancia no pertenece al segmento del cliente.', 422);
            }
            $instanceStatus = (string) $instance->status;
            if (!in_array($instanceStatus, ['operational', 'maintenance'], true)) {
                return Result::fail('La instancia no está disponible (suspendida o dada de baja).', 422);
            }
        }

        $creditDays = array_key_exists('credit_days', $request) && $request['credit_days'] !== '' && $request['credit_days'] !== null
            ? max(0, (int) $request['credit_days'])
            : (int) $client->credit_days;

        $stampOnIssue = array_key_exists('stamp_on_issue', $request)
            ? (bool) filter_var($request['stamp_on_issue'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : (bool) $client->stamp_on_issue;
        if ($stampOnIssue === null) {
            $stampOnIssue = (bool) $client->stamp_on_issue;
        }

        $promotionId = $this->positiveIntOrNull($request['promotion_id'] ?? null);
        $promo = null;
        if ($promotionId !== null) {
            $promoResult = $this->assertPromotionUsable($promotionId, $segmentId, $anniversary);
            if ($promoResult instanceof Result) {
                return $promoResult;
            }
            $promo = $promoResult;
        }

        $expandedLines = [];
        $rf2SavingsTotal = '0.00';

        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                return Result::fail('Formato de línea inválido.', 422);
            }

            $serviceId = $this->positiveIntOrNull($rawLine['service_id'] ?? null);
            $quantity = max(0, (int) ($rawLine['quantity'] ?? 0));
            if ($serviceId === null || $quantity < 1) {
                return Result::fail('Cada línea requiere service_id y quantity ≥ 1.', 422);
            }

            // services.is_active — NUNCA `active` (esa columna no existe en services).
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
                return Result::fail("Servicio {$serviceId} no encontrado o inactivo.", 422);
            }

            $serviceType = $this->normalizeServiceType((string) $service->service_type);
            $lineFrequency = $serviceType === 'recurring' ? $frequency : 'single';

            $override = trim((string) ($rawLine['unit_price_override'] ?? ''));
            if ($override !== '' && is_numeric($override)) {
                $unitPrice = FinancialCalculator::normalizeMoney($override);
            } else {
                try {
                    $unitPrice = $this->resolveListUnitPrice(
                        $priceListId,
                        $serviceId,
                        $frequency,
                        $quantity,
                        $serviceType,
                    );
                } catch (\InvalidArgumentException $e) {
                    $svcName = (string) $service->name;

                    return Result::fail(
                        "{$svcName}: {$e->getMessage()}",
                        422,
                    );
                }
            }

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
                if ($pkgService === null || !(bool) $pkgService->is_active) {
                    return Result::fail('El producto paquete destino no existe o está inactivo.', 422);
                }
                $packageName = (string) $pkgService->name;
                try {
                    $packageUnitPrice = $this->resolveListUnitPrice(
                        $priceListId,
                        $packageServiceId,
                        $frequency,
                        1,
                        'recurring',
                    );
                } catch (\InvalidArgumentException $e) {
                    return Result::fail(
                        "Paquete destino: {$e->getMessage()}",
                        422,
                    );
                }
            }

            $expanded = ContractPricingEngine::expandLineWithRf2(
                $serviceId,
                (string) $service->name,
                $serviceType,
                $lineFrequency,
                $quantity,
                $unitPrice,
                $allowPkg,
                $packageServiceId,
                $packageName,
                $packageFactor,
                $packageUnitPrice,
            );

            foreach ($expanded as $exp) {
                $rf2SavingsTotal = bcadd($rf2SavingsTotal, $exp['rf2_savings'], 2);
                $subtotal = ContractPricingEngine::lineSubtotal($exp['quantity'], $exp['unit_price'], '0.00');
                $expandedLines[] = [
                    'service_id' => $exp['service_id'],
                    'description' => mb_substr($exp['description'], 0, 200),
                    'service_type' => $exp['service_type'],
                    'billing_frequency' => $exp['billing_frequency'],
                    'quantity' => $exp['quantity'],
                    'unit_price' => $exp['unit_price'],
                    'discount_amount' => '0.00',
                    'subtotal' => $subtotal,
                    'is_package_converted' => $exp['is_package_converted'],
                ];
            }
        }

        // Descuento promocional proporcional sobre líneas recurrentes.
        $recurringBase = '0.00';
        foreach ($expandedLines as $line) {
            if ($line['service_type'] === 'recurring') {
                $recurringBase = bcadd($recurringBase, $line['subtotal'], 2);
            }
        }

        $promoDiscount = ContractPricingEngine::applyPromotionDiscount(
            $recurringBase,
            $promo !== null ? (string) $promo->discount_type : null,
            $promo !== null ? (string) $promo->discount_value : null,
        );
        $discountPool = $promoDiscount['discount_amount'];

        if (bccomp($discountPool, '0.00', 2) > 0 && bccomp($recurringBase, '0.00', 2) > 0) {
            $allocated = '0.00';
            $recurringIndexes = [];
            foreach ($expandedLines as $idx => $line) {
                if ($line['service_type'] === 'recurring') {
                    $recurringIndexes[] = $idx;
                }
            }
            $lastIdx = $recurringIndexes[array_key_last($recurringIndexes)] ?? null;
            foreach ($recurringIndexes as $idx) {
                if ($idx === $lastIdx) {
                    $share = bcsub($discountPool, $allocated, 2);
                } else {
                    $share = bcdiv(
                        bcmul($discountPool, $expandedLines[$idx]['subtotal'], 4),
                        $recurringBase,
                        2,
                    );
                    $allocated = bcadd($allocated, $share, 2);
                }
                $expandedLines[$idx]['discount_amount'] = $share;
                $expandedLines[$idx]['subtotal'] = ContractPricingEngine::lineSubtotal(
                    $expandedLines[$idx]['quantity'],
                    $expandedLines[$idx]['unit_price'],
                    $share,
                );
            }
        }

        $contractMrr = '0.00';
        $finalLines = [];
        foreach ($expandedLines as $line) {
            $lineMrr = ContractPricingEngine::lineMrrMxn(
                $line['service_type'],
                $line['billing_frequency'],
                $line['subtotal'],
                $exchangeRate,
            );
            $contractMrr = bcadd($contractMrr, $lineMrr, 2);
            $finalLines[] = [
                ...$line,
                'mrr_mxn' => $lineMrr,
            ];
        }

        $userId = (int) ($this->authContext['user_id'] ?? 0);

        return [
            'client_id' => $clientId,
            'segment_id' => $segmentId,
            'issuer_id' => $issuerId,
            'instance_id' => $instanceId,
            'price_list_id' => $priceListId,
            'promotion_id' => $promotionId,
            'frequency' => $frequency,
            'anniversary_date' => $anniversary,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'credit_days' => $creditDays,
            'stamp_on_issue' => $stampOnIssue,
            'mrr_mxn' => $contractMrr,
            // Próximo corte: aniversario + 1 mes (mensual) o + 1 año (anual).
            'next_cutoff_date' => $this->computeNextCutoffDate($anniversary, $frequency),
            'notes' => ($notes = trim((string) ($request['notes'] ?? ''))) !== '' ? mb_substr($notes, 0, 5000) : null,
            'created_by' => $userId > 0 ? $userId : null,
            'lines' => $finalLines,
            'rf2_savings' => $rf2SavingsTotal,
            'promo_discount' => $discountPool,
        ];
    }

    /**
     * Valida vigencia/alcance de la promoción; en éxito retorna la fila Eloquent (stdClass).
     *
     * @return Result|\stdClass
     */
    private function assertPromotionUsable(int $promotionId, int $segmentId, string $asOfDate): Result|\stdClass
    {
        // promotions.is_active — columna real del catálogo promocional.
        $promo = Capsule::table('promotions')
            ->select([
                'id',
                'segment_id',
                'is_active',
                'valid_from',
                'valid_until',
                'max_uses',
                'current_uses',
                'discount_type',
                'discount_value',
            ])
            ->where('id', $promotionId)
            ->first();
        if ($promo === null || !(bool) $promo->is_active) {
            return Result::fail('Promoción no encontrada o inactiva.', 422);
        }
        if ($promo->segment_id !== null && (int) $promo->segment_id !== $segmentId) {
            return Result::fail('La promoción no aplica al segmento del cliente.', 422);
        }
        if ((string) $promo->valid_from > $asOfDate) {
            return Result::fail('La promoción aún no está vigente.', 422);
        }
        if ($promo->valid_until !== null && (string) $promo->valid_until < $asOfDate) {
            return Result::fail('La promoción ya expiró.', 422);
        }
        if ($promo->max_uses !== null && (int) $promo->current_uses >= (int) $promo->max_uses) {
            return Result::fail('La promoción alcanzó el máximo de usos.', 422);
        }

        return $promo;
    }

    /**
     * Calcula el próximo corte a partir del aniversario y la frecuencia de facturación.
     * Mensual → +1 month; anual → +1 year (calendario America/Mexico_City).
     */
    private function computeNextCutoffDate(string $anniversaryDate, string $frequency): string
    {
        $tz = new \DateTimeZone('America/Mexico_City');
        $base = \DateTimeImmutable::createFromFormat('Y-m-d', $anniversaryDate, $tz);
        if ($base === false) {
            return $anniversaryDate;
        }

        $modifier = $frequency === 'annual' ? '+1 year' : '+1 month';

        return $base->modify($modifier)->format('Y-m-d');
    }

    /**
     * Resuelve precio en price_list_items.
     * one_time/consumo: busca frequency=single primero; fallback a monthly (datos legacy).
     *
     * @throws \InvalidArgumentException cantidad fuera de rango o sin escalones
     */
    private function resolveListUnitPrice(
        int $priceListId,
        int $serviceId,
        string $contractFrequency,
        int $quantity,
        string $serviceType = 'recurring',
    ): string {
        $frequencies = in_array($serviceType, ['single', 'consumption'], true)
            ? ['single', 'monthly']
            : [$contractFrequency];

        $tiers = [];
        foreach ($frequencies as $freq) {
            $tiers = Capsule::table('price_list_items')
                ->where('price_list_id', $priceListId)
                ->where('service_id', $serviceId)
                ->where('frequency', $freq)
                ->orderBy('min_quantity')
                ->get(['min_quantity', 'max_quantity', 'unit_price'])
                ->map(static fn (object $t): array => [
                    'min_quantity' => (int) $t->min_quantity,
                    'max_quantity' => $t->max_quantity !== null ? (int) $t->max_quantity : null,
                    'unit_price' => (string) $t->unit_price,
                ])
                ->all();
            if ($tiers !== []) {
                break;
            }
        }

        if ($tiers === []) {
            // Fallback: cualquier frecuencia del servicio en la lista.
            $tiers = Capsule::table('price_list_items')
                ->where('price_list_id', $priceListId)
                ->where('service_id', $serviceId)
                ->orderBy('min_quantity')
                ->get(['min_quantity', 'max_quantity', 'unit_price'])
                ->map(static fn (object $t): array => [
                    'min_quantity' => (int) $t->min_quantity,
                    'max_quantity' => $t->max_quantity !== null ? (int) $t->max_quantity : null,
                    'unit_price' => (string) $t->unit_price,
                ])
                ->all();
        }

        return ContractPricingEngine::resolveTierUnitPrice($tiers, $quantity);
    }

    private function nextFolio(): string
    {
        $max = Capsule::table('contracts')
            ->where('folio', 'like', 'CT-%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('folio');

        $next = 1;
        if (is_string($max) && preg_match('/CT-(\d+)/', $max, $m) === 1) {
            $next = (int) $m[1] + 1;
        }

        return sprintf('CT-%04d', $next);
    }

    private function normalizeBillingFrequency(string $value): ?string
    {
        $v = strtolower(trim($value));
        if ($v === '' || $v === 'all' || $v === 'todas') {
            return null;
        }
        if (in_array($v, ['monthly', 'mensual'], true)) {
            return 'monthly';
        }
        if (in_array($v, ['annual', 'anual', 'yearly'], true)) {
            return 'annual';
        }
        if (in_array($v, ['single', 'one_time', 'onetime', 'unico'], true)) {
            return 'single';
        }

        return null;
    }

    private function normalizeServiceType(string $value): string
    {
        $v = strtolower(trim($value));
        if (in_array($v, ['one_time', 'onetime', 'unico'], true)) {
            return 'single';
        }
        if (in_array($v, ['recurring', 'consumption', 'single'], true)) {
            return $v;
        }

        return 'recurring';
    }

    private function normalizeExchangeRateForMovement(string $rate): string
    {
        return bcadd($rate, '0', 6);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }

    /**
     * Motor transaccional de ciclo de vida (BCMath + movements append-only + audit).
     *
     * @param array<string, mixed> $request
     */
    private function runLifecycleOperation(int $contractId, array $request): Result
    {
        $operation = strtolower(trim((string) ($request['operation'] ?? '')));
        $allowed = [
            'expansion',
            'contraction',
            'price_adjustment',
            'frequency_change',
            'issuer_transfer',
            'churn',
            'reactivation',
        ];
        if (!in_array($operation, $allowed, true)) {
            return Result::fail(
                'operation inválida. Usa: ' . implode(', ', $allowed) . '.',
                422,
            );
        }

        $reason = trim((string) ($request['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            return Result::fail('El motivo (reason) es obligatorio (mínimo 3 caracteres).', 422);
        }
        $reason = mb_substr($reason, 0, 255);

        $withException = filter_var($request['with_exception'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $effectiveDate = trim((string) ($request['effective_date'] ?? ''));
        if ($effectiveDate === '') {
            $effectiveDate = $this->todayInMexicoCity();
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return Result::fail('effective_date inválida (YYYY-MM-DD).', 422);
        }

        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        if ($contract === null) {
            return Result::fail('Contrato no encontrado.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $contract->segment_id)) {
            return Result::fail('Contrato fuera de tu alcance de segmento.', 403);
        }

        // RF-11.4 / RF-11.5: excepción comercial requiere permiso independiente + motivo.
        $authGate = \System\Support\ExceptionAuthorization::assertCanAuthorize(
            $this->authContext,
            $withException,
            $reason,
            $contractId,
            3,
        );
        if (!$authGate['allowed']) {
            $code = $authGate['message'] === \System\Support\ExceptionAuthorization::DENIED_MESSAGE
                ? 403
                : 422;

            return Result::fail((string) $authGate['message'], $code);
        }
        $overdueAtException = $withException ? $authGate['overdue_balance'] : null;
        $exceptedPolicy = $withException
            ? \System\Support\ServiceRequestRules::EXCEPTION_POLICY_OVERDUE
            : null;

        $this->lifecycleExceptionFlags = [
            'has_exception' => $withException ? 1 : 0,
            'excepted_policy' => $exceptedPolicy,
            'overdue_balance_at_exception' => $overdueAtException,
        ];

        $status = (string) $contract->status;
        $statusGate = $this->assertLifecycleStatus($operation, $status);
        if ($statusGate !== null) {
            $this->lifecycleExceptionFlags = null;

            return $statusGate;
        }

        try {
            Capsule::connection()->transaction(function () use (
                $operation,
                $contractId,
                $request,
                $reason,
                $effectiveDate,
            ): void {
                $locked = Capsule::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
                if ($locked === null) {
                    throw new \RuntimeException('Contrato no encontrado.');
                }

                match ($operation) {
                    'expansion' => $this->lifecycleExpansion($locked, $request, $reason, $effectiveDate),
                    'contraction' => $this->lifecycleContraction($locked, $request, $reason, $effectiveDate),
                    'price_adjustment' => $this->lifecyclePriceAdjustment($locked, $request, $reason, $effectiveDate),
                    'frequency_change' => $this->lifecycleFrequencyChange($locked, $request, $reason, $effectiveDate),
                    'issuer_transfer' => $this->lifecycleIssuerTransfer($locked, $request, $reason, $effectiveDate),
                    'churn' => $this->lifecycleChurn($locked, $reason, $effectiveDate),
                    'reactivation' => $this->lifecycleReactivation($locked, $reason, $effectiveDate),
                    default => throw new \RuntimeException('Operación no implementada.'),
                };

                // RF-2.6: recalcula bolsas included tras cambio de cantidades.
                if (in_array($operation, ['expansion', 'contraction'], true)) {
                    $this->syncContractBenefitBalances(
                        $contractId,
                        (int) ($this->authContext['user_id'] ?? 0) ?: null,
                    );
                }
            });
        } catch (Throwable $exception) {
            $this->lifecycleExceptionFlags = null;
            $code = str_contains($exception->getMessage(), 'MRR en $0.00') ? 422 : 422;

            return Result::fail($exception->getMessage(), $code);
        }
        $this->lifecycleExceptionFlags = null;

        $enriched = $this->findEnriched($contractId, true) ?? ['id' => $contractId];
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'contracts',
            (string) $contractId,
            'status_change',
            'Ciclo de vida: ' . $operation . ' en contrato ' . ($enriched['folio'] ?? ('#' . $contractId)),
            [
                'status' => $status,
                'mrr_mxn' => FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn),
            ],
            [
                'operation' => $operation,
                'status' => $enriched['status'] ?? null,
                'mrr_mxn' => $enriched['mrr_mxn'] ?? null,
                'reason' => $reason,
                'effective_date' => $effectiveDate,
            ],
        );

        return Result::ok($enriched, 'Operación de ciclo de vida aplicada');
    }

    private function assertLifecycleStatus(string $operation, string $status): ?Result
    {
        $activeOnly = [
            'expansion',
            'contraction',
            'price_adjustment',
            'frequency_change',
            'issuer_transfer',
        ];
        if (in_array($operation, $activeOnly, true) && $status !== 'active') {
            return Result::fail('La operación solo aplica a contratos activos.', 422);
        }
        if ($operation === 'churn' && $status === 'cancelled') {
            return Result::fail('El contrato ya está cancelado.', 422);
        }
        if ($operation === 'reactivation' && !in_array($status, ['cancelled', 'suspended'], true)) {
            return Result::fail('Solo se pueden reactivar contratos cancelados o suspendidos.', 422);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function lifecycleExpansion(
        object $contract,
        array $request,
        string $reason,
        string $effectiveDate,
    ): void {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $mrrBefore = FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn);
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $exchangeRate = (string) $contract->exchange_rate;
        $frequency = (string) $contract->frequency;
        $priceListId = $contract->price_list_id !== null ? (int) $contract->price_list_id : null;
        if ($priceListId === null) {
            throw new \RuntimeException('El contrato no tiene lista de precios asociada.');
        }

        $lineId = $this->positiveIntOrNull($request['contract_line_id'] ?? null);
        $serviceId = $this->positiveIntOrNull($request['service_id'] ?? null);
        $quantity = max(0, (int) ($request['quantity'] ?? 0));
        $addedMrr = '0.00';
        $movementLineId = null;
        $movementServiceId = null;
        $qtyBefore = '0.00';
        $qtyAfter = '0.00';

        if ($lineId !== null) {
            // Aumentar cantidad de línea existente.
            $line = Capsule::table('contract_lines')
                ->where('id', $lineId)
                ->where('contract_id', (int) $contract->id)
                ->lockForUpdate()
                ->first();
            if ($line === null || !(bool) $line->active) {
                throw new \RuntimeException('Línea de contrato no encontrada o inactiva.');
            }
            if ((string) $line->service_type !== 'recurring') {
                throw new \RuntimeException('Solo se puede expandir cantidad en líneas recurrentes.');
            }
            $newQty = $quantity > 0 ? $quantity : ((int) $line->quantity + 1);
            if ($newQty <= (int) $line->quantity) {
                throw new \RuntimeException('La nueva cantidad debe ser mayor a la actual.');
            }

            // Cupón quemado: discount_amount → 0.00; subtotal/MRR a precio neto.
            $lineMrrBefore = FinancialCalculator::normalizeMoney((string) $line->mrr_mxn);
            $recalc = ContractLifecycleEngine::recalculateLineNetOfCoupon(
                $newQty,
                (string) $line->unit_price,
                (string) $line->service_type,
                (string) $line->billing_frequency,
                $exchangeRate,
            );
            Capsule::table('contract_lines')->where('id', $lineId)->update([
                'quantity' => $newQty,
                'discount_amount' => '0.00',
                'subtotal' => $recalc['subtotal'],
                'mrr_mxn' => $recalc['mrr_mxn'],
                'updated_at' => $nowUtc,
            ]);
            $addedMrr = bcsub($recalc['mrr_mxn'], $lineMrrBefore, 2);
            $movementLineId = $lineId;
            $movementServiceId = (int) $line->service_id;
            $qtyBefore = number_format((int) $line->quantity, 2, '.', '');
            $qtyAfter = number_format($newQty, 2, '.', '');
        } elseif ($serviceId !== null) {
            if ($quantity < 1) {
                throw new \RuntimeException('quantity debe ser ≥ 1 para agregar un servicio.');
            }
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
                throw new \RuntimeException('Servicio no encontrado o inactivo.');
            }

            $serviceType = $this->normalizeServiceType((string) $service->service_type);
            if ($serviceType !== 'recurring') {
                throw new \RuntimeException(
                    'La expansión solo admite servicios recurrentes. Los cargos únicos o por consumo se gestionan desde Solicitudes.',
                );
            }
            $lineFrequency = $frequency;
            $override = trim((string) ($request['unit_price_override'] ?? ''));
            if ($override !== '' && is_numeric($override)) {
                $unitPrice = FinancialCalculator::normalizeMoney($override);
            } else {
                $unitPrice = $this->resolveListUnitPrice(
                    $priceListId,
                    $serviceId,
                    $frequency,
                    $quantity,
                    $serviceType,
                );
            }

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
                if ($pkgService === null || !(bool) $pkgService->is_active) {
                    throw new \RuntimeException('El producto paquete destino no existe o está inactivo.');
                }
                $packageName = (string) $pkgService->name;
                $packageUnitPrice = $this->resolveListUnitPrice(
                    $priceListId,
                    $packageServiceId,
                    $frequency,
                    1,
                    'recurring',
                );
            }

            $expanded = ContractPricingEngine::expandLineWithRf2(
                $serviceId,
                (string) $service->name,
                $serviceType,
                $lineFrequency,
                $quantity,
                $unitPrice,
                $allowPkg,
                $packageServiceId,
                $packageName,
                $packageFactor,
                $packageUnitPrice,
            );

            $firstInsertedId = null;
            foreach ($expanded as $exp) {
                $subtotal = ContractPricingEngine::lineSubtotal($exp['quantity'], $exp['unit_price'], '0.00');
                $lineMrr = ContractPricingEngine::lineMrrMxn(
                    $exp['service_type'],
                    $exp['billing_frequency'],
                    $subtotal,
                    $exchangeRate,
                );
                $insertedId = (int) Capsule::table('contract_lines')->insertGetId([
                    'contract_id' => (int) $contract->id,
                    'service_id' => $exp['service_id'],
                    'description' => mb_substr($exp['description'], 0, 200),
                    'service_type' => $exp['service_type'],
                    'billing_frequency' => $exp['billing_frequency'],
                    'quantity' => $exp['quantity'],
                    'unit_price' => $exp['unit_price'],
                    'discount_amount' => '0.00',
                    'subtotal' => $subtotal,
                    'mrr_mxn' => $lineMrr,
                    'is_package_converted' => $exp['is_package_converted'] ? 1 : 0,
                    'active' => 1,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                $firstInsertedId ??= $insertedId;
                $addedMrr = bcadd($addedMrr, $lineMrr, 2);
            }
            $movementLineId = $firstInsertedId;
            $movementServiceId = $serviceId;
            $qtyBefore = '0.00';
            $qtyAfter = number_format($quantity, 2, '.', '');
        } else {
            throw new \RuntimeException('Indica contract_line_id (aumentar cantidad) o service_id (nuevo servicio).');
        }

        // MRR contrato = suma de líneas recurrentes activas (tras cupón quemado en la línea tocada).
        $newContractMrr = $this->sumActiveRecurringMrrForContract((int) $contract->id);
        $impact = ContractLifecycleEngine::impact($mrrBefore, $newContractMrr);
        if ($serviceId !== null && bccomp($addedMrr, '0.00', 2) <= 0) {
            throw new \RuntimeException('La expansión no genera incremento de MRR.');
        }

        $this->persistContractMrrAndMovement(
            (int) $contract->id,
            $impact,
            [
                'contract_line_id' => $movementLineId,
                'service_id' => $movementServiceId,
                'movement_type' => 'expansion',
                'mrr_category' => 'expansion',
                'effective_date' => $effectiveDate,
                'quantity_before' => $qtyBefore,
                'quantity_after' => $qtyAfter,
                'exchange_rate' => $this->normalizeExchangeRateForMovement($exchangeRate),
                'user_id' => $userId > 0 ? $userId : null,
                'reason' => $reason,
            ],
            $nowUtc,
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function lifecycleContraction(
        object $contract,
        array $request,
        string $reason,
        string $effectiveDate,
    ): void {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $mrrBefore = FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn);
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $lineId = $this->positiveIntOrNull($request['contract_line_id'] ?? null);
        if ($lineId === null) {
            throw new \RuntimeException('contract_line_id es requerido para contracción.');
        }

        $line = Capsule::table('contract_lines')
            ->where('id', $lineId)
            ->where('contract_id', (int) $contract->id)
            ->lockForUpdate()
            ->first();
        if ($line === null || !(bool) $line->active) {
            throw new \RuntimeException('Línea de contrato no encontrada o inactiva.');
        }

        $deactivate = filter_var($request['deactivate'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $newQty = array_key_exists('quantity', $request) ? max(0, (int) $request['quantity']) : null;
        $qtyBefore = number_format((int) $line->quantity, 2, '.', '');
        $qtyAfter = $qtyBefore;

        if ($deactivate || $newQty === 0) {
            // Cupón quemado al dar de baja: precio neto congelado, MRR de línea = 0.
            $recalc = ContractLifecycleEngine::recalculateLineNetOfCoupon(
                (int) $line->quantity,
                (string) $line->unit_price,
                (string) $line->service_type,
                (string) $line->billing_frequency,
                (string) $contract->exchange_rate,
            );
            Capsule::table('contract_lines')->where('id', $lineId)->update([
                'active' => 0,
                'discount_amount' => '0.00',
                'subtotal' => $recalc['subtotal'],
                'mrr_mxn' => '0.00',
                'updated_at' => $nowUtc,
            ]);
            $qtyAfter = '0.00';
        } elseif ($newQty !== null) {
            if ($newQty >= (int) $line->quantity) {
                throw new \RuntimeException('La nueva cantidad debe ser menor a la actual.');
            }
            if ((string) $line->service_type !== 'recurring') {
                throw new \RuntimeException('Solo se puede reducir cantidad en líneas recurrentes.');
            }
            // Cupón quemado: discount_amount → 0.00; subtotal/MRR a precio neto.
            $recalc = ContractLifecycleEngine::recalculateLineNetOfCoupon(
                $newQty,
                (string) $line->unit_price,
                (string) $line->service_type,
                (string) $line->billing_frequency,
                (string) $contract->exchange_rate,
            );
            Capsule::table('contract_lines')->where('id', $lineId)->update([
                'quantity' => $newQty,
                'discount_amount' => '0.00',
                'subtotal' => $recalc['subtotal'],
                'mrr_mxn' => $recalc['mrr_mxn'],
                'updated_at' => $nowUtc,
            ]);
            $qtyAfter = number_format($newQty, 2, '.', '');
        } else {
            throw new \RuntimeException('Indica quantity menor o deactivate=true.');
        }

        $newContractMrr = $this->sumActiveRecurringMrrForContract((int) $contract->id);
        if (bccomp($newContractMrr, '0.00', 2) <= 0) {
            throw new \RuntimeException(
                'La contracción dejaría el MRR en $0.00. Usa la operación de Cancelación (Churn).',
            );
        }
        $impact = ContractLifecycleEngine::impact($mrrBefore, $newContractMrr);

        $this->persistContractMrrAndMovement(
            (int) $contract->id,
            $impact,
            [
                'contract_line_id' => $lineId,
                'service_id' => (int) $line->service_id,
                'movement_type' => 'contraction',
                'mrr_category' => 'contraction',
                'effective_date' => $effectiveDate,
                'quantity_before' => $qtyBefore,
                'quantity_after' => $qtyAfter,
                'exchange_rate' => $this->normalizeExchangeRateForMovement((string) $contract->exchange_rate),
                'user_id' => $userId > 0 ? $userId : null,
                'reason' => $reason,
            ],
            $nowUtc,
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function lifecyclePriceAdjustment(
        object $contract,
        array $request,
        string $reason,
        string $effectiveDate,
    ): void {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $mrrBefore = FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn);
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $lineId = $this->positiveIntOrNull($request['contract_line_id'] ?? null);
        $newUnitPriceRaw = trim((string) ($request['unit_price'] ?? ''));
        if ($lineId === null || $newUnitPriceRaw === '' || !is_numeric($newUnitPriceRaw)) {
            throw new \RuntimeException('contract_line_id y unit_price son requeridos.');
        }

        $line = Capsule::table('contract_lines')
            ->where('id', $lineId)
            ->where('contract_id', (int) $contract->id)
            ->lockForUpdate()
            ->first();
        if ($line === null || !(bool) $line->active) {
            throw new \RuntimeException('Línea de contrato no encontrada o inactiva.');
        }
        if ((string) $line->service_type !== 'recurring') {
            throw new \RuntimeException('Solo se ajusta precio en líneas recurrentes.');
        }

        $newUnitPrice = FinancialCalculator::normalizeMoney($newUnitPriceRaw);
        // Cupón quemado: discount_amount → 0.00; subtotal = qty × unit_price neto.
        $recalc = ContractLifecycleEngine::recalculateLineNetOfCoupon(
            (int) $line->quantity,
            $newUnitPrice,
            (string) $line->service_type,
            (string) $line->billing_frequency,
            (string) $contract->exchange_rate,
        );

        Capsule::table('contract_lines')->where('id', $lineId)->update([
            'unit_price' => $newUnitPrice,
            'discount_amount' => '0.00',
            'subtotal' => $recalc['subtotal'],
            'mrr_mxn' => $recalc['mrr_mxn'],
            'updated_at' => $nowUtc,
        ]);

        $newContractMrr = $this->sumActiveRecurringMrrForContract((int) $contract->id);
        $impact = ContractLifecycleEngine::impact($mrrBefore, $newContractMrr);
        $this->persistContractMrrAndMovement(
            (int) $contract->id,
            $impact,
            [
                'contract_line_id' => $lineId,
                'service_id' => (int) $line->service_id,
                'movement_type' => 'price_adjustment',
                'mrr_category' => 'price_adjustment',
                'effective_date' => $effectiveDate,
                'quantity_before' => number_format((int) $line->quantity, 2, '.', ''),
                'quantity_after' => number_format((int) $line->quantity, 2, '.', ''),
                'exchange_rate' => $this->normalizeExchangeRateForMovement((string) $contract->exchange_rate),
                'user_id' => $userId > 0 ? $userId : null,
                'reason' => $reason,
            ],
            $nowUtc,
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function lifecycleFrequencyChange(
        object $contract,
        array $request,
        string $reason,
        string $effectiveDate,
    ): void {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $mrrBefore = FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn);
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $current = (string) $contract->frequency;
        $requested = $this->normalizeBillingFrequency((string) ($request['billing_frequency'] ?? $request['frequency'] ?? ''));
        $newFrequency = $requested;
        if ($newFrequency === null || !in_array($newFrequency, ['monthly', 'annual'], true)) {
            $newFrequency = $current === 'monthly' ? 'annual' : 'monthly';
        }
        if ($newFrequency === $current) {
            throw new \RuntimeException('La frecuencia solicitada es igual a la actual.');
        }

        $lines = Capsule::table('contract_lines')
            ->where('contract_id', (int) $contract->id)
            ->lockForUpdate()
            ->get();

        $exchangeRate = (string) $contract->exchange_rate;
        foreach ($lines as $line) {
            if (!(bool) $line->active) {
                continue;
            }
            if ((string) $line->service_type !== 'recurring') {
                continue;
            }

            // Cupón quemado en cada línea recurrente tocada por el cambio de frecuencia.
            $newUnit = ContractLifecycleEngine::convertUnitPriceForFrequency(
                (string) $line->unit_price,
                $current,
                $newFrequency,
            );
            $recalc = ContractLifecycleEngine::recalculateLineNetOfCoupon(
                (int) $line->quantity,
                $newUnit,
                'recurring',
                $newFrequency,
                $exchangeRate,
            );
            Capsule::table('contract_lines')->where('id', (int) $line->id)->update([
                'billing_frequency' => $newFrequency,
                'unit_price' => $newUnit,
                'discount_amount' => '0.00',
                'subtotal' => $recalc['subtotal'],
                'mrr_mxn' => $recalc['mrr_mxn'],
                'updated_at' => $nowUtc,
            ]);
        }

        $totalMrr = $this->sumActiveRecurringMrrForContract((int) $contract->id);
        $nextCutoff = $this->computeNextCutoffDate($effectiveDate, $newFrequency);
        Capsule::table('contracts')->where('id', (int) $contract->id)->update([
            'frequency' => $newFrequency,
            'mrr_mxn' => $totalMrr,
            'next_cutoff_date' => $nextCutoff,
            'updated_at' => $nowUtc,
        ]);

        $impact = ContractLifecycleEngine::impact($mrrBefore, $totalMrr);
        (new MovementsService())->recordWithinTransaction([
            'contract_id' => (int) $contract->id,
            'contract_line_id' => null,
            'service_id' => null,
            'movement_type' => 'frequency_change',
            'mrr_category' => 'frequency_change',
            'effective_date' => $effectiveDate,
            'quantity_before' => '0.00',
            'quantity_after' => '0.00',
            'mrr_before' => $impact['mrr_before'],
            'mrr_after' => $impact['mrr_after'],
            'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
            'exchange_rate' => $this->normalizeExchangeRateForMovement($exchangeRate),
            'user_id' => $userId > 0 ? $userId : null,
            'request_id' => null,
            'reason' => $reason,
            'has_exception' => 0,
            'excepted_policy' => null,
            'reversed_movement_id' => null,
            'created_at' => $nowUtc,
        ]);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function lifecycleIssuerTransfer(
        object $contract,
        array $request,
        string $reason,
        string $effectiveDate,
    ): void {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $newIssuerId = $this->positiveIntOrNull($request['issuer_id'] ?? null);
        if ($newIssuerId === null) {
            throw new \RuntimeException('issuer_id es requerido para la transferencia.');
        }
        if ($newIssuerId === (int) $contract->issuer_id) {
            throw new \RuntimeException('El emisor destino debe ser distinto al actual.');
        }

        $issuer = Capsule::table('issuers')
            ->select(['id', 'is_active', 'name'])
            ->where('id', $newIssuerId)
            ->first();
        if ($issuer === null || !(bool) $issuer->is_active) {
            throw new \RuntimeException('Emisor destino no encontrado o inactivo.');
        }

        $impact = ContractLifecycleEngine::transferImpact((string) $contract->mrr_mxn);
        Capsule::table('contracts')->where('id', (int) $contract->id)->update([
            'issuer_id' => $newIssuerId,
            'updated_at' => $nowUtc,
        ]);

        (new MovementsService())->recordWithinTransaction([
            'contract_id' => (int) $contract->id,
            'contract_line_id' => null,
            'service_id' => null,
            'movement_type' => 'issuer_transfer',
            'mrr_category' => 'transfer',
            'effective_date' => $effectiveDate,
            'quantity_before' => '0.00',
            'quantity_after' => '0.00',
            'mrr_before' => $impact['mrr_before'],
            'mrr_after' => $impact['mrr_after'],
            'mrr_delta_mxn' => '0.00',
            'exchange_rate' => $this->normalizeExchangeRateForMovement((string) $contract->exchange_rate),
            'user_id' => $userId > 0 ? $userId : null,
            'request_id' => null,
            'reason' => $reason,
            ...$this->exceptionColumnsForMovement(),
            'reversed_movement_id' => null,
            'created_at' => $nowUtc,
        ]);
    }

    private function lifecycleChurn(object $contract, string $reason, string $effectiveDate): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $impact = ContractLifecycleEngine::churnImpact((string) $contract->mrr_mxn);

        Capsule::table('contract_lines')
            ->where('contract_id', (int) $contract->id)
            ->where('active', 1)
            ->update([
                'active' => 0,
                'mrr_mxn' => '0.00',
                'updated_at' => $nowUtc,
            ]);

        Capsule::table('contracts')->where('id', (int) $contract->id)->update([
            'status' => 'cancelled',
            'termination_reason' => $reason,
            'mrr_mxn' => '0.00',
            'updated_at' => $nowUtc,
        ]);

        (new MovementsService())->recordWithinTransaction([
            'contract_id' => (int) $contract->id,
            'contract_line_id' => null,
            'service_id' => null,
            'movement_type' => 'churn',
            'mrr_category' => 'churn',
            'effective_date' => $effectiveDate,
            'quantity_before' => '1.00',
            'quantity_after' => '0.00',
            'mrr_before' => $impact['mrr_before'],
            'mrr_after' => '0.00',
            'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
            'exchange_rate' => $this->normalizeExchangeRateForMovement((string) $contract->exchange_rate),
            'user_id' => $userId > 0 ? $userId : null,
            'request_id' => null,
            'reason' => $reason,
            ...$this->exceptionColumnsForMovement(),
            'reversed_movement_id' => null,
            'created_at' => $nowUtc,
        ]);
    }

    private function lifecycleReactivation(object $contract, string $reason, string $effectiveDate): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $frequency = (string) $contract->frequency;
        $exchangeRate = (string) $contract->exchange_rate;

        // Reactiva líneas recurrentes que estaban activas al cancelar (todas las que tengan subtotal > 0).
        $lines = Capsule::table('contract_lines')
            ->where('contract_id', (int) $contract->id)
            ->lockForUpdate()
            ->get();

        $restored = '0.00';
        foreach ($lines as $line) {
            if ((string) $line->service_type !== 'recurring') {
                continue;
            }
            // Recalcula MRR desde subtotal congelado y reactiva.
            $lineMrr = ContractPricingEngine::lineMrrMxn(
                'recurring',
                (string) $line->billing_frequency !== '' ? (string) $line->billing_frequency : $frequency,
                (string) $line->subtotal,
                $exchangeRate,
            );
            if (bccomp($lineMrr, '0.00', 2) <= 0 && bccomp((string) $line->subtotal, '0.00', 2) <= 0) {
                continue;
            }
            Capsule::table('contract_lines')->where('id', (int) $line->id)->update([
                'active' => 1,
                'mrr_mxn' => $lineMrr,
                'billing_frequency' => $frequency,
                'updated_at' => $nowUtc,
            ]);
            $restored = bcadd($restored, $lineMrr, 2);
        }

        if (bccomp($restored, '0.00', 2) <= 0) {
            throw new \RuntimeException('No hay líneas recurrentes restaurables para reactivar el contrato.');
        }

        $nextCutoff = $this->computeNextCutoffDate($effectiveDate, $frequency);
        Capsule::table('contracts')->where('id', (int) $contract->id)->update([
            'status' => 'active',
            'termination_reason' => null,
            'mrr_mxn' => $restored,
            'next_cutoff_date' => $nextCutoff,
            'updated_at' => $nowUtc,
        ]);

        $impact = ContractLifecycleEngine::reactivationImpact($restored);
        (new MovementsService())->recordWithinTransaction([
            'contract_id' => (int) $contract->id,
            'contract_line_id' => null,
            'service_id' => null,
            'movement_type' => 'reactivation',
            'mrr_category' => 'reactivation',
            'effective_date' => $effectiveDate,
            'quantity_before' => '0.00',
            'quantity_after' => '1.00',
            'mrr_before' => '0.00',
            'mrr_after' => $impact['mrr_after'],
            'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
            'exchange_rate' => $this->normalizeExchangeRateForMovement($exchangeRate),
            'user_id' => $userId > 0 ? $userId : null,
            'request_id' => null,
            'reason' => $reason,
            ...$this->exceptionColumnsForMovement(),
            'reversed_movement_id' => null,
            'created_at' => $nowUtc,
        ]);
    }

    /**
     * Actualiza mrr_mxn del contrato e inserta movement (asumiendo transacción abierta).
     *
     * @param array{mrr_before:string,mrr_after:string,mrr_delta_mxn:string} $impact
     * @param array<string, mixed> $movementExtra
     */
    private function persistContractMrrAndMovement(
        int $contractId,
        array $impact,
        array $movementExtra,
        string $nowUtc,
    ): void {
        Capsule::table('contracts')->where('id', $contractId)->update([
            'mrr_mxn' => $impact['mrr_after'],
            'updated_at' => $nowUtc,
        ]);

        (new MovementsService())->recordWithinTransaction([
            'contract_id' => $contractId,
            'contract_line_id' => $movementExtra['contract_line_id'] ?? null,
            'service_id' => $movementExtra['service_id'] ?? null,
            'movement_type' => (string) $movementExtra['movement_type'],
            'mrr_category' => (string) $movementExtra['mrr_category'],
            'effective_date' => (string) $movementExtra['effective_date'],
            'quantity_before' => (string) ($movementExtra['quantity_before'] ?? '0.00'),
            'quantity_after' => (string) ($movementExtra['quantity_after'] ?? '0.00'),
            'mrr_before' => $impact['mrr_before'],
            'mrr_after' => $impact['mrr_after'],
            'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
            'exchange_rate' => (string) $movementExtra['exchange_rate'],
            'user_id' => $movementExtra['user_id'] ?? null,
            'request_id' => null,
            'reason' => (string) ($movementExtra['reason'] ?? ''),
            ...$this->exceptionColumnsForMovement(),
            'reversed_movement_id' => null,
            'created_at' => $nowUtc,
        ]);
    }

    /**
     * Columnas de excepción comercial a insertar en `movements` (RF-11.4 / RF-11.5).
     *
     * @return array{has_exception:int,excepted_policy:?string,overdue_balance_at_exception:?string}
     */
    private function exceptionColumnsForMovement(): array
    {
        if ($this->lifecycleExceptionFlags === null) {
            return [
                'has_exception' => 0,
                'excepted_policy' => null,
                'overdue_balance_at_exception' => null,
            ];
        }

        return $this->lifecycleExceptionFlags;
    }

    private function todayInMexicoCity(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    }

    /**
     * Suma BCMath del mrr_mxn de líneas recurrentes activas del contrato.
     */
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
}
