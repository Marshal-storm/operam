<?php

declare(strict_types=1);

namespace System\Support;

use InvalidArgumentException;
use System\Support\Jobs\BillingCycleRules;

/**
 * Motor puro del Monto Exigible Devengado (Págs. 9–10 del PDF de diseño) — RF-7.
 *
 * Fórmula oficial para decidir si un contrato está "al corriente":
 *
 *   Exigible = vencido anterior
 *            + (importe mensual efectivo × meses transcurridos del periodo, incl. el actual)
 *            − pagado del periodo
 *
 * Ejemplo (anualidad): contrato anual que se cobra 11 meses y cubre 12. Al mes 2 el cliente debe
 * 2/12 del importe anual; en cuanto liquida esa parte `is_current` pasa a `true` aunque la
 * factura anual completa siga abierta (lo devengado, no lo facturado, define lo exigible).
 *
 * Solo BCMath (escala interna 6, redondeo half-up a 2 decimales al final) — cero floats y sin I/O.
 */
final class ExigibleCalculator
{
    private const SCALE = 2;

    private const INTERNAL_SCALE = 6;

    private const MONTH_NAMES_ES = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    /** Etiqueta humana del plan según frecuencia del contrato. */
    private const PLAN_LABELS = [
        'monthly' => 'suscripción',
        'bimonthly' => 'suscripción bimestral',
        'quarterly' => 'suscripción trimestral',
        'semiannual' => 'suscripción semestral',
        'annual' => 'anualidad',
    ];

    /**
     * Evalúa si el contrato está al corriente y desglosa el adeudo.
     *
     * @param array{
     *   previous_overdue: string,
     *   period_total: string,
     *   period_months: int,
     *   elapsed_months: int,
     *   paid_in_period: string,
     *   period_start?: string|null,
     *   frequency?: string|null,
     *   contract_folio?: string|null
     * } $input
     * @return array{
     *   exigible_amount: string,
     *   is_current: bool,
     *   detail: string,
     *   previous_overdue: string,
     *   accrued_amount: string,
     *   effective_monthly_amount: string,
     *   paid_in_period: string,
     *   elapsed_months: int,
     *   unpaid_months: list<string>
     * }
     */
    public static function compute(array $input): array
    {
        $previousOverdue = self::nonNegative(FinancialCalculator::normalizeMoney($input['previous_overdue']));
        $paidInPeriod = self::nonNegative(FinancialCalculator::normalizeMoney($input['paid_in_period']));
        $periodTotal = self::nonNegative(FinancialCalculator::normalizeMoney($input['period_total']));
        $periodMonths = max(0, (int) $input['period_months']);
        $elapsed = $periodMonths > 0 ? max(0, min((int) $input['elapsed_months'], $periodMonths)) : 0;

        $accrued = self::accruedAmount($periodTotal, $periodMonths, $elapsed);
        $effectiveMonthly = self::effectiveMonthlyAmount($periodTotal, $periodMonths);

        $raw = bcsub(bcadd($previousOverdue, $accrued, self::SCALE), $paidInPeriod, self::SCALE);
        $isCurrent = bccomp($raw, '0.00', self::SCALE) <= 0;
        $exigible = $isCurrent ? '0.00' : $raw;

        $unpaidMonths = $isCurrent
            ? []
            : self::unpaidMonthLabels(
                $periodTotal,
                $periodMonths,
                $elapsed,
                $paidInPeriod,
                isset($input['period_start']) ? (string) $input['period_start'] : null,
            );

        return [
            'exigible_amount' => $exigible,
            'is_current' => $isCurrent,
            'detail' => self::buildDetail(
                $isCurrent,
                $unpaidMonths,
                bccomp($previousOverdue, '0.00', self::SCALE) > 0,
                (string) ($input['frequency'] ?? 'monthly'),
                (string) ($input['contract_folio'] ?? ''),
            ),
            'previous_overdue' => $previousOverdue,
            'accrued_amount' => $accrued,
            'effective_monthly_amount' => $effectiveMonthly,
            'paid_in_period' => $paidInPeriod,
            'elapsed_months' => $elapsed,
            'unpaid_months' => $unpaidMonths,
        ];
    }

    /**
     * Importe mensual efectivo = total del periodo ÷ meses del periodo (half-up a 2 decimales).
     */
    public static function effectiveMonthlyAmount(string $periodTotal, int $periodMonths): string
    {
        if ($periodMonths <= 0) {
            return '0.00';
        }
        $total = FinancialCalculator::normalizeMoney($periodTotal);

        return BillingCycleRules::roundHalfUp(
            bcdiv($total, (string) $periodMonths, self::INTERNAL_SCALE),
        );
    }

