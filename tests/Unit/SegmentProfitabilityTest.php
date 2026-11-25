<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\ExceptionAuthorization;
use System\Support\SegmentProfitabilityCalculator;

/**
 * Utilidad por segmento (RF-10) + gate de excepciones (RF-11.4) — BCMath puro.
 */
final class SegmentProfitabilityTest extends TestCase
{
    public function testGrossProfitMarginAndUnitEconomicsWithoutFloatLoss(): void
    {
        $gross = SegmentProfitabilityCalculator::computeGrossMetrics(
            '12500.55',
            '2499.45',
            '8000.10',
        );

        self::assertSame('12500.55', $gross['recurring_revenue_mxn']);
        self::assertSame('2499.45', $gross['non_recurring_revenue_mxn']);
        self::assertSame('15000.00', $gross['total_revenue_mxn']);
        self::assertSame('8000.10', $gross['total_cost_mxn']);
        self::assertSame('6999.90', $gross['gross_profit_mxn']);
        // (6999.90 / 15000.00) * 100 = 46.666... → 46.66 con escala 2
        self::assertSame('46.66', $gross['gross_margin_pct']);

        $unit = SegmentProfitabilityCalculator::computeUnitEconomics(
            $gross['total_revenue_mxn'],
            $gross['total_cost_mxn'],
            $gross['gross_profit_mxn'],
            3,
            25,
        );

        self::assertSame(3, $unit['active_contracts_count']);
        self::assertSame(25, $unit['active_concurrent_users_count']);
        self::assertSame('5000.00', $unit['revenue_per_contract_mxn']);
        self::assertSame('2666.70', $unit['cost_per_contract_mxn']);
        self::assertSame('2333.30', $unit['profit_per_contract_mxn']);
        self::assertSame('600.00', $unit['revenue_per_user_mxn']);
        self::assertSame('320.00', $unit['cost_per_user_mxn']);
        self::assertSame('279.99', $unit['profit_per_user_mxn']);
    }

    public function testMarginIsZeroWhenRevenueIsZero(): void
    {
        $gross = SegmentProfitabilityCalculator::computeGrossMetrics('0', '0', '1500.00');
        self::assertSame('0.00', $gross['total_revenue_mxn']);
        self::assertSame('-1500.00', $gross['gross_profit_mxn']);
        self::assertSame('0.00', $gross['gross_margin_pct']);

        $unit = SegmentProfitabilityCalculator::computeUnitEconomics(
            '0.00',
            '1500.00',
            '-1500.00',
            0,
            0,
        );
        self::assertSame('0.00', $unit['revenue_per_contract_mxn']);
        self::assertSame('0.00', $unit['profit_per_user_mxn']);
    }

    public function testConcurrentUsersFromPackageAndSingles(): void
    {
        self::assertSame(
            15,
            SegmentProfitabilityCalculator::concurrentUsersFromLine(3, true, 5),
        );
        self::assertSame(
            4,
            SegmentProfitabilityCalculator::concurrentUsersFromLine(4, false, 5),
        );
        // Factor inválido (<2) cae al default 5.
        self::assertSame(
            10,
            SegmentProfitabilityCalculator::concurrentUsersFromLine(2, true, 0),
        );
    }

    public function testLastNMonthKeysIncludesAnchor(): void
    {
        $keys = SegmentProfitabilityCalculator::lastNMonthKeys('2026-11', 6);
        self::assertSame(
            ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'],
            $keys,
        );
    }

    public function testBlocksExceptionWhenUserLacksCanAuthorizeExceptions(): void
    {
        $denied = ExceptionAuthorization::evaluatePermission(
            userCanAuthorize: false,
            withException: true,
            reasonOrNotes: 'Autorizado por dirección comercial',
        );

        self::assertFalse($denied['allowed']);
        self::assertSame(ExceptionAuthorization::DENIED_MESSAGE, $denied['message']);

        $allowed = ExceptionAuthorization::evaluatePermission(
            userCanAuthorize: true,
            withException: true,
            reasonOrNotes: 'Autorizado por dirección comercial',
        );
        self::assertTrue($allowed['allowed']);
        self::assertNull($allowed['message']);

        // Sin excepción no se exige el permiso independiente.
        $passthrough = ExceptionAuthorization::evaluatePermission(
            userCanAuthorize: false,
            withException: false,
            reasonOrNotes: '',
        );
        self::assertTrue($passthrough['allowed']);
    }
}
