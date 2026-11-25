<?php

declare(strict_types=1);

namespace System\Support;

/**
 * Motor puro BCMath de Utilidad por segmento (RF-10) — sin I/O.
 * Calcula utilidad bruta, margen, unit economics y serie de tendencia.
 */
final class SegmentProfitabilityCalculator
{
    private const SCALE = 2;
    private const PCT_SCALE = 2;

    /**
     * KPIs de utilidad bruta a partir de ingresos y costos (strings monetarios).
     *
     * @return array{
     *   recurring_revenue_mxn: string,
     *   non_recurring_revenue_mxn: string,
     *   total_revenue_mxn: string,
     *   total_cost_mxn: string,
     *   gross_profit_mxn: string,
     *   gross_margin_pct: string
     * }
     */
    public static function computeGrossMetrics(
        string $recurringRevenueMxn,
        string $nonRecurringRevenueMxn,
        string $totalCostMxn,
    ): array {
        $recurring = FinancialCalculator::normalizeMoney($recurringRevenueMxn);
        $nonRecurring = FinancialCalculator::normalizeMoney($nonRecurringRevenueMxn);
        $cost = FinancialCalculator::normalizeMoney($totalCostMxn);
        $totalRevenue = bcadd($recurring, $nonRecurring, self::SCALE);
        $grossProfit = bcsub($totalRevenue, $cost, self::SCALE);
        $marginPct = self::marginPercent($grossProfit, $totalRevenue);

        return [
            'recurring_revenue_mxn' => $recurring,
            'non_recurring_revenue_mxn' => $nonRecurring,
            'total_revenue_mxn' => $totalRevenue,
            'total_cost_mxn' => $cost,
            'gross_profit_mxn' => $grossProfit,
            'gross_margin_pct' => $marginPct,
        ];
    }

    /**
     * Unit economics: por contrato activo y por usuario concurrente activo.
     *
     * @return array{
     *   active_contracts_count: int,
     *   active_concurrent_users_count: int,
     *   revenue_per_contract_mxn: string,
     *   cost_per_contract_mxn: string,
     *   profit_per_contract_mxn: string,
     *   revenue_per_user_mxn: string,
     *   cost_per_user_mxn: string,
     *   profit_per_user_mxn: string
     * }
     */
    public static function computeUnitEconomics(
        string $totalRevenueMxn,
        string $totalCostMxn,
        string $grossProfitMxn,
        int $activeContractsCount,
        int $activeConcurrentUsersCount,
    ): array {
        $contracts = max(0, $activeContractsCount);
        $users = max(0, $activeConcurrentUsersCount);

        return [
            'active_contracts_count' => $contracts,
            'active_concurrent_users_count' => $users,
            'revenue_per_contract_mxn' => self::perUnit($totalRevenueMxn, $contracts),
            'cost_per_contract_mxn' => self::perUnit($totalCostMxn, $contracts),
            'profit_per_contract_mxn' => self::perUnit($grossProfitMxn, $contracts),
            'revenue_per_user_mxn' => self::perUnit($totalRevenueMxn, $users),
            'cost_per_user_mxn' => self::perUnit($totalCostMxn, $users),
            'profit_per_user_mxn' => self::perUnit($grossProfitMxn, $users),
        ];
    }

    /**
     * Margen bruto % = (utilidad / ingresos) * 100; '0.00' si ingresos = 0.
     */
    public static function marginPercent(string $grossProfitMxn, string $totalRevenueMxn): string
    {
        $revenue = FinancialCalculator::normalizeMoney($totalRevenueMxn);
        if (bccomp($revenue, '0.00', self::SCALE) === 0) {
            return '0.00';
        }

        $profit = FinancialCalculator::normalizeMoney($grossProfitMxn);
        $ratio = bcdiv($profit, $revenue, 6);

        return bcmul($ratio, '100', self::PCT_SCALE);
    }

    /**
     * Divide monto entre unidades (BCMath); '0.00' si unidades = 0.
     */
    public static function perUnit(string $amountMxn, int $units): string
    {
        if ($units <= 0) {
            return '0.00';
        }

        return bcdiv(
            FinancialCalculator::normalizeMoney($amountMxn),
            (string) $units,
            self::SCALE,
        );
    }

    /**
     * Usuarios concurrentes equivalentes de una línea (paquetes × factor + individuales).
     */
    public static function concurrentUsersFromLine(
        int $quantity,
        bool $isPackageConverted,
        int $packageFactor,
    ): int {
        $qty = max(0, $quantity);
        if (!$isPackageConverted) {
            return $qty;
        }

        $factor = $packageFactor >= 2 ? $packageFactor : 5;

        return $qty * $factor;
    }

    /**
     * Genera las claves YYYY-MM de los últimos N meses (incluye el mes ancla).
     *
     * @return list<string>
     */
    public static function lastNMonthKeys(string $anchorMonth, int $count = 6): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $anchorMonth) || $count < 1) {
            return [];
        }

        $keys = [];
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $anchorMonth . '-01', new \DateTimeZone('UTC'));
        if ($dt === false) {
            return [];
        }

        for ($i = $count - 1; $i >= 0; $i--) {
            $keys[] = $dt->modify('-' . $i . ' months')->format('Y-m');
        }

        return $keys;
    }
}
