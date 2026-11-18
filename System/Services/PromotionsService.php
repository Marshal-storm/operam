<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PromotionsModel;

final class PromotionsService extends ModelService
{
    protected function modelClass(): string
    {
        return PromotionsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('codigo', DataTypes::String, true)
            ->setField('nombre', DataTypes::String, true)
            ->setField('segmento_id', DataTypes::Integer, false)
            ->setField('tipo_descuento', DataTypes::String, true)
            ->setField('valor_descuento', DataTypes::String, true)
            ->setField('aplica_a', DataTypes::String, false, 'recurrente')
            ->setField('vigente_desde', DataTypes::Date, true)
            ->setField('vigente_hasta', DataTypes::Date, false)
            ->setField('usos_maximos', DataTypes::Integer, false)
            ->setField('usos_actuales', DataTypes::Integer, false, 0)
            ->setField('activa', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $auth = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery($auth, $context->getRequest());

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listEnriched($auth), 'Promociones');
        }
        if ($method === 'POST' && $id === null) {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segmento_id'] ?? null) !== null) {
                $request['segmento_id'] = (int) $auth['segmento_id'];
            }
            $input = $this->normalizeMoney($request);

            return $this->create($input, $context);
        }
        if ($id === null) {
            return Result::fail('Se requiere id de promoción.', 400);
        }

        $promoId = (int) $id;
        if ($method === 'PUT') {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segmento_id'] ?? null) !== null) {
                $request['segmento_id'] = (int) $auth['segmento_id'];
            }

            return $this->update($promoId, $this->normalizeMoney($request), $context);
        }
        if ($method === 'PATCH') {
            return $this->patch($promoId, $this->normalizeMoney($request), $context);
        }
        if ($method === 'DELETE') {
            return $this->patch($promoId, ['activa' => false], $context);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeMoney(array $input): array
    {
        if (isset($input['valor_descuento'])) {
            $raw = trim((string) $input['valor_descuento']);
            if (preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
                if (!str_contains($raw, '.')) {
                    $raw .= '.00';
                } elseif (preg_match('/^\d+\.\d$/', $raw)) {
                    $raw .= '0';
                }
                $input['valor_descuento'] = $raw;
            }
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $auth
     * @return list<array<string, mixed>>
     */
    private function listEnriched(array $auth = []): array
    {
        $builder = Capsule::table('promocion as p')
            ->leftJoin('segmento as seg', 'seg.id', '=', 'p.segmento_id');

        if (($auth['segmento_id'] ?? null) !== null) {
            $builder->where(static function ($q) use ($auth): void {
                $q->where('p.segmento_id', (int) $auth['segmento_id'])
                    ->orWhereNull('p.segmento_id');
            });
        }

        $rows = $builder
            ->orderBy('p.id')
            ->get([
                'p.id',
                'p.codigo',
                'p.nombre',
                'p.segmento_id',
                'seg.nombre as segmento',
                'p.tipo_descuento',
                'p.valor_descuento',
                'p.aplica_a',
                'p.vigente_desde',
                'p.vigente_hasta',
                'p.usos_maximos',
                'p.usos_actuales',
                'p.activa',
            ]);

        return $rows->map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'codigo' => (string) $row->codigo,
                'nombre' => (string) $row->nombre,
                'segmento' => $row->segmento !== null ? (string) $row->segmento : null,
                'segmento_id' => $row->segmento_id !== null ? (int) $row->segmento_id : null,
                'tipo_descuento' => (string) $row->tipo_descuento,
                'valor_descuento' => (string) $row->valor_descuento,
                'aplica_a' => (string) $row->aplica_a,
                'vigente_desde' => (string) $row->vigente_desde,
                'vigente_hasta' => $row->vigente_hasta !== null ? (string) $row->vigente_hasta : null,
                'usos_maximos' => $row->usos_maximos !== null ? (int) $row->usos_maximos : null,
                'usos_actuales' => (int) $row->usos_actuales,
                'activa' => (bool) $row->activa,
            ];
        })->all();
    }
}
