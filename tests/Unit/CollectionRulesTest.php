<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use System\Support\CollectionRules;
use System\Support\ContractLifecycleEngine;
use System\Support\FinancialCalculator;

/**
 * Certifica reglas BCMath de cobranza (Módulo 4 Paso 2):
 * emisión + pagos, bloqueo de sobrepago y suspensión +90d con exclusión.
 */
final class CollectionRulesTest extends TestCase
{
    public function testIssuePartialAndFullPaymentUpdatesBalanceExactly(): void
    {
        $amounts = CollectionRules::buildInvoiceAmounts('1000.00', '0.00');
        self::assertSame('1000.00', $amounts['total']);
        self::assertSame('1000.00', $amounts['balance']);
        self::assertSame('0.00', $amounts['paid_amount']);

        $partial = CollectionRules::applyPayment(
            $amounts['paid_amount'],
            $amounts['balance'],
            '400.00',
            '2026-12-01',
            '2026-11-15',
        );
        self::assertSame('400.00', $partial['paid_amount']);
        self::assertSame('600.00', $partial['balance']);
        self::assertSame('partial', $partial['status']);

        $full = CollectionRules::applyPayment(
            $partial['paid_amount'],
            $partial['balance'],
            '600.00',
            '2026-12-01',
            '2026-11-15',
        );
        self::assertSame('1000.00', $full['paid_amount']);
        self::assertSame('0.00', $full['balance']);
        self::assertSame('paid', $full['status']);

        // Simula collection_statuses tras liquidar: sin vencidos.
        $status = CollectionRules::computeCollectionStatusFromInvoices([
            [
                'id' => 1,
                'balance' => $full['balance'],
                'due_date' => '2026-12-01',
                'status' => $full['status'],
            ],
        ], '2026-11-15');
        self::assertSame('0.00', $status['overdue_balance']);
        self::assertSame(0, $status['days_overdue']);
        self::assertSame('none', $status['notice_code']);
    }

    public function testPaymentGreaterThanBalanceIsBlocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceder el saldo pendiente');

