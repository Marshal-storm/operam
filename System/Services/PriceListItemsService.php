<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PriceListItemsModel;

/**
 * CRUD de ítems / escalas de lista de precios (`lista_precio_item`).
 * RF-2: rangos por volumen `[cantidad_min, cantidad_max]` sin traslapes.
 * Montos siempre DECIMAL(14,2) como string — sin float.
 */
final class PriceListItemsService extends ModelService
{
    protected function modelClass(): string
    {
        return PriceListItemsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('lista_precio_id', DataTypes::Integer, true)
            ->setField('servicio_id', DataTypes::Integer, true)
            ->setField('frecuencia', DataTypes::String, true)
            ->setField('cantidad_min', DataTypes::Integer, false, 1)
            ->setField('cantidad_max', DataTypes::Integer, false)
            ->setField('precio_unitario', DataTypes::String, true);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listEnriched(), 'Ítems de precio');
        }
        if ($method === 'POST' && $id === null) {
            $input = $this->normalizeScale($this->normalizeMoney($context->getRequest()));
            $scaleError = $this->assertValidScale($input, null);
            if ($scaleError !== null) {
                return Result::fail($scaleError, 422);
            }

            return $this->create($input, $context);
        }
        // Quitar producto completo de una lista (todas las frecuencias / escalas).
        if ($method === 'DELETE' && $id === null) {
            return $this->removeProductFromList($context->getRequest());
        }
        if ($id === null) {
            return Result::fail('Se requiere id de ítem.', 400);
        }

        $itemId = (int) $id;
        if ($method === 'PUT') {
            $input = $this->normalizeScale($this->normalizeMoney($context->getRequest()));
            $scaleError = $this->assertValidScale($input, $itemId);
            if ($scaleError !== null) {
                return Result::fail($scaleError, 422);
            }

            return $this->update($itemId, $input, $context);
        }
        if ($method === 'PATCH') {
            $existing = $this->findModel($itemId);
            if ($existing === null) {
                return Result::fail('Ítem no encontrado.', 404);
            }
            $merged = array_merge($existing->toArray(), $this->normalizeMoney($context->getRequest()));
            $merged = $this->normalizeScale($merged);
            $scaleError = $this->assertValidScale($merged, $itemId);
            if ($scaleError !== null) {
                return Result::fail($scaleError, 422);
            }

            return $this->patch($itemId, $this->normalizeScale($this->normalizeMoney($context->getRequest())), $context);
        }
        if ($method === 'DELETE') {
            $model = $this->findModel($itemId);
            if ($model === null) {
                return Result::fail('Ítem no encontrado.', 404);
            }
            $model->delete();

            return Result::ok(['id' => $itemId], 'Ítem eliminado');
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function removeProductFromList(array $input): Result
    {
        $listaId = (int) ($input['lista_precio_id'] ?? 0);
        $servicioId = (int) ($input['servicio_id'] ?? 0);

        if ($listaId < 1 || $servicioId < 1) {
            return Result::fail('lista_precio_id y servicio_id son requeridos.', 400);
        }

        $deleted = Capsule::table('lista_precio_item')
            ->where('lista_precio_id', $listaId)
            ->where('servicio_id', $servicioId)
            ->delete();

        if ($deleted === 0) {
            return Result::fail('No hay ítems de ese producto en la lista.', 404);
        }

        return Result::ok(
            [
                'lista_precio_id' => $listaId,
                'servicio_id' => $servicioId,
                'deleted' => $deleted,
            ],
            'Producto quitado de la lista',
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeMoney(array $input): array
    {
        if (isset($input['precio_unitario'])) {
            $raw = trim((string) $input['precio_unitario']);
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
                $input['precio_unitario'] = '';
            } else {
                if (!str_contains($raw, '.')) {
                    $raw .= '.00';
                } elseif (preg_match('/^\d+\.\d$/', $raw)) {
                    $raw .= '0';
                }
                $input['precio_unitario'] = $raw;
            }
        }

        return $input;
    }

    /**
     * Normaliza cantidad_min (>=1) y cantidad_max (null = en adelante).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeScale(array $input): array
    {
        if (array_key_exists('cantidad_min', $input)) {
            $input['cantidad_min'] = max(1, (int) $input['cantidad_min']);
        }
        if (array_key_exists('cantidad_max', $input)) {
            $raw = $input['cantidad_max'];
            if ($raw === null || $raw === '' || $raw === false) {
                $input['cantidad_max'] = null;
            } else {
                $input['cantidad_max'] = (int) $raw;
            }
        }

        return $input;
    }

    /**
     * Valida rango individual y ausencia de traslapes por
     * lista_precio_id + servicio_id + frecuencia.
     *
     * @param array<string, mixed> $input
     */
    private function assertValidScale(array $input, ?int $exceptId): ?string
    {
        $listaId = (int) ($input['lista_precio_id'] ?? 0);
        $servicioId = (int) ($input['servicio_id'] ?? 0);
        $frecuencia = trim((string) ($input['frecuencia'] ?? ''));
        $min = (int) ($input['cantidad_min'] ?? 1);
        $max = array_key_exists('cantidad_max', $input) && $input['cantidad_max'] !== null
            ? (int) $input['cantidad_max']
            : null;

        if ($listaId < 1 || $servicioId < 1 || $frecuencia === '') {
            return 'lista_precio_id, servicio_id y frecuencia son requeridos para la escala.';
        }
        if ($min < 1) {
            return 'cantidad_min debe ser un entero mayor o igual a 1.';
        }
        if ($max !== null && $max < $min) {
            return 'cantidad_max no puede ser menor que cantidad_min.';
        }

        $query = Capsule::table('lista_precio_item')
            ->where('lista_precio_id', $listaId)
            ->where('servicio_id', $servicioId)
            ->where('frecuencia', $frecuencia);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $siblings = $query->get(['id', 'cantidad_min', 'cantidad_max']);
        foreach ($siblings as $sibling) {
            $sibMin = (int) $sibling->cantidad_min;
            $sibMax = $sibling->cantidad_max !== null ? (int) $sibling->cantidad_max : null;
            if ($this->rangesOverlap($min, $max, $sibMin, $sibMax)) {
                return sprintf(
                    'El rango %s se traslapa con el escalón existente %s (misma lista, producto y frecuencia).',
                    $this->formatRange($min, $max),
                    $this->formatRange($sibMin, $sibMax),
                );
            }
        }

        return null;
    }

    private function rangesOverlap(int $aMin, ?int $aMax, int $bMin, ?int $bMax): bool
    {
        $aHi = $aMax ?? PHP_INT_MAX;
        $bHi = $bMax ?? PHP_INT_MAX;

        return $aMin <= $bHi && $bMin <= $aHi;
    }

    private function formatRange(int $min, ?int $max): string
    {
        if ($max === null) {
            return sprintf('%d–∞', $min);
        }

        return sprintf('%d–%d', $min, $max);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listEnriched(): array
    {
        $rows = Capsule::table('lista_precio_item as lpi')
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
            ]);

        return $rows->map(static function (object $item): array {
            return [
                'id' => (int) $item->id,
                'lista_precio_id' => (int) $item->lista_precio_id,
                'servicio_id' => (int) $item->servicio_id,
                'servicio_codigo' => (string) ($item->servicio_codigo ?? ''),
                'servicio_nombre' => (string) ($item->servicio_nombre ?? ''),
                'frecuencia' => (string) $item->frecuencia,
                'cantidad_min' => (int) $item->cantidad_min,
                'cantidad_max' => $item->cantidad_max !== null ? (int) $item->cantidad_max : null,
                'precio_unitario' => (string) $item->precio_unitario,
            ];
        })->all();
    }
}
