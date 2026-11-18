<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PriceListsModel;
use Throwable;

final class PriceListsService extends ModelService
{
    protected function modelClass(): string
    {
        return PriceListsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('segmento_id', DataTypes::Integer, true)
            ->setField('nombre', DataTypes::String, true)
            ->setField('moneda', DataTypes::String, true)
            ->setField('es_default', DataTypes::Boolean, false, false)
            ->setField('vigente_desde', DataTypes::Date, true)
            ->setField('vigente_hasta', DataTypes::Date, false)
            ->setField('activa', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $auth = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery($auth, $context->getRequest());

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listEnriched($auth), 'Listas de precios');
        }
        if ($method === 'POST' && $id === null) {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segmento_id'] ?? null) !== null) {
                $request['segmento_id'] = (int) $auth['segmento_id'];
            }

            return $this->persistWithUniqueDefault($request, null, $context, 'create');
        }
        if ($id === null) {
            return Result::fail('Se requiere id de lista.', 400);
        }

        $listId = (int) $id;
        if ($method === 'PUT') {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segmento_id'] ?? null) !== null) {
                $request['segmento_id'] = (int) $auth['segmento_id'];
            }

            return $this->persistWithUniqueDefault($request, $listId, $context, 'update');
        }
        if ($method === 'PATCH') {
            return $this->persistWithUniqueDefault($request, $listId, $context, 'patch');
        }
        if ($method === 'DELETE') {
            return $this->patch($listId, ['activa' => false], $context);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * Guarda la lista y, si queda como default, desmarca las demás del mismo segmento+moneda.
     *
     * @param array<string, mixed> $request
     */
    private function persistWithUniqueDefault(
        array $request,
        ?int $listId,
        Context $context,
        string $mode,
    ): Result {
        try {
            return Capsule::connection()->transaction(function () use ($request, $listId, $context, $mode): Result {
                $result = match ($mode) {
                    'create' => $this->create($request, $context),
                    'update' => $this->update((int) $listId, $request, $context),
                    default => $this->patch((int) $listId, $request, $context),
                };

                if (!$result->isSuccess()) {
                    throw new RuntimeException($result->getMessage());
                }

                $savedId = $listId ?? (int) (($result->getData()['id'] ?? 0));
                if ($savedId < 1) {
                    return $result;
                }

                $row = Capsule::table('lista_precio')->where('id', $savedId)->first();
                if ($row !== null && (bool) (int) $row->es_default) {
                    Capsule::table('lista_precio')
                        ->where('segmento_id', (int) $row->segmento_id)
                        ->where('moneda', (string) $row->moneda)
                        ->where('id', '!=', $savedId)
                        ->update([
                            'es_default' => false,
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                }

                return $result;
            });
        } catch (Throwable $exception) {
            return Result::fail($exception->getMessage(), 422);
        }
    }

    /**
     * @param array<string, mixed> $auth
     * @return list<array<string, mixed>>
     */
    private function listEnriched(array $auth = []): array
    {
        $builder = Capsule::table('lista_precio as lp')
            ->leftJoin('segmento as seg', 'seg.id', '=', 'lp.segmento_id');

        if (($auth['segmento_id'] ?? null) !== null) {
            $builder->where('lp.segmento_id', (int) $auth['segmento_id']);
        }

        $lists = $builder
            ->orderBy('lp.id')
            ->get([
                'lp.id',
                'lp.nombre',
                'lp.segmento_id',
                'seg.nombre as segmento',
                'lp.moneda',
                'lp.es_default',
                'lp.vigente_desde',
                'lp.vigente_hasta',
                'lp.activa',
            ]);

        // Orden por cantidad_min para que el frontend agrupe escalones RF-2 en secuencia.
        $items = Capsule::table('lista_precio_item as lpi')
            ->leftJoin('servicio as s', 's.id', '=', 'lpi.servicio_id')
            ->orderBy('lpi.lista_precio_id')
            ->orderBy('lpi.servicio_id')
            ->orderBy('lpi.cantidad_min')
            ->orderBy('lpi.frecuencia')
            ->get([
                'lpi.id',
                'lpi.lista_precio_id',
                'lpi.servicio_id',
                's.codigo as servicio_codigo',
                's.nombre as servicio_nombre',
                'lpi.frecuencia',
                'lpi.cantidad_min',
                'lpi.cantidad_max',
                'lpi.precio_unitario',
            ])
            ->groupBy('lista_precio_id');

        return $lists->map(static function (object $list) use ($items): array {
            $listItems = ($items->get($list->id) ?? collect())->map(static function (object $item): array {
                return [
                    'id' => (int) $item->id,
                    'servicio_id' => (int) $item->servicio_id,
                    'servicio_codigo' => (string) ($item->servicio_codigo ?? ''),
                    'servicio_nombre' => (string) ($item->servicio_nombre ?? ''),
                    'frecuencia' => (string) $item->frecuencia,
                    'cantidad_min' => (int) $item->cantidad_min,
                    'cantidad_max' => $item->cantidad_max !== null ? (int) $item->cantidad_max : null,
                    'precio_unitario' => (string) $item->precio_unitario,
                ];
            })->values()->all();

            return [
                'id' => (int) $list->id,
                'nombre' => (string) $list->nombre,
                'segmento' => (string) ($list->segmento ?? ''),
                'segmento_id' => (int) $list->segmento_id,
                'moneda' => (string) $list->moneda,
                'es_default' => (bool) $list->es_default,
                'vigente_desde' => (string) $list->vigente_desde,
                'vigente_hasta' => $list->vigente_hasta !== null ? (string) $list->vigente_hasta : null,
                'activa' => (bool) $list->activa,
                'items' => $listItems,
            ];
        })->all();
    }
}
