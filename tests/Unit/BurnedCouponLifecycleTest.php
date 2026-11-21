<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\ContractLifecycleEngine;
use System\Support\ContractPricingEngine;

/**
 * Regla comercial "Cupón Quemado": el descuento promocional solo aplica en el alta.
 * Cualquier ajuste de precio / contracción restablece discount_amount = 0.00
 * y recalcula subtotal/MRR a precio neto con BCMath (cero subtotales negativos).
 */
final class BurnedCouponLifecycleTest extends TestCase
{
    public function testPriceAdjustmentBurnsCouponAndMatchesExactDelta(): void
    {
        // Alta con cupón 10%: 10 × $100 = $1000 → descuento $100 → subtotal $900 → MRR $900.
        $qty = 10;
        $listUnit = '100.00';
        $gross = ContractPricingEngine::lineSubtotal($qty, $listUnit, '0.00');
        self::assertSame('1000.00', $gross);

        $promo = ContractPricingEngine::applyPromotionDiscount($gross, 'percentage', '10.00');
        self::assertSame('100.00', $promo['discount_amount']);

        $discountedSubtotal = ContractPricingEngine::lineSubtotal($qty, $listUnit, $promo['discount_amount']);
        self::assertSame('900.00', $discountedSubtotal);
        self::assertGreaterThanOrEqual(0, (float) $discountedSubtotal);

        $lineMrrWithCoupon = ContractPricingEngine::lineMrrMxn(
            'recurring',
            'monthly',
            $discountedSubtotal,
            '1.0000',
        );
        self::assertSame('900.00', $lineMrrWithCoupon);

        // Contrato solo con esta línea.
        $contractMrrBefore = $lineMrrWithCoupon;

        // Ajuste de precio a $110: cupón quemado → discount=0, subtotal=10×110=1100, MRR=1100.
        $result = ContractLifecycleEngine::priceAdjustmentBurningCoupon(
            $contractMrrBefore,
            $lineMrrWithCoupon,
            $qty,
            '110.00',
            'monthly',
            '1.0000',
        );

        self::assertSame('0.00', $result['discount_amount']);
        self::assertSame('1100.00', $result['subtotal']);
        self::assertSame('1100.00', $result['line_mrr_after']);
        self::assertSame('900.00', $result['mrr_before']);
        self::assertSame('1100.00', $result['mrr_after']);
        self::assertSame('200.00', $result['mrr_delta_mxn']);
        self::assertSame(
            '200.00',
            bcsub($result['mrr_after'], $result['mrr_before'], 2),
        );
        self::assertTrue(bccomp($result['subtotal'], '0.00', 2) >= 0);
    }

    public function testContractionBurnsCouponAndKeepsPositiveContractMrr(): void
    {
        // Alta con cupón 10% sobre 5 × $200 = $1000 → desc $100 → MRR $900.
        $qty = 5;
        $unit = '200.00';
        $gross = ContractPricingEngine::lineSubtotal($qty, $unit, '0.00');
        $promo = ContractPricingEngine::applyPromotionDiscount($gross, 'percentage', '10.00');
        $discounted = ContractPricingEngine::lineSubtotal($qty, $unit, $promo['discount_amount']);
        $lineMrr = ContractPricingEngine::lineMrrMxn('recurring', 'monthly', $discounted, '1.0000');

        self::assertSame('100.00', $promo['discount_amount']);
        self::assertSame('900.00', $lineMrr);

        // Segunda línea sin cupón para que la contracción no lleve el MRR a 0.
        $otherMrr = '500.00';
        $contractMrrBefore = bcadd($lineMrr, $otherMrr, 2); // 1400.00

        // Contrae a 2 unidades: cupón quemado → 2 × 200 = 400 MRR.
        $result = ContractLifecycleEngine::contractionBurningCoupon(
            $contractMrrBefore,
            $lineMrr,
            2,
            $unit,
            'monthly',
            '1.0000',
        );

        self::assertSame('0.00', $result['discount_amount']);
        self::assertSame('400.00', $result['subtotal']);
        self::assertSame('400.00', $result['line_mrr_after']);
        // removed = 900 − 400 = 500 → after = 1400 − 500 = 900
        self::assertSame('1400.00', $result['mrr_before']);
        self::assertSame('900.00', $result['mrr_after']);
        self::assertSame('-500.00', $result['mrr_delta_mxn']);
        self::assertSame(
            '-500.00',
            bcsub($result['mrr_after'], $result['mrr_before'], 2),
        );
        self::assertTrue(bccomp($result['subtotal'], '0.00', 2) >= 0);
        self::assertTrue(bccomp($result['mrr_after'], '0.00', 2) > 0);
    }

    public function testRecalculateLineNetOfCouponNeverNegative(): void
    {
        $recalc = ContractLifecycleEngine::recalculateLineNetOfCoupon(
            3,
            '50.00',
            'recurring',
            'monthly',
            '1.0000',
        );

        self::assertSame('0.00', $recalc['discount_amount']);
        self::assertSame('150.00', $recalc['subtotal']);
        self::assertSame('150.00', $recalc['mrr_mxn']);
        self::assertTrue(bccomp($recalc['subtotal'], '0.00', 2) >= 0);
        self::assertTrue(bccomp($recalc['mrr_mxn'], '0.00', 2) >= 0);
    }
}
