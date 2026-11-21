<?php

declare(strict_types=1);

namespace System\Support;

/**
 * Motor puro de ciclo de vida de contratos (Módulo 3 Paso 3).
 * Solo BCMath escala 2 — cero floats, sin I/O. Ideal para pruebas unitarias.
 */
final class ContractLifecycleEngine
{
    private const SCALE = 2;

    /**
     * Construye el snapshot de impacto MRR (antes → delta → después).
     *
     * @return array{mrr_before:string,mrr_after:string,mrr_delta_mxn:string}
     */
    public static function impact(string $mrrBefore, string $mrrAfter): array
    {
        $before = FinancialCalculator::normalizeMoney($mrrBefore);
        $after = FinancialCalculator::normalizeMoney($mrrAfter);

        return [
            'mrr_before' => $before,
            'mrr_after' => $after,
            'mrr_delta_mxn' => bcsub($after, $before, self::SCALE),
        ];
    }

    /**
     * Expansión: suma MRR de líneas nuevas (o incremento de línea) al MRR del contrato.
     */
    public static function expansionImpact(string $contractMrrBefore, string $addedMrrMxn): array
    {
        $before = FinancialCalculator::normalizeMoney($contractMrrBefore);
        $added = FinancialCalculator::normalizeMoney($addedMrrMxn);
        $after = bcadd($before, $added, self::SCALE);

        return self::impact($before, $after);
    }

    /**
     * Contracción: reduce MRR; valida que el resultado sea estrictamente > 0.00.
     *
     * @return array{mrr_before:string,mrr_after:string,mrr_delta_mxn:string}
     *
     * @throws \InvalidArgumentException si el MRR resultante sería 0.00 (usar churn)
     */
    public static function contractionImpact(string $contractMrrBefore, string $removedMrrMxn): array
    {
        $before = FinancialCalculator::normalizeMoney($contractMrrBefore);
        $removed = FinancialCalculator::normalizeMoney($removedMrrMxn);
        $after = bcsub($before, $removed, self::SCALE);

        if (bccomp($after, '0.00', self::SCALE) <= 0) {
            throw new \InvalidArgumentException(
                'La contracción dejaría el MRR en $0.00. Usa la operación de Cancelación (Churn).',
            );
        }

        return self::impact($before, $after);
    }

    /**
     * Ajuste de precio: reemplaza el MRR de una línea por el nuevo.
     */
    public static function priceAdjustmentImpact(
        string $contractMrrBefore,
        string $lineMrrBefore,
        string $lineMrrAfter,
    ): array {
        $before = FinancialCalculator::normalizeMoney($contractMrrBefore);
        $oldLine = FinancialCalculator::normalizeMoney($lineMrrBefore);
        $newLine = FinancialCalculator::normalizeMoney($lineMrrAfter);
        $after = bcadd(bcsub($before, $oldLine, self::SCALE), $newLine, self::SCALE);

        return self::impact($before, $after);
    }

    /**
     * Cambio de frecuencia: recalcula el MRR total de líneas recurrentes activas.
     *
     * @param list<array{service_type:string,subtotal:string,active?:bool}> $lines
     * @return array{mrr_before:string,mrr_after:string,mrr_delta_mxn:string,lines_mrr:list<string>}
     */
    public static function frequencyChangeImpact(
        string $contractMrrBefore,
        array $lines,
        string $newFrequency,
        string $exchangeRate,
    ): array {
        $linesMrr = [];
        $total = '0.00';
        foreach ($lines as $line) {
            $active = $line['active'] ?? true;
            if (!$active) {
                $linesMrr[] = '0.00';
                continue;
            }
            $lineMrr = ContractPricingEngine::lineMrrMxn(
                (string) $line['service_type'],
                $newFrequency,
                (string) $line['subtotal'],
                $exchangeRate,
            );
            // Solo líneas recurrentes aportan; one-time ya devuelve 0.00.
            if ((string) $line['service_type'] !== 'recurring') {
                $lineMrr = '0.00';
            }
            $linesMrr[] = $lineMrr;
            $total = bcadd($total, $lineMrr, self::SCALE);
        }

        $impact = self::impact($contractMrrBefore, $total);
        $impact['lines_mrr'] = $linesMrr;

        return $impact;
    }

    /**
     * Transferencia de emisor: 100% neutral para el MRR (regla estricta del PDF).
     *
     * @return array{mrr_before:string,mrr_after:string,mrr_delta_mxn:string}
     */
    public static function transferImpact(string $contractMrr): array
    {
        $mrr = FinancialCalculator::normalizeMoney($contractMrr);

        return [
            'mrr_before' => $mrr,
            'mrr_after' => $mrr,
            'mrr_delta_mxn' => '0.00',
        ];
    }

    /**
     * Churn / cancelación: MRR → 0.00 con delta negativo igual al MRR actual.
     */
    public static function churnImpact(string $contractMrrBefore): array
    {
        $before = FinancialCalculator::normalizeMoney($contractMrrBefore);

        return [
            'mrr_before' => $before,
            'mrr_after' => '0.00',
            'mrr_delta_mxn' => bcsub('0.00', $before, self::SCALE),
        ];
    }

    /**
     * Reactivación: restaura el MRR desde las líneas recurrentes activas.
     */
    public static function reactivationImpact(string $restoredMrrMxn): array
    {
        $after = FinancialCalculator::normalizeMoney($restoredMrrMxn);

        return [
            'mrr_before' => '0.00',
            'mrr_after' => $after,
            'mrr_delta_mxn' => $after,
        ];
    }

