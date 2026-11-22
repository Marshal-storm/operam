<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\MovementsModel;
use System\Support\FinancialCalculator;
use Throwable;

/**
 * Servicio de bitácora `movements` (RNF-3 + RF-10).
 * Solo INSERT y reversiones contables; UPDATE/PATCH/DELETE bloqueados.
 * Cada INSERT sincroniza `monthly_mrr` en la misma transacción (bcadd).
 */
final class MovementsService extends ModelService
{
    private const IMMUTABLE_MESSAGE =
        'La bitácora de movimientos es estrictamente inmutable (RNF-3). Para corregir un registro, emita una reversión.';

    public function __construct(array $hooks = [])
    {
        $immutableHooks = [
            'before_update' => [
                static function (Context $context): void {
                    $context->setResult(Result::fail(self::IMMUTABLE_MESSAGE, 403));
                },
            ],
            'before_patch' => [
                static function (Context $context): void {
                    $context->setResult(Result::fail(self::IMMUTABLE_MESSAGE, 403));
                },
            ],
            'before_delete' => [
                static function (Context $context): void {
                    $context->setResult(Result::fail(self::IMMUTABLE_MESSAGE, 403));
                },
            ],
        ];

        parent::__construct(array_merge_recursive($immutableHooks, $hooks));
    }

