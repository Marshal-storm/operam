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
            ['categoria_mrr' => 'nuevo', 'mrr_delta_mxn' => '21600.00'],
            ['categoria_mrr' => 'expansion', 'mrr_delta_mxn' => '18200.00'],
            ['categoria_mrr' => 'ajuste_precio', 'mrr_delta_mxn' => '6100.00'],
            ['categoria_mrr' => 'cambio_frecuencia', 'mrr_delta_mxn' => '1900.00'],
            ['categoria_mrr' => 'contraccion', 'mrr_delta_mxn' => '-7400.00'],
            ['categoria_mrr' => 'churn', 'mrr_delta_mxn' => '-14300.00'],
            ['categoria_mrr' => 'reactivacion', 'mrr_delta_mxn' => '4100.00'],
            // transferencia se ignora (delta contable 0.00)
            ['categoria_mrr' => 'transferencia', 'mrr_delta_mxn' => '0.00'],
        ];

        $result = FinancialCalculator::calculateMrrBridge('1254300.00', $movements);

        self::assertSame('1284500.00', $result['mrrEnd']);
        self::assertSame('+30200.00', $result['netChange']);
        self::assertSame('21600.00', $result['breakdown']['nuevo']);
        self::assertSame('18200.00', $result['breakdown']['expansion']);
        self::assertSame('6100.00', $result['breakdown']['ajuste_precio']);
        self::assertSame('1900.00', $result['breakdown']['cambio_frecuencia']);
        self::assertSame('-7400.00', $result['breakdown']['contraccion']);
        self::assertSame('-14300.00', $result['breakdown']['churn']);
        self::assertSame('4100.00', $result['breakdown']['reactivacion']);
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
     * Delta +18200.00 → inverso -18200.00; mrr_antes/después intercambiados; suma 0.00.
     */
    public function testImmutableReversalRnf3(): void
    {
        $original = [
            'id' => 42,
            'categoria_mrr' => 'expansion',
            'mrr_antes' => '10000.00',
            'mrr_despues' => '28200.00',
            'mrr_delta_mxn' => '+18200.00',
            'cantidad_antes' => '1.00',
            'cantidad_despues' => '2.00',
        ];

        $reversal = FinancialCalculator::buildReversalPayload(
            $original,
            1,
            'Corrección contable de prueba',
        );

        self::assertSame('28200.00', $reversal['mrr_antes']);
        self::assertSame('10000.00', $reversal['mrr_despues']);
        self::assertSame('-18200.00', $reversal['mrr_delta_mxn']);
        self::assertSame(42, $reversal['movimiento_revertido_id']);
        self::assertSame('reversión', $reversal['tipo']);

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
            'motivo' => 'original',
            'mrr_delta_mxn' => '100.00',
        ]);
        $model->syncOriginal();

        try {
            $model->update(['motivo' => 'mutación ilegal']);
            self::fail('Se esperaba RuntimeException al intentar UPDATE.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('inmutable', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inmutable|DELETE/i');
        $model->delete();
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
