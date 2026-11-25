<?php

declare(strict_types=1);

namespace System\Support;

/**
 * Motor puro de bolsas / consumo (RF-4.1–RF-4.5) — sin I/O.
 * Orden: included → prepaid; tolerancia de sobregiro ≤ 2 unidades.
 */
final class BalanceRules
{
    public const OVERDRAFT_TOLERANCE = 2;

    /**
     * Calcula el plan de descuento entre bolsas.
     *
     * @return array{
     *   ok: bool,
     *   message: string|null,
     *   from_included: int,
     *   from_prepaid: int,
     *   forgiven_units: int,
     *   included_consumed_after: int,
     *   prepaid_consumed_after: int
     * }
     */
    public static function planConsumption(
        int $units,
        int $includedGranted,
        int $includedConsumed,
        int $prepaidGranted,
        int $prepaidConsumed,
        bool $allowOverdraftTolerance,
    ): array {
        if ($units < 1) {
            return self::fail('Las unidades deben ser un entero mayor a 0.');
        }

        $includedAvailable = max(0, $includedGranted - $includedConsumed);
        $prepaidAvailable = max(0, $prepaidGranted - $prepaidConsumed);
        $totalAvailable = $includedAvailable + $prepaidAvailable;

        $fromIncluded = min($units, $includedAvailable);
        $remainder = $units - $fromIncluded;
        $fromPrepaid = min($remainder, $prepaidAvailable);
        $shortfall = $remainder - $fromPrepaid;

        if ($shortfall > 0) {
            if ($allowOverdraftTolerance && $shortfall <= self::OVERDRAFT_TOLERANCE) {
                return [
                    'ok' => true,
                    'message' => null,
                    'from_included' => $fromIncluded,
                    'from_prepaid' => $fromPrepaid,
                    'forgiven_units' => $shortfall,
                    'included_consumed_after' => $includedConsumed + $fromIncluded,
                    'prepaid_consumed_after' => $prepaidConsumed + $fromPrepaid,
                ];
            }

            return self::fail('Saldo insuficiente');
        }

        return [
            'ok' => true,
            'message' => null,
            'from_included' => $fromIncluded,
            'from_prepaid' => $fromPrepaid,
            'forgiven_units' => 0,
            'included_consumed_after' => $includedConsumed + $fromIncluded,
            'prepaid_consumed_after' => $prepaidConsumed + $fromPrepaid,
        ];
    }

    /** Unidades disponibles nunca negativas. */
    public static function availableUnits(int $granted, int $consumed): int
    {
        return max(0, $granted - $consumed);
    }

    /**
     * @return array{
     *   ok: false,
     *   message: string,
     *   from_included: int,
     *   from_prepaid: int,
     *   forgiven_units: int,
     *   included_consumed_after: int,
     *   prepaid_consumed_after: int
     * }
     */
    private static function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'from_included' => 0,
            'from_prepaid' => 0,
            'forgiven_units' => 0,
            'included_consumed_after' => 0,
            'prepaid_consumed_after' => 0,
        ];
    }
}
