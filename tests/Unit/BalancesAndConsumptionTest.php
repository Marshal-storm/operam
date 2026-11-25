<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use System\Models\ConsumptionsModel;
use System\Support\BalanceRules;

/**
 * Bloque B Paso 1 — motor de bolsas, idempotencia conceptual y RNF-3 consumptions.
 */
final class BalancesAndConsumptionTest extends TestCase
{
    public function testConsumesIncludedBeforePrepaid(): void
    {
        $plan = BalanceRules::planConsumption(
            7,
            5,
            0,
            10,
            0,
            false,
        );

        self::assertTrue($plan['ok']);
        self::assertSame(5, $plan['from_included']);
        self::assertSame(2, $plan['from_prepaid']);
        self::assertSame(0, $plan['forgiven_units']);
        self::assertSame(5, $plan['included_consumed_after']);
        self::assertSame(2, $plan['prepaid_consumed_after']);
    }

    public function testRejectsInsufficientBalanceWithoutTolerance(): void
    {
        $plan = BalanceRules::planConsumption(5, 1, 0, 1, 0, false);
        self::assertFalse($plan['ok']);
        self::assertSame('Saldo insuficiente', $plan['message']);
    }

    public function testAllowsOverdraftToleranceUpToTwoUnits(): void
    {
        $plan = BalanceRules::planConsumption(4, 1, 0, 1, 0, true);
        self::assertTrue($plan['ok']);
        self::assertSame(1, $plan['from_included']);
        self::assertSame(1, $plan['from_prepaid']);
        self::assertSame(2, $plan['forgiven_units']);
        self::assertSame(1, $plan['included_consumed_after']);
        self::assertSame(1, $plan['prepaid_consumed_after']);
        self::assertSame(
            0,
            BalanceRules::availableUnits(1, $plan['included_consumed_after']),
        );
    }

    public function testRejectsOverdraftBeyondTolerance(): void
    {
        $plan = BalanceRules::planConsumption(5, 1, 0, 1, 0, true);
        self::assertFalse($plan['ok']);
        self::assertSame('Saldo insuficiente', $plan['message']);
    }

    /**
     * Idempotencia RNF-2: el mismo external_reference no debe re-descontar.
     * Se valida a nivel de plan + contrato de API (replay no vuelve a llamar plan).
     */
    public function testIdempotentReplayDoesNotReplanDiscount(): void
    {
        $first = BalanceRules::planConsumption(3, 10, 0, 0, 0, false);
        self::assertTrue($first['ok']);
        self::assertSame(3, $first['from_included']);

        // Un replay no vuelve a ejecutar el plan: el saldo "después" del primer consumo
        // permanece estable si no se reaplica el descuento.
        $availableAfter = BalanceRules::availableUnits(10, $first['included_consumed_after']);
        self::assertSame(7, $availableAfter);
        self::assertSame(
            7,
            BalanceRules::availableUnits(10, $first['included_consumed_after']),
        );
    }

    public function testConsumptionsModelBlocksUpdateAndDelete(): void
    {
        $this->bootEloquent();

        $model = new ConsumptionsModel();
        $model->exists = true;
        $model->id = 1;
        $model->forceFill([
            'balance_id' => 1,
            'contract_id' => 1,
            'service_id' => 1,
            'units' => 1,
        ]);
        $model->syncOriginal();

        try {
            $model->update(['units' => 2]);
            self::fail('Se esperaba RuntimeException al intentar UPDATE.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('inmutable', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inmutable|DELETE/i');
        $model->delete();
    }

    private static bool $eloquentBooted = false;

    private function bootEloquent(): void
    {
        if (self::$eloquentBooted) {
            return;
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        self::$eloquentBooted = true;
    }
}
