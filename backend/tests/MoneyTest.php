<?php

declare(strict_types=1);

namespace Operam\Tests;

use Operam\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testBridgeEquationWithoutFloatDrift(): void
    {
        $inicio = Money::of('1254300.00');
        $fin = $inicio
            ->plus(Money::of('21600.00'))
            ->plus(Money::of('18200.00'))
            ->plus(Money::of('6100.00'))
            ->plus(Money::of('1900.00'))
            ->minus(Money::of('7400.00'))
            ->minus(Money::of('14300.00'))
            ->plus(Money::of('4100.00'));

        self::assertSame('1284500.00', $fin->toDecimalString());
    }
}
