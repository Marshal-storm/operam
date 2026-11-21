<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PriceListItemsModel;
use System\Support\AuditLogger;

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
        $auth = $context->getData('auth', []);

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listEnriched(), 'Ítems de precio');
        }
        if ($method === 'POST' && $id === null) {
            $input = $this->normalizeScale($this->normalizeMoney($context->getRequest()));
            $scaleError = $this->assertValidScale($input, null);
            if ($scaleError !== null) {
                return Result::fail($scaleError, 422);
            }

            $created = $this->create($input, $context);
            if ($created->isSuccess()) {
                $data = $created->getData();
                $newId = (int) (is_array($data) ? ($data['id'] ?? 0) : 0);
                if ($newId > 0) {
                    $this->auditItem($auth, $newId, 'create', null);
                }
            }

            return $created;
        }
        // Quitar producto completo de una lista (todas las frecuencias / escalas).
        if ($method === 'DELETE' && $id === null) {
            return $this->removeProductFromList($context->getRequest(), $auth);
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

            $before = $this->itemSnapshot($itemId);
            $updated = $this->update($itemId, $input, $context);
            if ($updated->isSuccess()) {
                $this->auditItem($auth, $itemId, 'update', $before);
            }

            return $updated;
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

            $before = $this->itemSnapshot($itemId);
            $patched = $this->patch($itemId, $this->normalizeScale($this->normalizeMoney($context->getRequest())), $context);
            if ($patched->isSuccess()) {
                $this->auditItem($auth, $itemId, 'update', $before);
            }

            return $patched;
        }
        if ($method === 'DELETE') {
            $model = $this->findModel($itemId);
            if ($model === null) {
                return Result::fail('Ítem no encontrado.', 404);
            }
            $before = $this->itemSnapshot($itemId);
            $model->delete();
            AuditLogger::log(
                $auth,
                'config_prices',
                'price_list_item',
                (string) $itemId,
                'delete',
                $this->itemDescription('delete', $before),
                $before,
                null,
            );

            return Result::ok(['id' => $itemId], 'Ítem eliminado');
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $auth
     */
    private function removeProductFromList(array $input, array $auth): Result
    {
        $priceListId = (int) ($input['price_list_id'] ?? 0);
        $serviceId = (int) ($input['service_id'] ?? 0);

        if ($priceListId < 1 || $serviceId < 1) {
            return Result::fail('price_list_id y service_id son requeridos.', 400);
        }

        $existing = Capsule::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('service_id', $serviceId)
            ->get([
                'id',
                'price_list_id',
                'service_id',
                'frequency',
                'min_quantity',
                'max_quantity',
                'unit_price',
            ]);

        $deleted = Capsule::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('service_id', $serviceId)
            ->delete();

        if ($deleted === 0) {
            return Result::fail('No hay ítems de ese producto en la lista.', 404);
        }

        foreach ($existing as $row) {
            $before = $this->mapItemAudit($row);
            AuditLogger::log(
                $auth,
                'config_prices',
                'price_list_item',
                (string) $row->id,
                'delete',
                $this->itemDescription('delete', $before),
                $before,
                null,
            );
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

        // Alias UI/API: one_time → single (ENUM canónico en MySQL).
        if (array_key_exists('frequency', $input)) {
            $freq = strtolower(trim((string) $input['frequency']));
            if (in_array($freq, ['one_time', 'onetime', 'unico'], true)) {
                $input['frequency'] = 'single';
            }
        }

        // Productos de pago único / consumo siempre se guardan con frequency=single.
        $serviceId = (int) ($input['service_id'] ?? 0);
        if ($serviceId > 0) {
            $serviceType = Capsule::table('services')->where('id', $serviceId)->value('service_type');
            if (in_array((string) $serviceType, ['single', 'consumption', 'one_time'], true)) {
                $input['frequency'] = 'single';
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
                's.service_type as service_type',
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
                'service_type' => (string) ($item->service_type ?? 'recurring'),
                'frequency' => (string) $item->frequency,
                'min_quantity' => (int) $item->min_quantity,
                'max_quantity' => $item->max_quantity !== null ? (int) $item->max_quantity : null,
                'unit_price' => (string) $item->unit_price,
            ];
        })->all();
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, mixed>|null $before
     */
    private function auditItem(array $auth, int $id, string $action, ?array $before): void
    {
        $after = $this->itemSnapshot($id);
        AuditLogger::log(
            $auth,
            'config_prices',
            'price_list_item',
            (string) $id,
            $action,
            $this->itemDescription($action, $after ?? $before),
            $before,
            $after,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemSnapshot(int $id): ?array
    {
        $row = Capsule::table('price_list_items')->where('id', $id)->first([
            'id',
            'price_list_id',
            'service_id',
            'frequency',
            'min_quantity',
            'max_quantity',
            'unit_price',
        ]);

        return $row === null ? null : $this->mapItemAudit($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItemAudit(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'price_list_id' => (int) $row->price_list_id,
            'service_id' => (int) $row->service_id,
            'frequency' => (string) $row->frequency,
            'min_quantity' => (int) $row->min_quantity,
            'max_quantity' => $row->max_quantity !== null ? (int) $row->max_quantity : null,
            'unit_price' => (string) $row->unit_price,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function itemDescription(string $action, ?array $row): string
    {
        $row ??= [];
        $frequency = (string) ($row['frequency'] ?? '');
        $min = (int) ($row['min_quantity'] ?? 1);
        $max = array_key_exists('max_quantity', $row) && $row['max_quantity'] !== null
            ? (int) $row['max_quantity']
            : null;
        $range = $this->formatRange($min, $max);
        $price = (string) ($row['unit_price'] ?? '');
        $listId = (int) ($row['price_list_id'] ?? 0);
        $verb = match ($action) {
            'create' => 'Se creó',
            'delete' => 'Se eliminó',
            default => 'Se actualizó',
        };

        return trim(sprintf('%s la escala %s %s (%s) de la lista %d', $verb, $frequency, $range, $price, $listId));
    }
}
