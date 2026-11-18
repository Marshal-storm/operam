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
 * Servicio de bitácora `movimiento` (RNF-3 + RF-10).
 * Solo INSERT y reversiones contables; UPDATE/PATCH/DELETE bloqueados.
 * Cada INSERT sincroniza `mrr_mensual` en la misma transacción (bcadd).
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
            ->setField('contrato_id', DataTypes::Integer, true)
            ->setField('contrato_linea_id', DataTypes::Integer, false)
            ->setField('servicio_id', DataTypes::Integer, false)
            ->setField('tipo', DataTypes::String, true)
            ->setField('categoria_mrr', DataTypes::String, true)
            ->setField('fecha_efectiva', DataTypes::Date, true)
            ->setField('cantidad_antes', DataTypes::String, false, '0.00')
            ->setField('cantidad_despues', DataTypes::String, false, '0.00')
            ->setField('mrr_antes', DataTypes::String, true)
            ->setField('mrr_despues', DataTypes::String, true)
            ->setField('mrr_delta_mxn', DataTypes::String, false)
            ->setField('tipo_cambio', DataTypes::String, false, '1.000000')
            ->setField('usuario_id', DataTypes::Integer, false)
            ->setField('solicitud_id', DataTypes::Integer, false)
            ->setField('motivo', DataTypes::String, false)
            ->setField('con_excepcion', DataTypes::Boolean, false, false)
            ->setField('movimiento_revertido_id', DataTypes::Integer, false);
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
            $row = Capsule::table('movimiento')->where('id', (int) $id)->first();
            if ($row === null) {
                return Result::fail('Movimiento no encontrado.', 404);
            }

            return Result::ok($this->mapRow($row), 'Movimiento');
        }

        // POST /api/movements con reversal_of → reversión contable.
        if ($method === 'POST' && $id === null && isset($request['reversal_of'])) {
            $auth = $context->getData('auth', []);
            $userId = (int) ($request['usuario_id'] ?? $auth['user_id'] ?? 0);
            $reason = trim((string) ($request['motivo'] ?? $request['reason'] ?? 'Reversión contable'));
            if ($userId < 1) {
                return Result::fail('usuario_id es requerido para la reversión.', 422);
            }

            return $this->createReversalMovement((int) $request['reversal_of'], $userId, $reason);
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
     * Invierte mrr_delta_mxn y cantidad/mrr antes↔después con bcmath.
     * Sincroniza `mrr_mensual` en la misma transacción (RF-10).
     */
    public function createReversalMovement(int $originalMovementId, int $userId, string $reason): Result
    {
        if ($originalMovementId < 1) {
            return Result::fail('Identificador de movimiento original inválido.', 422);
        }
        if ($userId < 1) {
            return Result::fail('usuario_id es requerido.', 422);
        }

        $original = Capsule::table('movimiento')->where('id', $originalMovementId)->first();
        if ($original === null) {
            return Result::fail('Movimiento original no encontrado.', 404);
        }

        $already = Capsule::table('movimiento')
            ->where('movimiento_revertido_id', $originalMovementId)
            ->exists();
        if ($already) {
            return Result::fail('Este movimiento ya tiene una reversión registrada.', 422);
        }

        try {
            $payload = FinancialCalculator::buildReversalPayload(
                [
                    'id' => (int) $original->id,
                    'categoria_mrr' => (string) $original->categoria_mrr,
                    'mrr_antes' => (string) $original->mrr_antes,
                    'mrr_despues' => (string) $original->mrr_despues,
                    'mrr_delta_mxn' => (string) $original->mrr_delta_mxn,
                    'cantidad_antes' => (string) $original->cantidad_antes,
                    'cantidad_despues' => (string) $original->cantidad_despues,
                ],
                $userId,
                $reason,
            );
        } catch (Throwable $exception) {
            return Result::fail($exception->getMessage(), 500);
        }

        $payload['contrato_id'] = (int) $original->contrato_id;
        $payload['contrato_linea_id'] = $original->contrato_linea_id !== null
            ? (int) $original->contrato_linea_id
            : null;
        $payload['servicio_id'] = $original->servicio_id !== null ? (int) $original->servicio_id : null;
        $payload['fecha_efectiva'] = (string) $original->fecha_efectiva;
        $payload['tipo_cambio'] = (string) ($original->tipo_cambio ?? '1.000000');
        $payload['solicitud_id'] = null;
        $payload['con_excepcion'] = false;
        $payload['created_at'] = gmdate('Y-m-d H:i:s');

        return $this->insertMovementAndSyncMrr($payload, 'Reversión contable registrada');
    }

    /**
     * INSERT inmutable + UPSERT de `mrr_mensual` en una sola transacción (RF-10 / RNF-1 / RNF-3).
     *
     * @param array<string, mixed> $payload
     */
    private function insertMovementAndSyncMrr(array $payload, string $okMessage): Result
    {
        try {
            $row = Capsule::connection()->transaction(function () use ($payload) {
                $id = Capsule::table('movimiento')->insertGetId($payload);
                $this->syncMrrMensualFromMovement($payload);
                $inserted = Capsule::table('movimiento')->where('id', $id)->first();
                if ($inserted === null) {
                    throw new \RuntimeException('No se pudo leer el movimiento insertado.');
                }

                return $inserted;
            });
        } catch (Throwable $exception) {
            return Result::fail(
                'No se pudo registrar el movimiento / sincronizar mrr_mensual: ' . $exception->getMessage(),
                500,
            );
        }

        return Result::ok($this->mapRow($row), $okMessage, 201);
    }

    /**
     * Actualiza o inserta el acumulado de `mrr_mensual` sumando con bcadd (escala 2).
     * `mes` = YYYY-MM derivado de fecha_efectiva (corte conceptual YYYY-MM-01).
     *
     * @param array<string, mixed> $movement
     */
    private function syncMrrMensualFromMovement(array $movement): void
    {
        $categoria = (string) ($movement['categoria_mrr'] ?? '');
        if ($categoria === 'transferencia' || $categoria === '') {
            return;
        }
        if (!in_array($categoria, FinancialCalculator::MRR_BRIDGE_CATEGORIES, true)) {
            return;
        }

        $mes = FinancialCalculator::monthKeyFromEffectiveDate((string) $movement['fecha_efectiva']);
        $delta = FinancialCalculator::normalizeMoney((string) ($movement['mrr_delta_mxn'] ?? '0.00'));
        $dims = $this->resolveMrrDimensions(
            (int) $movement['contrato_id'],
            isset($movement['servicio_id']) && $movement['servicio_id'] !== null && $movement['servicio_id'] !== ''
                ? (int) $movement['servicio_id']
                : null,
        );

        $query = Capsule::table('mrr_mensual')
            ->where('mes', $mes)
            ->where('categoria', $categoria)
            ->where('segmento_id', $dims['segmento_id']);

        if ($dims['emisor_id'] === null) {
            $query->whereNull('emisor_id');
        } else {
            $query->where('emisor_id', $dims['emisor_id']);
        }

        if ($dims['servicio_id'] === null) {
            $query->whereNull('servicio_id');
        } else {
            $query->where('servicio_id', $dims['servicio_id']);
        }

        $existing = $query->lockForUpdate()->first();
        $nowUtc = gmdate('Y-m-d H:i:s');

        if ($existing !== null) {
            $newMonto = FinancialCalculator::accumulateMrrMensualAmount(
                (string) $existing->monto,
                $delta,
            );
            Capsule::table('mrr_mensual')->where('id', (int) $existing->id)->update([
                'monto' => $newMonto,
                'updated_at' => $nowUtc,
            ]);

            return;
        }

        Capsule::table('mrr_mensual')->insert([
            'mes' => $mes,
            'segmento_id' => $dims['segmento_id'],
            'emisor_id' => $dims['emisor_id'],
            'servicio_id' => $dims['servicio_id'],
            'categoria' => $categoria,
            'monto' => $delta,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);
    }

    /**
     * Resuelve dimensiones del agregado desde contrato → cliente.
     *
     * @return array{segmento_id: int, emisor_id: int|null, servicio_id: int|null}
     */
    private function resolveMrrDimensions(int $contratoId, ?int $servicioId): array
    {
        $row = Capsule::table('contrato as c')
            ->leftJoin('cliente as cl', 'cl.id', '=', 'c.cliente_id')
            ->where('c.id', $contratoId)
            ->first(['c.segmento_id', 'cl.emisor_id']);

        if ($row === null) {
            throw new \RuntimeException("Contrato {$contratoId} no encontrado para sincronizar mrr_mensual.");
        }

        return [
            'segmento_id' => (int) $row->segmento_id,
            'emisor_id' => $row->emisor_id !== null ? (int) $row->emisor_id : null,
            'servicio_id' => $servicioId,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function listRecent(array $query): array
    {
        $limit = max(1, min(200, (int) ($query['limit'] ?? 50)));
        $builder = Capsule::table('movimiento')->orderByDesc('id')->limit($limit);

        if (!empty($query['contrato_id'])) {
            $builder->where('contrato_id', (int) $query['contrato_id']);
        }
        if (!empty($query['categoria_mrr'])) {
            $builder->where('categoria_mrr', (string) $query['categoria_mrr']);
        }

        return $builder->get()->map(fn (object $row): array => $this->mapRow($row))->all();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeMoney(array $input): array
    {
        foreach (['mrr_antes', 'mrr_despues', 'mrr_delta_mxn', 'cantidad_antes', 'cantidad_despues'] as $field) {
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
            isset($input['mrr_antes'], $input['mrr_despues'])
            && (!isset($input['mrr_delta_mxn']) || trim((string) $input['mrr_delta_mxn']) === '')
        ) {
            $input['mrr_delta_mxn'] = bcsub(
                (string) $input['mrr_despues'],
                (string) $input['mrr_antes'],
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
            'contrato_id' => (int) $row->contrato_id,
            'contrato_linea_id' => $row->contrato_linea_id !== null ? (int) $row->contrato_linea_id : null,
            'servicio_id' => $row->servicio_id !== null ? (int) $row->servicio_id : null,
            'tipo' => (string) $row->tipo,
            'categoria_mrr' => (string) $row->categoria_mrr,
            'fecha_efectiva' => (string) $row->fecha_efectiva,
            'cantidad_antes' => (string) $row->cantidad_antes,
            'cantidad_despues' => (string) $row->cantidad_despues,
            'mrr_antes' => (string) $row->mrr_antes,
            'mrr_despues' => (string) $row->mrr_despues,
            'mrr_delta_mxn' => (string) $row->mrr_delta_mxn,
            'tipo_cambio' => (string) $row->tipo_cambio,
            'usuario_id' => $row->usuario_id !== null ? (int) $row->usuario_id : null,
            'solicitud_id' => $row->solicitud_id !== null ? (int) $row->solicitud_id : null,
            'motivo' => $row->motivo !== null ? (string) $row->motivo : null,
            'con_excepcion' => (bool) $row->con_excepcion,
            'movimiento_revertido_id' => isset($row->movimiento_revertido_id) && $row->movimiento_revertido_id !== null
                ? (int) $row->movimiento_revertido_id
                : null,
            'created_at' => (string) $row->created_at,
        ];
    }
}
