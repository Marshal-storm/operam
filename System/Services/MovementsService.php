<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\MovementsModel;

/**
 * Servicio de bitácora `movimiento` (RNF-3).
 * Solo INSERT y reversiones contables; UPDATE/PATCH/DELETE bloqueados.
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

            return $this->create($input, $context);
        }

        if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            return Result::fail(self::IMMUTABLE_MESSAGE, 403);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * Inserta un movimiento inverso que referencia al original (RNF-3).
     * Invierte mrr_delta_mxn y cantidad/mrr antes↔después con bcmath.
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

        $delta = (string) $original->mrr_delta_mxn;
        $invertedDelta = bcmul($delta, '-1', 2);

        $payload = [
            'contrato_id' => (int) $original->contrato_id,
            'contrato_linea_id' => $original->contrato_linea_id !== null
                ? (int) $original->contrato_linea_id
                : null,
            'servicio_id' => $original->servicio_id !== null ? (int) $original->servicio_id : null,
            'tipo' => 'reversión',
            'categoria_mrr' => (string) $original->categoria_mrr,
            'fecha_efectiva' => (string) $original->fecha_efectiva,
            'cantidad_antes' => (string) $original->cantidad_despues,
            'cantidad_despues' => (string) $original->cantidad_antes,
            'mrr_antes' => (string) $original->mrr_despues,
            'mrr_despues' => (string) $original->mrr_antes,
            'mrr_delta_mxn' => $invertedDelta,
            'tipo_cambio' => (string) ($original->tipo_cambio ?? '1.000000'),
            'usuario_id' => $userId,
            'solicitud_id' => null,
            'motivo' => mb_substr(trim($reason) !== '' ? trim($reason) : 'Reversión contable', 0, 255),
            'con_excepcion' => false,
            'movimiento_revertido_id' => $originalMovementId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        // Verificación aritmética: mrr_despues - mrr_antes == delta invertido.
        $check = bcsub($payload['mrr_despues'], $payload['mrr_antes'], 2);
        if (bccomp($check, $invertedDelta, 2) !== 0) {
            return Result::fail('Inconsistencia aritmética al construir la reversión.', 500);
        }

        $newId = Capsule::table('movimiento')->insertGetId($payload);
        $row = Capsule::table('movimiento')->where('id', $newId)->first();

        return Result::ok(
            $row !== null ? $this->mapRow($row) : ['id' => $newId],
            'Reversión contable registrada',
            201,
        );
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