    protected function modelClass(): string
    {
        return MovementsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('contract_id', DataTypes::Integer, true)
            ->setField('contract_line_id', DataTypes::Integer, false)
            ->setField('service_id', DataTypes::Integer, false)
            ->setField('movement_type', DataTypes::String, true)
            ->setField('mrr_category', DataTypes::String, true)
            ->setField('effective_date', DataTypes::Date, true)
            ->setField('quantity_before', DataTypes::String, false, '0.00')
            ->setField('quantity_after', DataTypes::String, false, '0.00')
            ->setField('mrr_before', DataTypes::String, true)
            ->setField('mrr_after', DataTypes::String, true)
            ->setField('mrr_delta_mxn', DataTypes::String, false)
            ->setField('exchange_rate', DataTypes::String, false, '1.000000')
            ->setField('user_id', DataTypes::Integer, false)
            ->setField('request_id', DataTypes::Integer, false)
            ->setField('reason', DataTypes::String, false)
            ->setField('has_exception', DataTypes::Boolean, false, false)
            ->setField('excepted_policy', DataTypes::String, false)
            ->setField('reversed_movement_id', DataTypes::Integer, false);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $request = $context->getRequest();

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listRecent($request), 'Movimientos');
        }

        if ($method === 'GET' && $id !== null) {
            $row = Capsule::table('movements')->where('id', (int) $id)->first();
            if ($row === null) {
                return Result::fail('Movimiento no encontrado.', 404);
            }

            return Result::ok($this->mapRow($row), 'Movimiento');
        }

        // POST /api/movements con reversal_of → reversión contable (preferir ruta anidada en contracts).
        if ($method === 'POST' && $id === null && isset($request['reversal_of'])) {
            $auth = $context->getData('auth', []);
            $userId = (int) ($request['user_id'] ?? $auth['user_id'] ?? 0);
            $reason = trim((string) ($request['reason'] ?? ''));
            $effectiveDate = isset($request['effective_date'])
                ? trim((string) $request['effective_date'])
                : null;
            if ($userId < 1) {
                return Result::fail('user_id es requerido para la reversión.', 422);
            }

            return $this->createReversalMovement(
                (int) $request['reversal_of'],
                $userId,
                $reason,
                $effectiveDate !== '' ? $effectiveDate : null,
            );
        }

        if ($method === 'POST' && $id === null) {
            $input = $this->normalizeMoney($request);
            if (!isset($input['created_at'])) {
                $input['created_at'] = gmdate('Y-m-d H:i:s');
            }

            return $this->insertMovementAndSyncMrr($input, 'Movimiento registrado');
        }

        if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            return Result::fail(self::IMMUTABLE_MESSAGE, 403);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * Inserta un movimiento inverso que referencia al original (RNF-3).
     * Calcula el impacto contra el MRR actual del contrato (BCMath), sincroniza
     * `monthly_mrr` y actualiza `contracts.mrr_mxn` en la misma transacción.
     *
     * @param string|null $effectiveDate Fecha YYYY-MM-DD (default hoy America/Mexico_City)
     * @param int|null    $expectedContractId Si se indica, el movimiento debe pertenecer a ese contrato
     */
    public function createReversalMovement(
        int $originalMovementId,
        int $userId,
        string $reason,
        ?string $effectiveDate = null,
        ?int $expectedContractId = null,
    ): Result {
        if ($originalMovementId < 1) {
            return Result::fail('Identificador de movimiento original inválido.', 422);
        }
        if ($userId < 1) {
            return Result::fail('user_id es requerido.', 422);
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            return Result::fail('El motivo (reason) es obligatorio (mínimo 5 caracteres).', 422);
        }
        $reason = mb_substr($reason, 0, 255);

        $effective = $effectiveDate !== null ? trim($effectiveDate) : '';
        if ($effective === '') {
            $effective = (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))
                ->format('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) {
            return Result::fail('effective_date inválida (YYYY-MM-DD).', 422);
        }

        $original = Capsule::table('movements')->where('id', $originalMovementId)->first();
        if ($original === null) {
            return Result::fail('Movimiento original no encontrado.', 404);
        }

        $contractId = (int) $original->contract_id;
        if ($expectedContractId !== null && $contractId !== $expectedContractId) {
            return Result::fail('El movimiento no pertenece al contrato indicado.', 422);
        }

        // No se permite revertir un asiento que ya es una reversión.
        if ($original->reversed_movement_id !== null) {
            return Result::fail(
                'No se puede revertir un movimiento que ya es una reversión contable.',
                422,
            );
        }

        $already = Capsule::table('movements')
            ->where('reversed_movement_id', $originalMovementId)
            ->exists();
        if ($already) {
            return Result::fail('Este movimiento ya fue revertido anteriormente.', 422);
        }

        $originalDelta = FinancialCalculator::normalizeMoney((string) $original->mrr_delta_mxn);
        if (bccomp($originalDelta, '0.00', 2) === 0) {
            return Result::fail(
                'No se puede revertir un movimiento con delta MRR neutral (0.00).',
                422,
            );
        }

        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        if ($contract === null) {
            return Result::fail('Contrato asociado al movimiento no encontrado.', 404);
        }

        $inverseDelta = bcmul($originalDelta, '-1', 2);
        $mrrBefore = FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn);
        $mrrAfter = bcadd($mrrBefore, $inverseDelta, 2);
        if (bccomp($mrrAfter, '0.00', 2) < 0) {
            return Result::fail(
                'La reversión dejaría el MRR del contrato en negativo (' . $mrrAfter . ').',
                422,
            );
        }

        $fullReason = mb_substr(
            'Reversión de movimiento #' . $originalMovementId . ': ' . $reason,
            0,
            255,
        );

        try {
            // Valida inmutabilidad / payload base (tipo reversal + reversed_movement_id).
            $base = FinancialCalculator::buildReversalPayload(
                [
                    'id' => (int) $original->id,
                    'mrr_category' => (string) $original->mrr_category,
                    'mrr_before' => (string) $original->mrr_before,
                    'mrr_after' => (string) $original->mrr_after,
                    'mrr_delta_mxn' => $originalDelta,
                    'quantity_before' => (string) $original->quantity_before,
                    'quantity_after' => (string) $original->quantity_after,
                ],
                $userId,
                $fullReason,
            );
        } catch (Throwable $exception) {
            return Result::fail($exception->getMessage(), 500);
        }

        // Impacto contable contra el MRR vigente del contrato (no el histórico del asiento).
        $payload = array_merge($base, [
            'contract_id' => $contractId,
            'contract_line_id' => $original->contract_line_id !== null
                ? (int) $original->contract_line_id
                : null,
            'service_id' => $original->service_id !== null ? (int) $original->service_id : null,
            'effective_date' => $effective,
            'mrr_before' => $mrrBefore,
            'mrr_after' => $mrrAfter,
            'mrr_delta_mxn' => $inverseDelta,
            'exchange_rate' => (string) ($original->exchange_rate ?? '1.000000'),
            'request_id' => null,
            'has_exception' => false,
            'excepted_policy' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        try {
            $row = Capsule::connection()->transaction(function () use ($payload, $contractId, $mrrAfter) {
                $inserted = $this->insertMovementRowAndSync($payload);
                Capsule::table('contracts')->where('id', $contractId)->update([
                    'mrr_mxn' => $mrrAfter,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

                return $inserted;
            });
        } catch (Throwable $exception) {
            return Result::fail(
                'No se pudo registrar la reversión contable: ' . $exception->getMessage(),
                500,
            );
        }

        $mapped = $this->mapRow($row);
        $mapped['contract_mrr_mxn'] = $mrrAfter;
        $mapped['is_reversed'] = false;

        return Result::ok($mapped, 'Reversión contable registrada', 201);
    }

    /**
     * INSERT inmutable + UPSERT de `monthly_mrr` en una sola transacción (RF-10 / RNF-1 / RNF-3).
     *
     * @param array<string, mixed> $payload
     */
    private function insertMovementAndSyncMrr(array $payload, string $okMessage): Result
    {
        try {
            $row = Capsule::connection()->transaction(function () use ($payload) {
                return $this->insertMovementRowAndSync($payload);
            });
        } catch (Throwable $exception) {
            return Result::fail(
                'No se pudo registrar el movimiento ni sincronizar el MRR mensual: ' . $exception->getMessage(),
                500,
            );
        }

        return Result::ok($this->mapRow($row), $okMessage, 201);
    }

    /**
     * Inserta movimiento + sync MRR asumiendo que ya hay una transacción abierta
     * (p. ej. alta atómica de contrato en ContractsService).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordWithinTransaction(array $payload): array
    {
        $row = $this->insertMovementRowAndSync($payload);

        return $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertMovementRowAndSync(array $payload): object
    {
        $id = Capsule::table('movements')->insertGetId($payload);
        $this->syncMonthlyMrrFromMovement($payload);
        $inserted = Capsule::table('movements')->where('id', $id)->first();
        if ($inserted === null) {
            throw new \RuntimeException('No se pudo leer el movimiento insertado.');
        }

        return $inserted;
    }

    /**
     * Actualiza o inserta el acumulado de `monthly_mrr` sumando con bcadd (escala 2).
     * `month` = YYYY-MM derivado de effective_date (corte conceptual YYYY-MM-01).
     *
     * @param array<string, mixed> $movement
     */
    private function syncMonthlyMrrFromMovement(array $movement): void
    {
        $category = (string) ($movement['mrr_category'] ?? '');
        if ($category === 'transfer' || $category === '') {
            return;
        }
        if (!in_array($category, FinancialCalculator::MRR_BRIDGE_CATEGORIES, true)) {
            return;
        }

        $month = FinancialCalculator::monthKeyFromEffectiveDate((string) $movement['effective_date']);
        $delta = FinancialCalculator::normalizeMoney((string) ($movement['mrr_delta_mxn'] ?? '0.00'));
        $dims = $this->resolveMrrDimensions(
            (int) $movement['contract_id'],
            isset($movement['service_id']) && $movement['service_id'] !== null && $movement['service_id'] !== ''
                ? (int) $movement['service_id']
                : null,
        );

        $query = Capsule::table('monthly_mrr')
            ->where('month', $month)
            ->where('category', $category)
            ->where('segment_id', $dims['segment_id']);

        if ($dims['issuer_id'] === null) {
            $query->whereNull('issuer_id');
        } else {
            $query->where('issuer_id', $dims['issuer_id']);
        }

        if ($dims['service_id'] === null) {
            $query->whereNull('service_id');
        } else {
            $query->where('service_id', $dims['service_id']);
        }

        $existing = $query->lockForUpdate()->first();
        $nowUtc = gmdate('Y-m-d H:i:s');

        if ($existing !== null) {
            $newAmount = FinancialCalculator::accumulateMonthlyMrrAmount(
                (string) $existing->amount,
                $delta,
            );
            Capsule::table('monthly_mrr')->where('id', (int) $existing->id)->update([
                'amount' => $newAmount,
                'updated_at' => $nowUtc,
            ]);

            return;
        }

        Capsule::table('monthly_mrr')->insert([
            'month' => $month,
            'segment_id' => $dims['segment_id'],
            'issuer_id' => $dims['issuer_id'],
            'service_id' => $dims['service_id'],
            'category' => $category,
            'amount' => $delta,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);
    }

    /**
     * Resuelve dimensiones del agregado desde contrato → cliente.
     *
     * @return array{segment_id: int, issuer_id: int|null, service_id: int|null}
     */
    private function resolveMrrDimensions(int $contractId, ?int $serviceId): array
    {
        $row = Capsule::table('contracts as c')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->where('c.id', $contractId)
            ->first(['c.segment_id', 'c.issuer_id as contract_issuer_id', 'cl.issuer_id as client_issuer_id']);

        if ($row === null) {
            throw new \RuntimeException("Contrato {$contractId} no encontrado para sincronizar monthly_mrr.");
        }

        $issuerId = $row->contract_issuer_id !== null
            ? (int) $row->contract_issuer_id
            : ($row->client_issuer_id !== null ? (int) $row->client_issuer_id : null);

        return [
            'segment_id' => (int) $row->segment_id,
            'issuer_id' => $issuerId,
            'service_id' => $serviceId,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function listRecent(array $query): array
    {
        $limit = max(1, min(200, (int) ($query['limit'] ?? 50)));
        $builder = Capsule::table('movements as m')
            ->leftJoin('service_requests as sr', 'sr.id', '=', 'm.request_id')
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->limit($limit)
            ->select(['m.*', 'sr.folio as request_folio']);

        if (!empty($query['contract_id'])) {
            $builder->where('m.contract_id', (int) $query['contract_id']);
        }
        if (!empty($query['mrr_category'])) {
            $builder->where('m.mrr_category', (string) $query['mrr_category']);
        }

        return $builder->get()->map(fn (object $row): array => $this->mapRow($row))->all();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeMoney(array $input): array
    {
        foreach (['mrr_before', 'mrr_after', 'mrr_delta_mxn', 'quantity_before', 'quantity_after'] as $field) {
            if (!isset($input[$field])) {
                continue;
            }
            $raw = trim((string) $input[$field]);
            if ($raw === '' || !preg_match('/^-?\d+(\.\d{1,2})?$/', $raw)) {
                continue;
            }
            if (!str_contains($raw, '.')) {
                $raw .= '.00';
            } elseif (preg_match('/^-?\d+\.\d$/', $raw)) {
                $raw .= '0';
            }
            $input[$field] = $raw;
        }

        if (
            isset($input['mrr_before'], $input['mrr_after'])
            && (!isset($input['mrr_delta_mxn']) || trim((string) $input['mrr_delta_mxn']) === '')
        ) {
            $input['mrr_delta_mxn'] = bcsub(
                (string) $input['mrr_after'],
                (string) $input['mrr_before'],
                2,
            );
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'contract_id' => (int) $row->contract_id,
            'contract_line_id' => $row->contract_line_id !== null ? (int) $row->contract_line_id : null,
            'service_id' => $row->service_id !== null ? (int) $row->service_id : null,
            'movement_type' => (string) $row->movement_type,
            'mrr_category' => (string) $row->mrr_category,
            'effective_date' => (string) $row->effective_date,
            'quantity_before' => (string) $row->quantity_before,
            'quantity_after' => (string) $row->quantity_after,
            'mrr_before' => (string) $row->mrr_before,
            'mrr_after' => (string) $row->mrr_after,
            'mrr_delta_mxn' => (string) $row->mrr_delta_mxn,
            'exchange_rate' => (string) $row->exchange_rate,
            'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
            'request_id' => $row->request_id !== null ? (int) $row->request_id : null,
            'request_folio' => isset($row->request_folio) && $row->request_folio !== null
                ? (string) $row->request_folio
                : null,
            'reason' => $row->reason !== null ? (string) $row->reason : null,
            'has_exception' => (bool) $row->has_exception,
            'excepted_policy' => isset($row->excepted_policy) && $row->excepted_policy !== null
                ? (string) $row->excepted_policy
                : null,
            'reversed_movement_id' => isset($row->reversed_movement_id) && $row->reversed_movement_id !== null
                ? (int) $row->reversed_movement_id
                : null,
            'created_at' => (string) $row->created_at,
        ];
    }
}
