<?php

declare(strict_types=1);

namespace System\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Support\Jobs\BillingCycleRules;

/**
 * Aplica la fórmula oficial de Monto Exigible Devengado (`ExigibleCalculator`) a contratos reales.
 *
 * Fuente única compartida por el tablero del Portal, el pago de saldo con IntegroPAY y el candado
 * de "cliente al corriente" de las solicitudes de cambio de plan (RF-8.1).
 */
final class ContractExigibleResolver
{
    /**
     * Facturas de licencias (recurring/mixed) no canceladas, agrupadas por contrato.
     *
     * @param list<int> $contractIds
     * @return array<int, list<object>>
     */
    public static function loadLicenseInvoices(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }
        $rows = Capsule::table('invoices')
            ->whereIn('contract_id', $contractIds)
            ->whereIn('invoice_type', CollectionRules::LICENSE_INVOICE_TYPES)
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'contract_id', 'total', 'paid_amount', 'balance', 'issue_date', 'due_date', 'period_start']);

        $byContract = [];
        foreach ($rows as $invoice) {
            $byContract[(int) $invoice->contract_id][] = $invoice;
        }

        return $byContract;
    }

    /**
     * Exigible de un contrato ya cargado.
     *
     * @param list<object> $licenseInvoices
     * @return array{result: array<string, mixed>, next_cutoff_date: string|null}
     */
    public static function forContract(object $contract, array $licenseInvoices, string $today): array
    {
        $frequency = (string) $contract->frequency;
        $accrues = BillingCycleRules::isRecurringFrequency($frequency)
            && in_array((string) $contract->status, ['active', 'suspended'], true);
        $periodMonths = $accrues ? BillingCycleRules::monthsForFrequency($frequency) : 0;
        $periodStart = $accrues
            ? ExigibleCalculator::currentPeriodStart((string) $contract->anniversary_date, $today, $frequency)
            : null;

        $previousOverdue = '0.00';
        $paidInPeriod = '0.00';
        $periodInvoiceTotal = '0.00';
        foreach ($licenseInvoices as $invoice) {
            $inCurrentPeriod = $periodStart !== null && (
                ($invoice->period_start !== null && (string) $invoice->period_start === $periodStart)
                || ($invoice->period_start === null && (string) $invoice->issue_date >= $periodStart)
            );
            if ($inCurrentPeriod) {
                $paidInPeriod = bcadd($paidInPeriod, FinancialCalculator::normalizeMoney((string) $invoice->paid_amount), 2);
                $periodInvoiceTotal = bcadd($periodInvoiceTotal, FinancialCalculator::normalizeMoney((string) $invoice->total), 2);
                continue;
            }
            $balance = FinancialCalculator::normalizeMoney((string) $invoice->balance);
            if (bccomp($balance, '0.00', 2) > 0 && (string) $invoice->due_date < $today) {
                $previousOverdue = bcadd($previousOverdue, $balance, 2);
            }
        }

        // Total del periodo con IVA (los cobros llevan 16%): factura vigente o, si aún no se
        // emite, MRR mensual congelado × meses del periodo × 1.16 (siempre BCMath).
        $periodTotal = bccomp($periodInvoiceTotal, '0.00', 2) > 0
            ? $periodInvoiceTotal
            : BillingCycleRules::roundHalfUp(bcmul(
                bcmul(FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn), (string) $periodMonths, 6),
                bcadd('1', CollectionRules::IVA_RATE, 2),
                6,
            ));

        $result = ExigibleCalculator::compute([
            'previous_overdue' => $previousOverdue,
            'period_total' => $periodTotal,
            'period_months' => $periodMonths,
            'elapsed_months' => $periodStart !== null
                ? ExigibleCalculator::elapsedMonths($periodStart, $today, $periodMonths)
                : 0,
            'paid_in_period' => $paidInPeriod,
            'period_start' => $periodStart,
            'frequency' => $frequency,
            'contract_folio' => (string) $contract->folio,
        ]);

        $nextCutoff = $contract->next_cutoff_date !== null ? (string) $contract->next_cutoff_date : null;
        if ($nextCutoff === null && $periodStart !== null) {
            $nextCutoff = BillingCycleRules::nextBillingDate(
                $periodStart,
                $frequency,
                BillingCycleRules::anniversaryDay((string) $contract->anniversary_date),
            );
        }

        return ['result' => $result, 'next_cutoff_date' => $nextCutoff];
    }

    /**
     * Exigible de un contrato por id (carga contrato + facturas de licencias).
     *
     * @return array{result: array<string, mixed>, next_cutoff_date: string|null}|null
     */
    public static function forContractId(int $contractId, ?string $today = null): ?array
    {
        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        if ($contract === null) {
            return null;
        }

        return self::forContract(
            $contract,
            self::loadLicenseInvoices([$contractId])[$contractId] ?? [],
            $today ?? gmdate('Y-m-d'),
        );
    }
}
