<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use System\Support\FinancialCalculator;

/**
 * RNF-15 — normalizeMoney rechaza texto no numérico con InvalidArgumentException (HTTP 422).
 */
final class NormalizeMoneyValidationTest extends TestCase
{
    public function testRejectsAlphabeticGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El monto debe ser un número válido');
        FinancialCalculator::normalizeMoney('ddd');
    }

    public function testRejectsMixedAlphanumeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FinancialCalculator::normalizeMoney('12a');
    }

    public function testRejectsNanLiteral(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FinancialCalculator::normalizeMoney('NaN');
    }

    public function testAcceptsValidDecimal(): void
    {
        self::assertSame('1500.50', FinancialCalculator::normalizeMoney('1500.50'));
        self::assertSame('1500.00', FinancialCalculator::normalizeMoney('1,500'));
        self::assertSame('0.00', FinancialCalculator::normalizeMoney('0'));
    }

    /** Deltas con signo `+`/`-` (movements / reversiones RNF-3). */
    public function testAcceptsSignedDeltas(): void
    {
        self::assertSame('18200.00', FinancialCalculator::normalizeMoney('+18200.00'));
        self::assertSame('-7400.00', FinancialCalculator::normalizeMoney('-7400.00'));
    }
}
