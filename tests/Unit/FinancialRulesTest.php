<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use System\Models\MovementsModel;
use System\Support\FinancialCalculator;

/**
 * Suite oficial de reglas financieras (página 24 del diseño).
 * Certifica RNF-1 (BCMath), RF-2 (paquetes de 5) y RNF-3 (inmutabilidad / reversión).
 */
final class FinancialRulesTest extends TestCase
{
    private static bool $eloquentBooted = false;

    /**
     * Test 1 — Puente de MRR Oficial Septiembre 2026.
     * Inicio 1,254,300.00 + 7 deltas oficiales = Fin 1,284,500.00 (neto +30,200.00).
     */
    public function testOfficialMrrBridgeSeptember2026(): void
    {
        $movements = [
            ['mrr_category' => 'new', 'mrr_delta_mxn' => '21600.00'],
            ['mrr_category' => 'expansion', 'mrr_delta_mxn' => '18200.00'],
            ['mrr_category' => 'price_adjustment', 'mrr_delta_mxn' => '6100.00'],
            ['mrr_category' => 'frequency_change', 'mrr_delta_mxn' => '1900.00'],
            ['mrr_category' => 'contraction', 'mrr_delta_mxn' => '-7400.00'],
            ['mrr_category' => 'churn', 'mrr_delta_mxn' => '-14300.00'],
            ['mrr_category' => 'reactivation', 'mrr_delta_mxn' => '4100.00'],
            // transfer se ignora (delta contable 0.00)
            ['mrr_category' => 'transfer', 'mrr_delta_mxn' => '0.00'],
        ];

        $result = FinancialCalculator::calculateMrrBridge('1254300.00', $movements);

        self::assertSame('1284500.00', $result['mrrEnd']);
        self::assertSame('+30200.00', $result['netChange']);
        self::assertSame('21600.00', $result['breakdown']['new']);
        self::assertSame('18200.00', $result['breakdown']['expansion']);
        self::assertSame('6100.00', $result['breakdown']['price_adjustment']);
        self::assertSame('1900.00', $result['breakdown']['frequency_change']);
        self::assertSame('-7400.00', $result['breakdown']['contraction']);
        self::assertSame('-14300.00', $result['breakdown']['churn']);
        self::assertSame('4100.00', $result['breakdown']['reactivation']);
    }

    /**
     * Test 2 — Conversión automática paquetes de 5 usuarios (RF-2).
     * 12 usuarios × $180 vs 2×$750 + 2×$180 → optimizado $1,860 / ahorro $300.
     */
    public function testOptimizeUserPackagesRf2(): void
    {
        $result = FinancialCalculator::optimizeUserPackages(12, '180.00', '750.00');

        self::assertSame(2, $result['packagesCount']);
        self::assertSame(2, $result['singleUsersCount']);
        self::assertSame('2160.00', $result['regularTotal']);
        self::assertSame('1860.00', $result['optimizedTotal']);
        self::assertSame('300.00', $result['savings']);
    }

    /**
     * Test 3 — Reversión inmutable (RNF-3).
     * Delta +18200.00 → inverso -18200.00; mrr_before/after intercambiados; suma 0.00.
     */
    public function testImmutableReversalRnf3(): void
    {
        $original = [
            'id' => 42,
            'mrr_category' => 'expansion',
            'mrr_before' => '10000.00',
            'mrr_after' => '28200.00',
            'mrr_delta_mxn' => '+18200.00',
            'quantity_before' => '1.00',
            'quantity_after' => '2.00',
        ];

        $reversal = FinancialCalculator::buildReversalPayload(
            $original,
            1,
            'Corrección contable de prueba',
        );

        self::assertSame('28200.00', $reversal['mrr_before']);
        self::assertSame('10000.00', $reversal['mrr_after']);
        self::assertSame('-18200.00', $reversal['mrr_delta_mxn']);
        self::assertSame(42, $reversal['reversed_movement_id']);
        self::assertSame('reversal', $reversal['movement_type']);
        self::assertSame(1, $reversal['user_id']);

        $sum = bcadd(
            FinancialCalculator::normalizeMoney((string) $original['mrr_delta_mxn']),
            (string) $reversal['mrr_delta_mxn'],
            2,
        );
        self::assertSame('0.00', $sum);

        $this->expectException(RuntimeException::class);
        FinancialCalculator::buildReversalPayload(
            $original + ['_inplace_update' => true],
            1,
            'intento ilegal de mutación',
        );
    }

    /**
     * Test 4 — Bloqueo de mutación en MovementsModel (RNF-3).
     * UPDATE / DELETE sobre instancia existente deben lanzar RuntimeException.
     */
    public function testMovementsModelBlocksUpdateAndDelete(): void
    {
        $this->bootEloquentForModelEvents();

        $model = new MovementsModel();
        $model->exists = true;
        $model->id = 1;
        $model->forceFill([
            'reason' => 'original',
            'mrr_delta_mxn' => '100.00',
        ]);
        $model->syncOriginal();

        try {
            $model->update(['reason' => 'mutación ilegal']);
            self::fail('Se esperaba RuntimeException al intentar UPDATE.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('inmutable', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inmutable|DELETE/i');
        $model->delete();
    }

    /**
     * Test 5 — Acumulación exacta monthly_mrr con bcadd (RF-10 / RNF-1).
     * Partiendo de 21600.00 + deltas sucesivos sin pérdida de centavos.
     */
    public function testMonthlyMrrAccumulationWithBcadd(): void
    {
        self::assertSame('2026-09', FinancialCalculator::monthKeyFromEffectiveDate('2026-09-15'));
        self::assertSame('2026-09', FinancialCalculator::monthKeyFromEffectiveDate('2026-09-01'));

        $amount = '0.00';
        $deltas = [
            '21600.00',
            '18200.00',
            '6100.00',
            '1900.00',
            '-7400.00',
            '-14300.00',
            '4100.00',
        ];

        foreach ($deltas as $delta) {
            $amount = FinancialCalculator::accumulateMonthlyMrrAmount($amount, $delta);
        }

        self::assertSame('30200.00', $amount);

        // Segunda aplicación del mismo delta (UPSERT acumulativo) no pierde centavos.
        $afterSecondNew = FinancialCalculator::accumulateMonthlyMrrAmount('21600.00', '21600.00');
        self::assertSame('43200.00', $afterSecondNew);

        // Reversión exacta: monto + delta invertido = monto previo.
        $reversed = FinancialCalculator::accumulateMonthlyMrrAmount('43200.00', '-21600.00');
        self::assertSame('21600.00', $reversed);
    }

    /**
     * Arranca Capsule SQLite en memoria + Dispatcher para que booted()
     * registre los listeners updating/deleting de MovementsModel.
     */
    private function bootEloquentForModelEvents(): void
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