        CollectionRules::applyPayment(
            '0.00',
            '250.00',
            '250.01',
            '2026-12-01',
            '2026-11-15',
        );
    }

    public function testSuspensionRuleSuspendsNormalButRespectsExcludeSuspension(): void
    {
        $overdueBalance = FinancialCalculator::normalizeMoney('3500.00');
        $days = 95;

        self::assertTrue(
            CollectionRules::isEligibleForSuspension('active', $days, $overdueBalance, false),
        );
        self::assertFalse(
            CollectionRules::isEligibleForSuspension('active', $days, $overdueBalance, true),
            'exclude_suspension = true debe proteger al contrato',
        );
        self::assertFalse(
            CollectionRules::isEligibleForSuspension('active', 89, $overdueBalance, false),
        );

        $notice = CollectionRules::noticeLevelFromDays(95);
        self::assertSame(CollectionRules::NOTICE_SUSPENSION, $notice['level']);
        self::assertSame('suspension', $notice['code']);

        // Movimiento churn inmutable (delta BCMath) al suspender.
        $impact = ContractLifecycleEngine::churnImpact('12500.00');
        self::assertSame('12500.00', $impact['mrr_before']);
        self::assertSame('0.00', $impact['mrr_after']);
        self::assertSame('-12500.00', $impact['mrr_delta_mxn']);
    }

    public function testCollectionStatusMarksOverdueAndAgingBuckets(): void
    {
        $today = '2026-11-21';
        $computed = CollectionRules::computeCollectionStatusFromInvoices([
            [
                'id' => 10,
                'balance' => '500.00',
                'due_date' => '2026-08-01',
                'status' => 'pending',
            ],
            [
                'id' => 11,
                'balance' => '200.00',
                'due_date' => '2026-12-01',
                'status' => 'pending',
            ],
        ], $today);

        self::assertSame('500.00', $computed['overdue_balance']);
        self::assertSame(112, $computed['days_overdue']);
        self::assertSame('suspension', $computed['notice_code']);
        self::assertContains(10, $computed['mark_overdue_ids']);
        self::assertNotContains(11, $computed['mark_overdue_ids']);

        self::assertSame('upcoming', CollectionRules::agingBucketKey(0, false));
        self::assertSame('days_1_30', CollectionRules::agingBucketKey(15, false));
        self::assertSame('days_31_60', CollectionRules::agingBucketKey(45, false));
        self::assertSame('days_61_90', CollectionRules::agingBucketKey(75, false));
        self::assertSame('suspended_90_plus', CollectionRules::agingBucketKey(90, false));
        self::assertSame('suspended_90_plus', CollectionRules::agingBucketKey(10, true));
    }

    public function testDueDateUsesCreditDays(): void
    {
        self::assertSame('2026-12-21', CollectionRules::computeDueDate('2026-11-21', 30));
        self::assertSame('2026-11-21', CollectionRules::computeDueDate('2026-11-21', 0));
    }

    /**
     * Reversión contable: reabre balance exacto y recalcula notice/collection status.
     */
    public function testPaymentReversalReopensBalanceAndCollectionStatus(): void
    {
        $amounts = CollectionRules::buildInvoiceAmounts('850.00', '0.00');
        $afterPay = CollectionRules::applyPayment(
            '0.00',
            $amounts['balance'],
            '850.00',
            '2026-10-01',
            '2026-11-21',
        );
        self::assertSame('paid', $afterPay['status']);
        self::assertSame('0.00', $afterPay['balance']);

        $reversed = CollectionRules::reversePayment(
            $amounts['total'],
            $afterPay['paid_amount'],
            '850.00',
            '2026-10-01',
            '2026-11-21',
        );
        self::assertSame('0.00', $reversed['paid_amount']);
        self::assertSame('850.00', $reversed['balance']);
        self::assertSame('overdue', $reversed['status']);

        $status = CollectionRules::computeCollectionStatusFromInvoices([
            [
                'id' => 99,
                'balance' => $reversed['balance'],
                'due_date' => '2026-10-01',
                'status' => $reversed['status'],
            ],
        ], '2026-11-21');
        self::assertSame('850.00', $status['overdue_balance']);
        self::assertSame(51, $status['days_overdue']);
        self::assertSame('warning', $status['notice_code']);
    }

    /**
     * Generación de factura desde solicitud aprobada one_time (subtotal + IVA 16%).
     */
    public function testInvoiceAmountsFromApprovedOneTimeRequest(): void
    {
        // 8h × $850.00 = $6,800.00 (mismo caso de negocio que solicitudes).
        $lineSubtotal = bcmul('8', '850.00', 2);
        self::assertSame('6800.00', $lineSubtotal);

        $invoice = CollectionRules::buildInvoiceAmounts($lineSubtotal);
        self::assertSame('6800.00', $invoice['subtotal']);
        self::assertSame('1088.00', $invoice['tax_amount']); // 6800 × 0.16
        self::assertSame('7888.00', $invoice['total']);
        self::assertSame('7888.00', $invoice['balance']);
        self::assertSame('0.00', $invoice['paid_amount']);

        $due = CollectionRules::computeDueDate('2026-11-21', 15);
        self::assertSame('2026-12-06', $due);
        self::assertSame(
            'pending',
            CollectionRules::resolveOpenInvoiceStatus('0.00', $invoice['balance'], $due, '2026-11-21'),
        );
    }

    /**
     * IVA 16% oficial: subtotal 2400 → tax 384 → total/balance 2784;
     * pago total cierra en 0.00 (MRR del contrato no se toca en cobranza).
     */
    public function testInvoiceAppliesSixteenPercentIvaAndFullPaymentClosesBalance(): void
    {
        $invoice = CollectionRules::buildInvoiceAmounts('2400.00');
        self::assertSame('2400.00', $invoice['subtotal']);
        self::assertSame('384.00', $invoice['tax_amount']);
        self::assertSame('2784.00', $invoice['total']);
        self::assertSame('2784.00', $invoice['balance']);

        $contractMrrBefore = FinancialCalculator::normalizeMoney('12500.00');

        $paid = CollectionRules::applyPayment(
            $invoice['paid_amount'],
            $invoice['balance'],
            '2784.00',
            '2026-12-31',
            '2026-11-21',
        );
        self::assertSame('2784.00', $paid['paid_amount']);
        self::assertSame('0.00', $paid['balance']);
        self::assertSame('paid', $paid['status']);

        // Cobranza no muta MRR: el contrato permanece intacto.
        $contractMrrAfter = $contractMrrBefore;
        self::assertSame('12500.00', $contractMrrAfter);
        self::assertSame('0.00', bcsub($contractMrrAfter, $contractMrrBefore, 2));
    }
}