    /**
     * Importe devengado = total del periodo × meses transcurridos ÷ meses del periodo.
     * Se multiplica ANTES de dividir para no acumular error de redondeo (2/12 de 11,000.00 = 1,833.33).
     */
    public static function accruedAmount(string $periodTotal, int $periodMonths, int $elapsedMonths): string
    {
        if ($periodMonths <= 0 || $elapsedMonths <= 0) {
            return '0.00';
        }
        $total = FinancialCalculator::normalizeMoney($periodTotal);
        $elapsed = min($elapsedMonths, $periodMonths);

        return BillingCycleRules::roundHalfUp(
            bcdiv(bcmul($total, (string) $elapsed, self::INTERNAL_SCALE), (string) $periodMonths, self::INTERNAL_SCALE),
        );
    }

    /**
     * Fecha de inicio del periodo vigente (último corte <= hoy) partiendo del aniversario.
     * Devuelve null si el contrato aún no inicia o la frecuencia no es recurrente.
     */
    public static function currentPeriodStart(string $anniversaryYmd, string $todayYmd, string $frequency): ?string
    {
        if (!BillingCycleRules::isRecurringFrequency($frequency) || $anniversaryYmd > $todayYmd) {
            return null;
        }
        $anniversaryDay = BillingCycleRules::anniversaryDay($anniversaryYmd);
        $start = $anniversaryYmd;
        $guard = 0;
        while ($guard < 1200) {
            $next = BillingCycleRules::nextBillingDate($start, $frequency, $anniversaryDay);
            if ($next > $todayYmd) {
                break;
            }
            $start = $next;
            $guard++;
        }

        return $start;
    }

    /**
     * Meses transcurridos del periodo INCLUYENDO el actual (mínimo 1 si el periodo ya inició),
     * limitados a los meses del periodo (12 en anualidad).
     */
    public static function elapsedMonths(string $periodStartYmd, string $todayYmd, int $periodMonths): int
    {
        if ($periodMonths <= 0 || $periodStartYmd > $todayYmd) {
            return 0;
        }
        $anniversaryDay = BillingCycleRules::anniversaryDay($periodStartYmd);
        $cursor = $periodStartYmd;
        $elapsed = 1;
        while ($elapsed < $periodMonths) {
            $next = BillingCycleRules::nextBillingDate($cursor, 'monthly', $anniversaryDay);
            if ($next > $todayYmd) {
                break;
            }
            $cursor = $next;
            $elapsed++;
        }

        return $elapsed;
    }

    /**
     * Nombres de los meses devengados aún no cubiertos por el pago del periodo.
     * Un mes `k` está cubierto si lo pagado alcanza el devengado acumulado a ese mes.
     *
     * @return list<string> Meses en minúsculas ("agosto"), en orden cronológico.
     */
    private static function unpaidMonthLabels(
        string $periodTotal,
        int $periodMonths,
        int $elapsed,
        string $paidInPeriod,
        ?string $periodStartYmd,
    ): array {
        if ($elapsed <= 0 || $periodStartYmd === null || $periodStartYmd === '') {
            return [];
        }
        $startMonth = (int) substr($periodStartYmd, 5, 2);
        if ($startMonth < 1 || $startMonth > 12) {
            throw new InvalidArgumentException('Fecha de inicio de periodo inválida: ' . $periodStartYmd);
        }

        $labels = [];
        for ($month = 1; $month <= $elapsed; $month++) {
            $accruedToMonth = self::accruedAmount($periodTotal, $periodMonths, $month);
            if (bccomp($paidInPeriod, $accruedToMonth, self::SCALE) >= 0) {
                continue;
            }
            $labels[] = self::MONTH_NAMES_ES[(($startMonth - 1 + $month - 1) % 12) + 1];
        }

        return $labels;
    }

    /**
     * Texto legible para el cliente (es-MX). Ej.: "Agosto y septiembre de tu anualidad PF-2291".
     *
     * @param list<string> $unpaidMonths
     */
    private static function buildDetail(
        bool $isCurrent,
        array $unpaidMonths,
        bool $hasPreviousOverdue,
        string $frequency,
        string $contractFolio,
    ): string {
        $plan = self::PLAN_LABELS[$frequency] ?? 'suscripción';
        $subject = trim($plan . ' ' . $contractFolio);

        if ($isCurrent) {
            return sprintf('Tu %s está al corriente.', $subject);
        }

        if ($unpaidMonths === []) {
            return sprintf('Saldo vencido de periodos anteriores en tu %s.', $subject);
        }

        $detail = sprintf('%s de tu %s', self::joinMonths($unpaidMonths), $subject);

        return $hasPreviousOverdue ? $detail . ', más saldo vencido anterior' : $detail;
    }

    /**
     * @param list<string> $months
     */
    private static function joinMonths(array $months): string
    {
        $count = count($months);
        $text = $count === 1
            ? $months[0]
            : implode(', ', array_slice($months, 0, $count - 1)) . ' y ' . $months[$count - 1];

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private static function nonNegative(string $amount): string
    {
        return bccomp($amount, '0.00', self::SCALE) < 0 ? '0.00' : $amount;
    }
}
