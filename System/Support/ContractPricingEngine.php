<?php

declare(strict_types=1);

namespace System\Support;

use InvalidArgumentException;

/**
 * Motor de precios de contrato (RF-2 + promociones + MRR MXN) — BCMath puro, sin I/O.
 */
final class ContractPricingEngine
{
    private const SCALE = 2;

    /**
     * Máximo cubierto por los escalones (null = hay ∞ abierto).
     *
     * @param list<array{min_quantity:int,max_quantity:int|null,unit_price?:string}> $tiers
     */
    public static function maxCoveredQuantity(array $tiers): ?int
    {
        $max = 0;
        foreach ($tiers as $tier) {
            if ($tier['max_quantity'] === null) {
                return null;
            }
            $hi = (int) $tier['max_quantity'];
            if ($hi > $max) {
                $max = $hi;
            }
        }

        return $max;
    }

    /**
     * Resuelve precio unitario vigente según escalones de lista (min/max quantity).
     * Si la cantidad no cae en ningún rango → InvalidArgumentException (no $0.00 silencioso).
     *
     * @param list<array{min_quantity:int,max_quantity:int|null,unit_price:string}> $tiers
     */
    public static function resolveTierUnitPrice(array $tiers, int $quantity): string
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('La cantidad debe ser ≥ 1.');
        }
        if ($tiers === []) {
            throw new InvalidArgumentException('No hay escalones de precio para el servicio.');
        }

        foreach ($tiers as $tier) {
            $min = (int) $tier['min_quantity'];
            $max = $tier['max_quantity'];
            if ($quantity < $min) {
                continue;
            }
            if ($max === null || $quantity <= (int) $max) {
                return FinancialCalculator::normalizeMoney((string) $tier['unit_price']);
            }
        }

        $coveredMax = self::maxCoveredQuantity($tiers);
        $maxLabel = $coveredMax === null ? '∞' : (string) $coveredMax;
        throw new InvalidArgumentException(
            "Cantidad fuera de los rangos configurados en esta lista (máx. {$maxLabel})",
        );
    }

    /**
     * Totales de factura inicial vs impacto MRR (BCMath).
     *
     * @param list<array{service_type:string,subtotal:string,mrr_mxn:string}> $lines
     * @return array{
     *   recurring_subtotal:string,
     *   one_time_subtotal:string,
     *   initial_invoice_total:string,
     *   mrr_mxn:string
     * }
     */
    public static function summarizeInvoiceAndMrr(array $lines, string $promoDiscount = '0.00'): array
    {
        $recurring = '0.00';
        $oneTime = '0.00';
        $mrr = '0.00';
        foreach ($lines as $line) {
            $sub = FinancialCalculator::normalizeMoney((string) ($line['subtotal'] ?? '0'));
            $lineMrr = FinancialCalculator::normalizeMoney((string) ($line['mrr_mxn'] ?? '0'));
            $type = (string) ($line['service_type'] ?? 'recurring');
            if ($type === 'recurring') {
                $recurring = bcadd($recurring, $sub, self::SCALE);
            } else {
                $oneTime = bcadd($oneTime, $sub, self::SCALE);
            }
            $mrr = bcadd($mrr, $lineMrr, self::SCALE);
        }

        $discount = FinancialCalculator::normalizeMoney($promoDiscount);
        $invoice = bcsub(bcadd($recurring, $oneTime, self::SCALE), $discount, self::SCALE);
        if (bccomp($invoice, '0.00', self::SCALE) < 0) {
            $invoice = '0.00';
        }

        return [
            'recurring_subtotal' => $recurring,
            'one_time_subtotal' => $oneTime,
            'initial_invoice_total' => $invoice,
            'mrr_mxn' => $mrr,
        ];
    }

    /**
     * Expande una línea con conversión RF-2 (paquetes de factor N + sueltos).
     *
     * @return list<array{
     *   service_id:int,
     *   description:string,
     *   service_type:string,
     *   billing_frequency:string,
     *   quantity:int,
     *   unit_price:string,
     *   is_package_converted:bool,
     *   rf2_savings:string
     * }>
     */
    public static function expandLineWithRf2(
        int $serviceId,
        string $serviceName,
        string $serviceType,
        string $billingFrequency,
        int $quantity,
        string $unitPrice,
        bool $allowPackageConversion,
        ?int $packageServiceId,
        string $packageServiceName,
        int $packageFactor,
        string $packageUnitPrice,
    ): array {
        $qty = max(0, $quantity);
        $unit = FinancialCalculator::normalizeMoney($unitPrice);

        if (
            !$allowPackageConversion
            || $packageServiceId === null
            || $packageServiceId < 1
            || $packageFactor < 2
            || $qty < $packageFactor
        ) {
            return [[
                'service_id' => $serviceId,
                'description' => $serviceName,
                'service_type' => $serviceType,
                'billing_frequency' => $billingFrequency,
                'quantity' => $qty,
                'unit_price' => $unit,
                'is_package_converted' => false,
                'rf2_savings' => '0.00',
            ]];
        }

        // RF-2 genérico: siempre usa el package_factor real del producto (no hardcode 5).
        $opt = FinancialCalculator::optimizeUserPackages(
            $qty,
            $unit,
            $packageUnitPrice,
            $packageFactor,
        );

        $lines = [];
        if ($opt['packagesCount'] > 0) {
            $lines[] = [
                'service_id' => $packageServiceId,
                'description' => $packageServiceName,
                'service_type' => $serviceType,
                'billing_frequency' => $billingFrequency,
                'quantity' => $opt['packagesCount'],
                'unit_price' => FinancialCalculator::normalizeMoney($packageUnitPrice),
                'is_package_converted' => true,
                'rf2_savings' => $opt['savings'],
            ];
        }
        if ($opt['singleUsersCount'] > 0) {
            $lines[] = [
                'service_id' => $serviceId,
                'description' => $serviceName,
                'service_type' => $serviceType,
                'billing_frequency' => $billingFrequency,
                'quantity' => $opt['singleUsersCount'],
                'unit_price' => $unit,
                'is_package_converted' => true,
                'rf2_savings' => '0.00',
            ];
        }

        return $lines !== [] ? $lines : [[
            'service_id' => $serviceId,
            'description' => $serviceName,
            'service_type' => $serviceType,
            'billing_frequency' => $billingFrequency,
            'quantity' => $qty,
            'unit_price' => $unit,
            'is_package_converted' => false,
            'rf2_savings' => '0.00',
        ]];
    }

    /**
     * Calcula descuento de promoción sobre una base recurrente.
     *
     * @return array{discount_amount:string, label:string}
     */
    public static function applyPromotionDiscount(
        string $recurringSubtotal,
        ?string $discountType,
        ?string $discountValue,
    ): array {
        $base = FinancialCalculator::normalizeMoney($recurringSubtotal);
        if ($discountType === null || $discountValue === null || $discountType === '') {
            return ['discount_amount' => '0.00', 'label' => ''];
        }

        $value = FinancialCalculator::normalizeMoney($discountValue);

        return match ($discountType) {
            'percentage' => [
                'discount_amount' => bcdiv(bcmul($base, $value, 4), '100', self::SCALE),
                'label' => $value . '%',
            ],
            'fixed_amount' => [
                'discount_amount' => bccomp($value, $base, self::SCALE) > 0 ? $base : $value,
                'label' => 'Monto fijo',
            ],
            'free_months' => [
                // free_months no reduce el MRR mensual; el descuento de factura inicial
                // se modela como 0 sobre MRR (impacto en cobranza, no en puente).
                'discount_amount' => '0.00',
                'label' => $value . ' mes(es) gratis',
            ],
            default => ['discount_amount' => '0.00', 'label' => ''],
        };
    }

    /**
     * MRR mensual en MXN para una línea congelada.
     */
    public static function lineMrrMxn(
        string $serviceType,
        string $billingFrequency,
        string $subtotal,
        string $exchangeRate,
    ): string {
        if ($serviceType !== 'recurring') {
            return '0.00';
        }

        $amount = FinancialCalculator::normalizeMoney($subtotal);
        if ($billingFrequency === 'annual') {
            $amount = bcdiv($amount, '12', self::SCALE);
        } elseif ($billingFrequency === 'single') {
            return '0.00';
        }

        $fx = FinancialCalculator::normalizeMoney($exchangeRate);
        // exchange_rate ya viene con hasta 4 decimales; normalizamos a 2 para el producto final.
        return bcmul($amount, $fx, self::SCALE);
    }

    /**
     * Subtotal de línea = quantity * unit_price − discount (piso 0).
     */
    public static function lineSubtotal(int $quantity, string $unitPrice, string $discountAmount = '0.00'): string
    {
        $gross = bcmul((string) max(0, $quantity), FinancialCalculator::normalizeMoney($unitPrice), self::SCALE);
        $discount = FinancialCalculator::normalizeMoney($discountAmount);
        $net = bcsub($gross, $discount, self::SCALE);
        if (bccomp($net, '0.00', self::SCALE) < 0) {
            return '0.00';
        }

        return $net;
    }
}
