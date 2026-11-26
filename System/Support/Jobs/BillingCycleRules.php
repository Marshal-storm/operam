<?php

declare(strict_types=1);

namespace System\Support\Jobs;

use DateTimeImmutable;
use InvalidArgumentException;
use System\Support\FinancialCalculator;

/**
 * Motor puro del ciclo de facturación recurrente por aniversario (RF-5.1 / RF-5.2 / RF-5.3).
 * Solo BCMath escala 2 y fechas `Y-m-d` — sin I/O, ideal para pruebas unitarias.
 */
final class BillingCycleRules
{
    private const SCALE = 2;

    /** Meses que cubre cada frecuencia de cobro recurrente. */
    public const FREQUENCY_MONTHS = [
        'monthly' => 1,
        'bimonthly' => 2,
        'quarterly' => 3,
        'semiannual' => 6,
        'annual' => 12,
    ];

    /** Tope de periodos atrasados que el job factura de una sola vez por contrato. */
    public const MAX_CATCH_UP_PERIODS = 24;

    public static function isRecurringFrequency(string $frequency): bool
    {
        return array_key_exists($frequency, self::FREQUENCY_MONTHS);
    }

    public static function monthsForFrequency(string $frequency): int
    {
        if (!self::isRecurringFrequency($frequency)) {
            throw new InvalidArgumentException('Frecuencia de cobro recurrente no soportada: ' . $frequency);
        }

        return self::FREQUENCY_MONTHS[$frequency];
    }

    /**
     * Siguiente fecha de corte respetando el día de aniversario (Pág. 8 del PDF):
     * si el aniversario es 29/30/31 y el mes destino no tiene ese día, usa el último día del mes.
     * El día se toma siempre del aniversario original (31 ene → 28 feb → 31 mar, sin "derivar").
     */
    public static function nextBillingDate(string $currentYmd, string $frequency, int $anniversaryDay): string
    {
        $current = self::parseDate($currentYmd);
        $months = self::monthsForFrequency($frequency);
        $day = max(1, min(31, $anniversaryDay));

        $firstOfTarget = $current->modify('first day of this month')->modify(sprintf('+%d months', $months));
        $lastDay = (int) $firstOfTarget->format('t');

        return $firstOfTarget->setDate(
            (int) $firstOfTarget->format('Y'),
            (int) $firstOfTarget->format('m'),
            min($day, $lastDay),
        )->format('Y-m-d');
    }

    /** Fin del periodo facturado: día previo al siguiente corte. */
    public static function periodEnd(string $periodStartYmd, string $frequency, int $anniversaryDay): string
    {
        $next = self::parseDate(self::nextBillingDate($periodStartYmd, $frequency, $anniversaryDay));

        return $next->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Primera fecha de corte >= `$todayYmd` partiendo de `$currentYmd` (reactivaciones: no se
     * facturan retroactivamente los periodos en que el contrato estuvo suspendido).
     */
    public static function rollForwardTo(string $currentYmd, string $todayYmd, string $frequency, int $anniversaryDay): string
    {
        $date = $currentYmd;
        $guard = 0;
        while ($date < $todayYmd && $guard < 600) {
            $date = self::nextBillingDate($date, $frequency, $anniversaryDay);
            $guard++;
        }

        return $date;
    }

    /**
     * Importe de una línea para el periodo del contrato: subtotal × (meses contrato ÷ meses línea).
     * Las líneas comparten frecuencia con el contrato en el flujo normal (factor 1).
     */
    public static function linePeriodAmount(string $lineSubtotal, string $lineFrequency, string $contractFrequency): string
    {
        $subtotal = FinancialCalculator::normalizeMoney($lineSubtotal);
        $lineMonths = self::monthsForFrequency($lineFrequency);
        $contractMonths = self::monthsForFrequency($contractFrequency);
        if ($lineMonths === $contractMonths) {
            return $subtotal;
        }

        return self::roundHalfUp(bcdiv(bcmul($subtotal, (string) $contractMonths, 6), (string) $lineMonths, 6));
    }

    /**
     * Subtotal del periodo para todas las líneas recurrentes activas (sin IVA; el IVA se aplica
     * al emitir con CollectionRules::buildInvoiceAmounts).
     *
     * @param list<array{subtotal:string,billing_frequency:string}> $lines
     */
    public static function periodSubtotal(array $lines, string $contractFrequency): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $lineFrequency = self::isRecurringFrequency((string) $line['billing_frequency'])
                ? (string) $line['billing_frequency']
                : $contractFrequency;
            $total = bcadd(
                $total,
                self::linePeriodAmount((string) $line['subtotal'], $lineFrequency, $contractFrequency),
                self::SCALE,
            );
        }

        return $total;
    }

    /** Día del mes del aniversario (1–31) a partir de `Y-m-d`. */
    public static function anniversaryDay(string $anniversaryYmd): int
    {
        return (int) self::parseDate($anniversaryYmd)->format('j');
    }

    /** Redondeo comercial half-up a 2 decimales sobre un string BCMath de mayor escala. */
    public static function roundHalfUp(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $offset, 6), '0', self::SCALE);
    }

    private static function parseDate(string $ymd): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if ($date === false || $date->format('Y-m-d') !== $ymd) {
            throw new InvalidArgumentException('Fecha inválida (YYYY-MM-DD): ' . $ymd);
        }

        return $date;
    }
}
