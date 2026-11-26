<?php

declare(strict_types=1);

namespace System\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;

/**
 * Resolución de precios desde `price_lists` / `price_list_items` (RF-2, escalones por volumen).
 *
 * Fuente única compartida por la Bandeja de Solicitudes y la Tienda del Portal: el escalón vigente
 * siempre se decide en el servidor con BCMath (`ContractPricingEngine::resolveTierUnitPrice`).
 */
final class PriceListResolver
{
    /** Tipos no recurrentes: se cotizan con la frecuencia `single` (o `monthly` como respaldo). */
    private const NON_RECURRING_TYPES = ['single', 'consumption', 'one_time'];

    /**
     * Escalones del servicio en la lista (ordenados por `min_quantity ASC` — excepción documentada
     * del orden por reciente: los rangos de volumen se leen de menor a mayor).
     *
     * @return list<array{min_quantity:int,max_quantity:int|null,unit_price:string}>
     */
    public static function tiersFor(
        int $priceListId,
        int $serviceId,
        string $contractFrequency,
        string $serviceType = 'recurring',
    ): array {
        $frequencies = in_array($serviceType, self::NON_RECURRING_TYPES, true)
            ? ['single', 'monthly']
            : [$contractFrequency];

        foreach ($frequencies as $frequency) {
            $tiers = self::loadTiers($priceListId, $serviceId, $frequency);
            if ($tiers !== []) {
                return $tiers;
            }
        }

        return self::loadTiers($priceListId, $serviceId, null);
    }

    /**
     * Precio unitario del escalón que corresponde a `$quantity`.
     *
     * @throws InvalidArgumentException Si no hay escalones o la cantidad queda fuera de rango.
     */
    public static function resolveUnitPrice(
        int $priceListId,
        int $serviceId,
        string $contractFrequency,
        int $quantity,
        string $serviceType = 'recurring',
    ): string {
        return ContractPricingEngine::resolveTierUnitPrice(
            self::tiersFor($priceListId, $serviceId, $contractFrequency, $serviceType),
            $quantity,
        );
    }

    /**
     * Lista aplicable a un contrato: la pactada en el contrato o, en su defecto, la lista
     * predeterminada activa del segmento.
     */
    public static function priceListIdForContract(object $contract): ?int
    {
        if (isset($contract->price_list_id) && $contract->price_list_id !== null && (int) $contract->price_list_id > 0) {
            return (int) $contract->price_list_id;
        }

        return self::defaultPriceListIdForSegment((int) ($contract->segment_id ?? 0));
    }

    public static function defaultPriceListIdForSegment(int $segmentId): ?int
    {
        if ($segmentId < 1) {
            return null;
        }
        $id = Capsule::table('price_lists')
            ->where('segment_id', $segmentId)
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->orderByRaw('COALESCE(updated_at, created_at) DESC')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** @return list<array{min_quantity:int,max_quantity:int|null,unit_price:string}> */
    private static function loadTiers(int $priceListId, int $serviceId, ?string $frequency): array
    {
        $query = Capsule::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('service_id', $serviceId);
        if ($frequency !== null) {
            $query->where('frequency', $frequency);
        }

        return $query
            ->orderBy('min_quantity')
            ->get(['min_quantity', 'max_quantity', 'unit_price'])
            ->map(static fn (object $tier): array => [
                'min_quantity' => (int) $tier->min_quantity,
                'max_quantity' => $tier->max_quantity !== null ? (int) $tier->max_quantity : null,
                'unit_price' => FinancialCalculator::normalizeMoney((string) $tier->unit_price),
            ])
            ->values()
            ->all();
    }
}