    /**
     * Convierte unit_price congelado al cambiar frecuencia (mensual ↔ anual).
     * Mensual→Anual: ×12; Anual→Mensual: ÷12. Preserva equivalencia económica del MRR.
     */
    public static function convertUnitPriceForFrequency(
        string $unitPrice,
        string $fromFrequency,
        string $toFrequency,
    ): string {
        $price = FinancialCalculator::normalizeMoney($unitPrice);
        if ($fromFrequency === $toFrequency) {
            return $price;
        }
        if ($fromFrequency === 'monthly' && $toFrequency === 'annual') {
            return bcmul($price, '12', self::SCALE);
        }
        if ($fromFrequency === 'annual' && $toFrequency === 'monthly') {
            return bcdiv($price, '12', self::SCALE);
        }

        return $price;
    }

    /**
     * Suma MRR de líneas recurrentes activas (para reactivación / recálculo).
     *
     * @param list<array{service_type:string,mrr_mxn:string,active?:bool}> $lines
     */
    public static function sumActiveRecurringMrr(array $lines): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $active = $line['active'] ?? true;
            if (!$active) {
                continue;
            }
            if ((string) ($line['service_type'] ?? '') !== 'recurring') {
                continue;
            }
            $total = bcadd($total, FinancialCalculator::normalizeMoney((string) $line['mrr_mxn']), self::SCALE);
        }

        return $total;
    }

    /**
     * Cupón quemado: recalcula subtotal/MRR de una línea a precio neto (`discount_amount = 0.00`).
     * Fórmula: subtotal = qty × unit_price (piso 0); MRR vía lineMrrMxn (annual/12, FX USD).
     *
     * @return array{discount_amount:string,subtotal:string,mrr_mxn:string}
     */
    public static function recalculateLineNetOfCoupon(
        int $quantity,
        string $unitPrice,
        string $serviceType,
        string $billingFrequency,
        string $exchangeRate,
    ): array {
        $qty = max(0, $quantity);
        $unit = FinancialCalculator::normalizeMoney($unitPrice);
        $subtotal = bcmul((string) $qty, $unit, self::SCALE);
        if (bccomp($subtotal, '0.00', self::SCALE) < 0) {
            $subtotal = '0.00';
        }

        $mrr = ContractPricingEngine::lineMrrMxn(
            $serviceType,
            $billingFrequency,
            $subtotal,
            $exchangeRate,
        );
        if (bccomp($mrr, '0.00', self::SCALE) < 0) {
            $mrr = '0.00';
        }

        return [
            'discount_amount' => '0.00',
            'subtotal' => $subtotal,
            'mrr_mxn' => $mrr,
        ];
    }

    /**
     * Simula ajuste de precio con cupón quemado y calcula el impacto en el MRR del contrato.
     *
     * @return array{
     *   discount_amount:string,
     *   subtotal:string,
     *   line_mrr_after:string,
     *   mrr_before:string,
     *   mrr_after:string,
     *   mrr_delta_mxn:string
     * }
     */
    public static function priceAdjustmentBurningCoupon(
        string $contractMrrBefore,
        string $lineMrrBefore,
        int $quantity,
        string $newUnitPrice,
        string $billingFrequency,
        string $exchangeRate = '1.0000',
    ): array {
        $recalc = self::recalculateLineNetOfCoupon(
            $quantity,
            $newUnitPrice,
            'recurring',
            $billingFrequency,
            $exchangeRate,
        );
        $impact = self::priceAdjustmentImpact(
            $contractMrrBefore,
            $lineMrrBefore,
            $recalc['mrr_mxn'],
        );

        return [
            'discount_amount' => $recalc['discount_amount'],
            'subtotal' => $recalc['subtotal'],
            'line_mrr_after' => $recalc['mrr_mxn'],
            'mrr_before' => $impact['mrr_before'],
            'mrr_after' => $impact['mrr_after'],
            'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
        ];
    }

    /**
     * Simula contracción de cantidad con cupón quemado.
     *
     * @return array{
     *   discount_amount:string,
     *   subtotal:string,
     *   line_mrr_after:string,
     *   mrr_before:string,
     *   mrr_after:string,
     *   mrr_delta_mxn:string
     * }
     */
    public static function contractionBurningCoupon(
        string $contractMrrBefore,
        string $lineMrrBefore,
        int $newQuantity,
        string $unitPrice,
        string $billingFrequency,
        string $exchangeRate = '1.0000',
    ): array {
        $recalc = self::recalculateLineNetOfCoupon(
            $newQuantity,
            $unitPrice,
            'recurring',
            $billingFrequency,
            $exchangeRate,
        );
        $removed = bcsub(
            FinancialCalculator::normalizeMoney($lineMrrBefore),
            $recalc['mrr_mxn'],
            self::SCALE,
        );
        $impact = self::contractionImpact($contractMrrBefore, $removed);

        return [
            'discount_amount' => $recalc['discount_amount'],
            'subtotal' => $recalc['subtotal'],
            'line_mrr_after' => $recalc['mrr_mxn'],
            'mrr_before' => $impact['mrr_before'],
            'mrr_after' => $impact['mrr_after'],
            'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
        ];
    }
}
