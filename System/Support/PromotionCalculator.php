<?php

declare(strict_types=1);

namespace System\Support;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Calculadora pura de promociones de unidades extra % en consumibles (RF-2.8, Pág. 12).
 * No acumulables: si aplican varias, gana la que otorgue mayor cantidad de unidades.
 */
final class PromotionCalculator
{
    /**
     * Fórmula exacta del PDF: floor(quantity × pct / 100) con BCMath.
     * Ej. 1010 × 25% = 252.
     */
    public static function computeExtraUnits(int $quantity, string|float|int $extraUnitsPct): int
    {
        if ($quantity < 1) {
            return 0;
        }
        $pct = trim((string) $extraUnitsPct);
        if ($pct === '' || !is_numeric($pct) || bccomp($pct, '0', 4) <= 0) {
            return 0;
        }

        // bcmul con 4 decimales → bcdiv entre 100 con escala 0 = truncado hacia abajo (floor ≥ 0).
        $product = bcmul((string) $quantity, $pct, 4);
        $floored = bcdiv($product, '100', 0);

        return max(0, (int) $floored);
    }

    /**
     * Evalúa candidatos ya filtrados y devuelve el mejor (mayor extra_units).
     *
     * @param list<array{
     *   id:int,
     *   code?:string,
     *   name?:string,
     *   extra_units_pct:string|float|int,
     *   min_quantity?:int|null
     * }> $candidates
     * @return array{
     *   applies:bool,
     *   promotion_id:int|null,
     *   promotion_code:string|null,
     *   promotion_name:string|null,
     *   extra_units_pct:string|null,
     *   extra_units:int,
     *   purchased_units:int,
     *   total_units:int
     * }
     */
    public static function pickBestExtraUnitsPromo(int $quantity, array $candidates): array
    {
        $empty = [
            'applies' => false,
            'promotion_id' => null,
            'promotion_code' => null,
            'promotion_name' => null,
            'extra_units_pct' => null,
            'extra_units' => 0,
            'purchased_units' => $quantity,
            'total_units' => $quantity,
        ];
        if ($quantity < 1 || $candidates === []) {
            return $empty;
        }

        $best = null;
        $bestExtra = -1;
        foreach ($candidates as $promo) {
            $minQty = isset($promo['min_quantity']) && $promo['min_quantity'] !== null
                ? (int) $promo['min_quantity']
                : 0;
            if ($minQty > 0 && $quantity < $minQty) {
                continue;
            }
            $extra = self::computeExtraUnits($quantity, $promo['extra_units_pct'] ?? 0);
            if ($extra > $bestExtra) {
                $bestExtra = $extra;
                $best = $promo;
            }
        }

        if ($best === null || $bestExtra <= 0) {
            return $empty;
        }

        return [
            'applies' => true,
            'promotion_id' => (int) $best['id'],
            'promotion_code' => isset($best['code']) ? (string) $best['code'] : null,
            'promotion_name' => isset($best['name']) ? (string) $best['name'] : null,
            'extra_units_pct' => number_format((float) $best['extra_units_pct'], 2, '.', ''),
            'extra_units' => $bestExtra,
            'purchased_units' => $quantity,
            'total_units' => $quantity + $bestExtra,
        ];
    }

    /**
     * Consulta MySQL: promociones activas vigentes extra_units cuyo alcance
     * coincida con client_id O segment_id (scopes + legado segment_id en promotions).
     *
     * @return array{
     *   applies:bool,
     *   promotion_id:int|null,
     *   promotion_code:string|null,
     *   promotion_name:string|null,
     *   extra_units_pct:string|null,
     *   extra_units:int,
     *   purchased_units:int,
     *   total_units:int
     * }
     */
    public static function evaluateExtraUnitsPromo(
        int $clientId,
        int $segmentId,
        int $serviceId,
        int $quantity,
        ?string $date = null,
    ): array {
        $onDate = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? $date
            : (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');

        $rows = Capsule::table('promotions as p')
            ->where('p.is_active', 1)
            ->where('p.promo_mode', 'extra_units')
            ->where('p.service_id', $serviceId)
            ->where('p.valid_from', '<=', $onDate)
            ->where(static function ($q) use ($onDate): void {
                $q->whereNull('p.valid_until')->orWhere('p.valid_until', '>=', $onDate);
            })
            ->orderByDesc('p.id')
            ->get([
                'p.id',
                'p.code',
                'p.name',
                'p.segment_id',
                'p.extra_units_pct',
                'p.min_quantity',
            ]);

        $candidates = [];
        foreach ($rows as $row) {
            $promoId = (int) $row->id;
            $scopes = Capsule::table('promotion_scopes')
                ->where('promotion_id', $promoId)
                ->get(['segment_id', 'client_id']);

            if ($scopes->count() > 0) {
                // Alcance explícito: cliente específico O segmento (cliente gana aunque su segmento no esté).
                $inScope = false;
                foreach ($scopes as $scope) {
                    if ($scope->client_id !== null && (int) $scope->client_id === $clientId) {
                        $inScope = true;
                        break;
                    }
                    if ($scope->segment_id !== null && (int) $scope->segment_id === $segmentId) {
                        $inScope = true;
                        break;
                    }
                }
                if (!$inScope) {
                    continue;
                }
            } else {
                // Sin scopes: promotions.segment_id legado (NULL = todos los segmentos).
                if ($row->segment_id !== null && (int) $row->segment_id !== $segmentId) {
                    continue;
                }
            }

            $candidates[] = [
                'id' => $promoId,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'extra_units_pct' => (string) ($row->extra_units_pct ?? '0'),
                'min_quantity' => $row->min_quantity !== null ? (int) $row->min_quantity : null,
            ];
        }

        return self::pickBestExtraUnitsPromo($quantity, $candidates);
    }
}
