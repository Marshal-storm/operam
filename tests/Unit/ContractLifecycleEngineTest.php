<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\ContractLifecycleEngine;
use System\Support\ContractPricingEngine;

/**
 * Certifica deltas BCMath exactos del Motor de Ciclo de Vida (Módulo 3 Paso 3).
 */
final class ContractLifecycleEngineTest extends TestCase
{
    public function testExpansionAddsPositiveDelta(): void
    {
        $impact = ContractLifecycleEngine::expansionImpact('5000.00', '1250.50');

        self::assertSame('5000.00', $impact['mrr_before']);
        self::assertSame('6250.50', $impact['mrr_after']);
        self::assertSame('1250.50', $impact['mrr_delta_mxn']);
    }

    public function testContractionSubtractsAndKeepsMrrPositive(): void
    {
        $impact = ContractLifecycleEngine::contractionImpact('5000.00', '800.00');

        self::assertSame('5000.00', $impact['mrr_before']);
        self::assertSame('4200.00', $impact['mrr_after']);
        self::assertSame('-800.00', $impact['mrr_delta_mxn']);
    }

    public function testContractionToZeroThrowsAndSuggestsChurn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cancelación (Churn)');

        ContractLifecycleEngine::contractionImpact('800.00', '800.00');
    }

    public function testPriceAdjustmentCanBePositiveOrNegative(): void
    {
        $up = ContractLifecycleEngine::priceAdjustmentImpact('3000.00', '1000.00', '1500.00');
        self::assertSame('3500.00', $up['mrr_after']);
        self::assertSame('500.00', $up['mrr_delta_mxn']);

        $down = ContractLifecycleEngine::priceAdjustmentImpact('3000.00', '1000.00', '700.00');
        self::assertSame('2700.00', $down['mrr_after']);
        self::assertSame('-300.00', $down['mrr_delta_mxn']);
    }

    public function testFrequencyChangeMonthlyToAnnualRecalculatesWithBcdiv(): void
    {
        // Línea mensual: subtotal 1200 → MRR 1200.
        // Al pasar a anual sin convertir unit_price, lineMrrMxn divide entre 12.
        $lines = [
            ['service_type' => 'recurring', 'subtotal' => '1200.00', 'active' => true],
            ['service_type' => 'single', 'subtotal' => '500.00', 'active' => true],
        ];

        $impact = ContractLifecycleEngine::frequencyChangeImpact(
            '1200.00',
            $lines,
            'annual',
            '1.0000',
        );

        self::assertSame('100.00', $impact['mrr_after']); // 1200/12
        self::assertSame('-1100.00', $impact['mrr_delta_mxn']);
        self::assertSame('100.00', $impact['lines_mrr'][0]);
        self::assertSame('0.00', $impact['lines_mrr'][1]);
    }

    public function testFrequencyUnitPriceConversionPreservesEconomicMrr(): void
    {
        $annualUnit = ContractLifecycleEngine::convertUnitPriceForFrequency('100.00', 'monthly', 'annual');
        self::assertSame('1200.00', $annualUnit);

        $monthlyUnit = ContractLifecycleEngine::convertUnitPriceForFrequency('1200.00', 'annual', 'monthly');
        self::assertSame('100.00', $monthlyUnit);

        // Equivalencia: 5 × 100 mensual = 500 MRR; 5 × 1200 anual / 12 = 500 MRR.
        $monthlySub = ContractPricingEngine::lineSubtotal(5, '100.00');
        $monthlyMrr = ContractPricingEngine::lineMrrMxn('recurring', 'monthly', $monthlySub, '1.0000');
        $annualSub = ContractPricingEngine::lineSubtotal(5, $annualUnit);
        $annualMrr = ContractPricingEngine::lineMrrMxn('recurring', 'annual', $annualSub, '1.0000');

        self::assertSame('500.00', $monthlyMrr);
        self::assertSame('500.00', $annualMrr);
    }

    public function testIssuerTransferIsNeutralZeroDelta(): void
    {
        $impact = ContractLifecycleEngine::transferImpact('9876.54');

        self::assertSame('9876.54', $impact['mrr_before']);
        self::assertSame('9876.54', $impact['mrr_after']);
        self::assertSame('0.00', $impact['mrr_delta_mxn']);
    }

    public function testChurnZerosMrrWithNegativeDelta(): void
    {
        $impact = ContractLifecycleEngine::churnImpact('14300.00');

        self::assertSame('14300.00', $impact['mrr_before']);
        self::assertSame('0.00', $impact['mrr_after']);
        self::assertSame('-14300.00', $impact['mrr_delta_mxn']);
    }

    public function testReactivationRestoresFromZero(): void
    {
        $impact = ContractLifecycleEngine::reactivationImpact('4100.00');

        self::assertSame('0.00', $impact['mrr_before']);
        self::assertSame('4100.00', $impact['mrr_after']);
        self::assertSame('4100.00', $impact['mrr_delta_mxn']);
    }

    public function testSumActiveRecurringMrrIgnoresInactiveAndOneTime(): void
    {
        $total = ContractLifecycleEngine::sumActiveRecurringMrr([
            ['service_type' => 'recurring', 'mrr_mxn' => '1000.00', 'active' => true],
            ['service_type' => 'recurring', 'mrr_mxn' => '250.50', 'active' => true],
            ['service_type' => 'recurring', 'mrr_mxn' => '999.00', 'active' => false],
            ['service_type' => 'single', 'mrr_mxn' => '0.00', 'active' => true],
        ]);

        self::assertSame('1250.50', $total);
    }
}
