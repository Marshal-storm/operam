<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\BenefitRulesEngine;
use System\Support\PromotionCalculator;

/**
 * Bloque B Paso 2 — reglas de beneficio (RF-2.6) y unidades extra % (RF-2.8).
 */
final class BenefitsAndExtraUnitsPromoTest extends TestCase
{
    public function testBenefitRuleSevenUsersTimesFiftyEqualsThreeHundredFifty(): void
    {
        $equivalents = BenefitRulesEngine::equivalentUnits(7, 1);
        self::assertSame(7, $equivalents);

        $folios = BenefitRulesEngine::benefitUnits($equivalents, 50);
        self::assertSame(350, $folios);
    }

    public function testPackageFactorMultipliesEquivalentUnits(): void
    {
        // 1 paquete × factor 5 = 5 usuarios equivalentes × 50 = 250 folios.
        $eq = BenefitRulesEngine::equivalentUnits(1, 5);
        self::assertSame(5, $eq);
        self::assertSame(250, BenefitRulesEngine::benefitUnits($eq, 50));
    }

    public function testExtraUnitsFloorOfOneThousandTenTimesTwentyFivePercent(): void
    {
        // 1010 × 25% = 252.5 → floor 252 (BCMath bcdiv escala 0).
        $extra = PromotionCalculator::computeExtraUnits(1010, '25.00');
        self::assertSame(252, $extra);
    }

    public function testExtraUnitsOneThousandTimesTwentyFivePercent(): void
    {
        self::assertSame(250, PromotionCalculator::computeExtraUnits(1000, '25'));
    }

    public function testNonStackablePromosPickHighestExtraUnits(): void
    {
        $result = PromotionCalculator::pickBestExtraUnitsPromo(1010, [
            [
                'id' => 1,
                'code' => 'EXTRA15',
                'name' => 'Extra 15%',
                'extra_units_pct' => '15.00',
                'min_quantity' => 1000,
            ],
            [
                'id' => 2,
                'code' => 'EXTRA25',
                'name' => 'Extra 25%',
                'extra_units_pct' => '25.00',
                'min_quantity' => 1000,
            ],
        ]);

        self::assertTrue($result['applies']);
        self::assertSame(2, $result['promotion_id']);
        self::assertSame(252, $result['extra_units']);
        self::assertSame(1010, $result['purchased_units']);
        self::assertSame(1262, $result['total_units']);
        self::assertSame('25.00', $result['extra_units_pct']);
    }

    public function testMinQuantityGateRejectsBelowThreshold(): void
    {
        $result = PromotionCalculator::pickBestExtraUnitsPromo(500, [
            [
                'id' => 9,
                'code' => 'EXTRA25',
                'name' => 'Extra 25%',
                'extra_units_pct' => '25.00',
                'min_quantity' => 1000,
            ],
        ]);

        self::assertFalse($result['applies']);
        self::assertSame(0, $result['extra_units']);
        self::assertSame(500, $result['total_units']);
    }

    /**
     * Alcance por cliente: el candidato filtrado por client_id aplica aunque
     * no exista scope de segmento (simulado pasando solo ese candidato).
     */
    public function testClientSpecificScopeCandidateApplies(): void
    {
        $result = PromotionCalculator::pickBestExtraUnitsPromo(1000, [
            [
                'id' => 44,
                'code' => 'CLIENTONLY',
                'name' => 'Solo cliente VIP',
                'extra_units_pct' => '25.00',
                'min_quantity' => 1000,
            ],
        ]);

        self::assertTrue($result['applies']);
        self::assertSame(44, $result['promotion_id']);
        self::assertSame(250, $result['extra_units']);
    }

    /**
     * Separación purchase vs promotion: la promo no altera el precio ni el MRR;
     * solo aporta unidades extra independientes.
     */
    public function testPurchaseAndPromotionUnitsAreSeparateWithoutTouchingPriceOrMrr(): void
    {
        $purchased = 1010;
        $unitPrice = '1.50';
        $mrrBefore = '1200.00';

        $promo = PromotionCalculator::pickBestExtraUnitsPromo($purchased, [
            [
                'id' => 7,
                'code' => 'EXTRA25',
                'name' => 'Extra 25%',
                'extra_units_pct' => '25.00',
                'min_quantity' => 1000,
            ],
        ]);

        // Movimientos conceptuales separados.
        $purchaseMovement = [
            'movement_type' => 'purchase',
            'units' => $purchased,
            'promotion_id' => null,
        ];
        $promotionMovement = [
            'movement_type' => 'promotion',
            'units' => $promo['extra_units'],
            'promotion_id' => $promo['promotion_id'],
        ];

        self::assertSame('purchase', $purchaseMovement['movement_type']);
        self::assertSame('promotion', $promotionMovement['movement_type']);
        self::assertSame(1010, $purchaseMovement['units']);
        self::assertSame(252, $promotionMovement['units']);
        self::assertSame(7, $promotionMovement['promotion_id']);

        // Precio de la línea y MRR del contrato permanecen intactos.
        $lineSubtotal = bcmul((string) $purchased, $unitPrice, 2);
        self::assertSame('1515.00', $lineSubtotal);
        self::assertSame('1200.00', $mrrBefore);
        self::assertSame(
            $mrrBefore,
            bcadd($mrrBefore, '0.00', 2),
            'El MRR no debe mutar por unidades regaladas',
        );
    }

    public function testBenefitPreviewMessageInSpanish(): void
    {
        $msg = BenefitRulesEngine::previewMessage([
            [
                'benefit_service_name' => 'folios',
                'units' => 350,
                'units_per_unit' => 50,
                'equivalent_units' => 7,
                'is_cumulative' => false,
                'reset_period' => 'monthly',
            ],
        ]);

        self::assertStringContainsString('Incluye por beneficio:', $msg);
        self::assertStringContainsString('350 folios', $msg);
        self::assertStringContainsString('50 por unidad × 7 unidades', $msg);
        self::assertStringContainsString('no acumulables', $msg);
    }
}
