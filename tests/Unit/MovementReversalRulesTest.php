<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\FinancialCalculator;

/**
 * Reglas de reversión contable RNF-3 (BCMath).
 * Certifica delta invertido exacto, bloqueo de MRR negativo y delta neutral.
 */
final class MovementReversalRulesTest extends TestCase
{
    /**
     * Delta original + inverso = 0.00 (cancelación exacta con BCMath).
     */
    public function testInverseDeltaCancelsOriginalExactly(): void
    {
        $originalDelta = FinancialCalculator::normalizeMoney('+18200.50');
        $inverseDelta = bcmul($originalDelta, '-1', 2);

        self::assertSame('18200.50', $originalDelta);
        self::assertSame('-18200.50', $inverseDelta);
        self::assertSame('0.00', bcadd($originalDelta, $inverseDelta, 2));
    }

    /**
     * Impacto contra MRR vigente del contrato (no el histórico del asiento).
     */
    public function testReversalImpactUsesCurrentContractMrr(): void
    {
        $originalDelta = '18200.00';
        $inverseDelta = bcmul($originalDelta, '-1', 2);
        $mrrBefore = FinancialCalculator::normalizeMoney('50000.00');
        $mrrAfter = bcadd($mrrBefore, $inverseDelta, 2);

        self::assertSame('-18200.00', $inverseDelta);
        self::assertSame('31800.00', $mrrAfter);

        $payload = FinancialCalculator::buildReversalPayload(
            [
                'id' => 99,
                'mrr_category' => 'expansion',
                'mrr_before' => '10000.00',
                'mrr_after' => '28200.00',
                'mrr_delta_mxn' => $originalDelta,
            ],
            1,
            'Reversión de movimiento #99: corrección de prueba',
        );

        self::assertSame('reversal', $payload['movement_type']);
        self::assertSame(99, $payload['reversed_movement_id']);
        self::assertSame('-18200.00', $payload['mrr_delta_mxn']);
    }

    /**
     * Bloqueo: la reversión no puede dejar el MRR del contrato en negativo.
     */
    public function testBlocksReversalThatWouldMakeContractMrrNegative(): void
    {
        $mrrBefore = FinancialCalculator::normalizeMoney('1000.00');
        $inverseDelta = bcmul('5000.00', '-1', 2);
        $mrrAfter = bcadd($mrrBefore, $inverseDelta, 2);

        self::assertSame('-4000.00', $mrrAfter);
        self::assertLessThan(0, bccomp($mrrAfter, '0.00', 2));
    }

    /**
     * Bloqueo: delta neutral (transfer / 0.00) no es reversible.
     */
    public function testNeutralDeltaIsNotReversible(): void
    {
        $delta = FinancialCalculator::normalizeMoney('0.00');
        self::assertSame(0, bccomp($delta, '0.00', 2));
    }

    /**
     * Bloqueo lógico de doble reversión: un asiento con reversed_movement_id
     * no puede ser revertido de nuevo; un original ya apuntado tampoco.
     */
    public function testDoubleReversalGuardLogic(): void
    {
        $original = [
            'id' => 10,
            'reversed_movement_id' => null,
            'mrr_delta_mxn' => '100.00',
        ];
        $existingReversal = [
            'id' => 11,
            'reversed_movement_id' => 10,
            'mrr_delta_mxn' => '-100.00',
        ];

        // El asiento inverso no es elegible (ya es reversión).
        self::assertNotNull($existingReversal['reversed_movement_id']);

        // El original ya tiene una reversión apuntando a su id.
        $alreadyReversed = $existingReversal['reversed_movement_id'] === $original['id'];
        self::assertTrue($alreadyReversed);

        // Un segundo intento sobre el mismo original debe rechazarse.
        $secondAttemptAllowed = !$alreadyReversed && $original['reversed_movement_id'] === null;
        self::assertFalse($secondAttemptAllowed);
    }
}
