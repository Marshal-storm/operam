<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PriceListItemsModel;

/**
 * CRUD de ítems / escalas de lista de precios (`price_list_items`).
 * RF-2: rangos por volumen `[min_quantity, max_quantity]` sin traslapes.
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
            ->setField('price_list_id', DataTypes::Integer, true)
            ->setField('service_id', DataTypes::Integer, true)
            ->setField('frequency', DataTypes::String, true)
            ->setField('min_quantity', DataTypes::Integer, false, 1)
            ->setField('max_quantity', DataTypes::Integer, false)
            ->setField('unit_price', DataTypes::String, true);
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
        $priceListId = (int) ($input['price_list_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);

        if ($priceListId < 1 || $serviceId < 1) {
            return Result::fail('price_list_id y service_id son requeridos.', 400);
        }

        $deleted = Capsule::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('service_id', $serviceId)
            ->delete();

        if ($deleted === 0) {
            return Result::fail('No hay ítems de ese producto en la lista.', 404);
        }

        return Result::ok(
            [
                'price_list_id' => $priceListId,
                'service_id' => $serviceId,
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
        if (isset($input['unit_price'])) {
            $raw = trim((string) $input['unit_price']);
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
                $input['unit_price'] = '';
            } else {
                if (!str_contains($raw, '.')) {
                    $raw .= '.00';
                } elseif (preg_match('/^\d+\.\d$/', $raw)) {
                    $raw .= '0';
                }
                $input['unit_price'] = $raw;
            }
        }

        return $input;
    }

    /**
     * Normaliza min_quantity (>=1) y max_quantity (null = en adelante).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeScale(array $input): array
    {
        if (array_key_exists('min_quantity', $input)) {
            $input['min_quantity'] = max(1, (int) $input['min_quantity']);
        }
        if (array_key_exists('max_quantity', $input)) {
            $raw = $input['max_quantity'];
            if ($raw === null || $raw === '' || $raw === false) {
                $input['max_quantity'] = null;
            } else {
                $input['max_quantity'] = (int) $raw;
            }
        }

        return $input;
    }

    /**
     * Valida rango individual y ausencia de traslapes por
     * price_list_id + service_id + frequency.
     *
     * @param array<string, mixed> $input
     */
    private function assertValidScale(array $input, ?int $exceptId): ?string
    {
        $priceListId = (int) ($input['price_list_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);
        $frequency = trim((string) ($input['frequency'] ?? ''));
        $min = (int) ($input['min_quantity'] ?? 1);
        $max = array_key_exists('max_quantity', $input) && $input['max_quantity'] !== null
            ? (int) $input['max_quantity']
            : null;

        if ($priceListId < 1 || $serviceId < 1 || $frequency === '') {
            return 'price_list_id, service_id y frequency son requeridos para la escala.';
        }
        if ($min < 1) {
            return 'min_quantity debe ser un entero mayor o igual a 1.';
        }
        if ($max !== null && $max < $min) {
            return 'max_quantity no puede ser menor que min_quantity.';
        }

        $query = Capsule::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('service_id', $serviceId)
            ->where('frequency', $frequency);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $siblings = $query->get(['id', 'min_quantity', 'max_quantity']);
        foreach ($siblings as $sibling) {
            $sibMin = (int) $sibling->min_quantity;
            $sibMax = $sibling->max_quantity !== null ? (int) $sibling->max_quantity : null;
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
        $rows = Capsule::table('price_list_items as lpi')
            ->leftJoin('services as s', 's.id', '=', 'lpi.service_id')
            ->orderBy('lpi.price_list_id')
            ->orderBy('lpi.service_id')
            ->orderBy('lpi.min_quantity')
            ->orderBy('lpi.frequency')
            ->get([
                'lpi.id',
                'lpi.price_list_id',
                'lpi.service_id',
                's.code as service_code',
                's.name as service_name',
                'lpi.frequency',
                'lpi.min_quantity',
                'lpi.max_quantity',
                'lpi.unit_price',
            ]);

        return $rows->map(static function (object $item): array {
            return [
                'id' => (int) $item->id,
                'price_list_id' => (int) $item->price_list_id,
                'service_id' => (int) $item->service_id,
                'service_code' => (string) ($item->service_code ?? ''),
                'service_name' => (string) ($item->service_name ?? ''),
                'frequency' => (string) $item->frequency,
                'min_quantity' => (int) $item->min_quantity,
                'max_quantity' => $item->max_quantity !== null ? (int) $item->max_quantity : null,
                'unit_price' => (string) $item->unit_price,
            ];
        })->all();
    }
}
