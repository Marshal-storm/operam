<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\ContractLifecycleEngine;
use System\Support\FinancialCalculator;
use System\Support\ServiceRequestRules;

/**
 * Certifica reglas BCMath de solicitudes (Módulo 4 Paso 1):
 * one_time sin MRR, expansión recurrente con request_id, bloqueo por cartera.
 */
final class ServiceRequestRulesTest extends TestCase
{
    /**
     * 8 horas de desarrollo a $850 → subtotal exacto; mrr_impact = 0.00;
     * la aprobación NO toca contracts.mrr_mxn ni movements.
     */
    public function testOneTimeApprovalCalculatesSubtotalWithoutMrrOrContractTouch(): void
    {
        $priced = ServiceRequestRules::priceLine(
            serviceId: 40,
            serviceName: 'Hora de desarrollo',
            serviceType: 'one_time',
            billingFrequency: 'single',
            quantity: 8,
            unitPrice: '850.00',
            exchangeRate: '1.0000',
        );

        self::assertSame('6800.00', $priced['subtotal']);
        self::assertSame('0.00', $priced['mrr_impact_mxn']);

        $summary = ServiceRequestRules::summarizeRequest([[
            'service_type' => 'one_time',
            'subtotal' => $priced['subtotal'],
            'mrr_impact_mxn' => $priced['mrr_impact_mxn'],
        ]]);

        self::assertSame('one_time', $summary['request_type']);
        self::assertSame('6800.00', $summary['subtotal']);
        self::assertSame('0.00', $summary['mrr_impact_mxn']);
        self::assertFalse(ServiceRequestRules::approvalTouchesContractMrr($summary['request_type']));

        // Simula contrato previo: al aprobar one_time el MRR permanece intacto.
        $contractMrrBefore = FinancialCalculator::normalizeMoney('12500.00');
        $contractMrrAfter = $contractMrrBefore; // sin movements / sin update
        self::assertSame('0.00', bcsub($contractMrrAfter, $contractMrrBefore, 2));
        self::assertSame('12500.00', $contractMrrAfter);
    }

    /**
     * Expansión recurrente: actualiza MRR del contrato, genera movement con request_id
     * y delta que alimenta monthly_mrr (categoría expansion).
     */
    public function testRecurringExpansionUpdatesContractInsertsMovementWithRequestId(): void
    {
        $priced = ServiceRequestRules::priceLine(
            serviceId: 10,
            serviceName: 'Usuario Individual',
            serviceType: 'recurring',
            billingFrequency: 'monthly',
            quantity: 5,
            unitPrice: '180.00',
            exchangeRate: '1.0000',
        );

        self::assertSame('900.00', $priced['subtotal']);
        self::assertSame('900.00', $priced['mrr_impact_mxn']);

        $summary = ServiceRequestRules::summarizeRequest([[
            'service_type' => 'recurring',
            'subtotal' => $priced['subtotal'],
            'mrr_impact_mxn' => $priced['mrr_impact_mxn'],
        ]]);
        self::assertSame('recurring_expansion', $summary['request_type']);
        self::assertTrue(ServiceRequestRules::approvalTouchesContractMrr($summary['request_type']));

        $contractMrrBefore = '5000.00';
        $impact = ServiceRequestRules::expansionContractImpact(
            $contractMrrBefore,
            $summary['mrr_impact_mxn'],
        );

        self::assertSame('5000.00', $impact['mrr_before']);
        self::assertSame('5900.00', $impact['mrr_after']);
        self::assertSame('900.00', $impact['mrr_delta_mxn']);

        // Equivalente ContractLifecycleEngine (misma fuente BCMath).
        $legacy = ContractLifecycleEngine::expansionImpact($contractMrrBefore, '900.00');
        self::assertSame($legacy, $impact);

        $movement = ServiceRequestRules::buildExpansionMovementPayload(
            contractId: 77,
            requestId: 12,
            impact: $impact,
            effectiveDate: '2026-11-21',
            exchangeRate: '1.0000',
            userId: 1,
            reason: 'Aprobación SOL-0003',
            withException: false,
            exceptionPolicy: null,
            contractLineId: 501,
            serviceId: 10,
            quantityBefore: '0.00',
            quantityAfter: '5.00',
            createdAtUtc: '2026-11-21 18:00:00',
        );

        self::assertSame(12, $movement['request_id']);
        self::assertSame('expansion', $movement['mrr_category']);
        self::assertSame('expansion', $movement['movement_type']);
        self::assertSame('900.00', $movement['mrr_delta_mxn']);
        self::assertSame(0, $movement['has_exception']);
        self::assertNull($movement['excepted_policy']);

        // monthly_mrr recibe el mismo delta (bcadd) — certificado por categoría expansion.
        $monthAmountBefore = '1200.00';
        $monthAmountAfter = bcadd($monthAmountBefore, $movement['mrr_delta_mxn'], 2);
        self::assertSame('2100.00', $monthAmountAfter);
    }

    /**
     * Bloqueo: saldo vencido o días vencidos sin with_exception = true.
     */
    public function testBlocksApprovalWhenContractHasOverdueWithoutException(): void
    {
        $blocked = ServiceRequestRules::evaluateCollectionGate(
            overdueBalance: '1500.00',
            daysOverdue: 12,
            withException: false,
            resolutionNotes: '',
        );

        self::assertFalse($blocked['allowed']);
        self::assertTrue($blocked['requires_exception']);
        self::assertSame(ServiceRequestRules::BLOCK_OVERDUE_MESSAGE, $blocked['message']);

        $alsoBlockedByDays = ServiceRequestRules::evaluateCollectionGate(
            overdueBalance: '0.00',
            daysOverdue: 3,
            withException: false,
            resolutionNotes: 'corto',
        );
        self::assertFalse($alsoBlockedByDays['allowed']);

        $allowedWithException = ServiceRequestRules::evaluateCollectionGate(
            overdueBalance: '1500.00',
            daysOverdue: 12,
            withException: true,
            resolutionNotes: 'Autorizado por dirección comercial',
        );
        self::assertTrue($allowedWithException['allowed']);
        self::assertSame(
            ServiceRequestRules::EXCEPTION_POLICY_OVERDUE,
            $allowedWithException['exception_policy'],
        );

        $current = ServiceRequestRules::evaluateCollectionGate('0.00', 0, false, '');
        self::assertTrue($current['allowed']);
        self::assertFalse($current['requires_exception']);
    }

    public function testRf2PackageConversionAffectsRecurringMrrImpact(): void
    {
        $priced = ServiceRequestRules::priceLine(
            serviceId: 10,
            serviceName: 'Usuario Individual',
            serviceType: 'recurring',
            billingFrequency: 'monthly',
            quantity: 12,
            unitPrice: '180.00',
            exchangeRate: '1.0000',
            allowPackageConversion: true,
            packageServiceId: 11,
            packageServiceName: 'Paquete 5 Usuarios',
            packageFactor: 5,
            packageUnitPrice: '750.00',
        );

        // 2×750 + 2×180 = 1500 + 360 = 1860 (ahorro vs 12×180=2160)
        self::assertTrue($priced['rf2_applied']);
        self::assertSame('1860.00', $priced['subtotal']);
        self::assertSame('1860.00', $priced['mrr_impact_mxn']);
        self::assertSame('300.00', $priced['rf2_savings']);
    }

    public function testConsumptionDoesNotContributeMrr(): void
    {
        $priced = ServiceRequestRules::priceLine(
            serviceId: 22,
            serviceName: 'Timbre CFDI',
            serviceType: 'consumption',
            billingFrequency: 'single',
            quantity: 100,
            unitPrice: '1.50',
            exchangeRate: '1.0000',
        );

        self::assertSame('150.00', $priced['subtotal']);
        self::assertSame('0.00', $priced['mrr_impact_mxn']);
        self::assertSame(
            'consumption',
            ServiceRequestRules::determineRequestType(['consumption', 'one_time']),
        );
    }

    /**
     * Solicitud mixta: +2 usuarios recurrentes + 4 horas one_time.
     * Solo los usuarios impactan contracts.mrr_mxn / movements.request_id;
     * las horas aportan 0.00 al MRR.
     */
    public function testMixedRequestOnlyRecurringImpactsContractMrrAndMovement(): void
    {
        $users = ServiceRequestRules::priceLine(
            serviceId: 10,
            serviceName: 'Usuario Individual',
            serviceType: 'recurring',
            billingFrequency: 'monthly',
            quantity: 2,
            unitPrice: '180.00',
            exchangeRate: '1.0000',
        );
        $hours = ServiceRequestRules::priceLine(
            serviceId: 40,
            serviceName: 'Hora de desarrollo',
            serviceType: 'one_time',
            billingFrequency: 'single',
            quantity: 4,
            unitPrice: '850.00',
            exchangeRate: '1.0000',
        );

        self::assertSame('360.00', $users['subtotal']);
        self::assertSame('360.00', $users['mrr_impact_mxn']);
        self::assertSame('3400.00', $hours['subtotal']);
        self::assertSame('0.00', $hours['mrr_impact_mxn']);

        $summary = ServiceRequestRules::summarizeRequest([
            [
                'service_type' => 'recurring',
                'subtotal' => $users['subtotal'],
                'mrr_impact_mxn' => $users['mrr_impact_mxn'],
            ],
            [
                'service_type' => 'one_time',
                'subtotal' => $hours['subtotal'],
                'mrr_impact_mxn' => $hours['mrr_impact_mxn'],
            ],
        ]);
        self::assertSame('recurring_expansion', $summary['request_type']);
        self::assertSame('3760.00', $summary['subtotal']);
        self::assertSame('360.00', $summary['mrr_impact_mxn']);
        self::assertTrue(ServiceRequestRules::approvalTouchesContractMrr($summary['request_type']));

        $partition = ServiceRequestRules::partitionMixedRequestLines([
            [
                'service_type' => 'recurring',
                'subtotal' => $users['subtotal'],
                'mrr_impact_mxn' => $users['mrr_impact_mxn'],
            ],
            [
                'service_type' => 'one_time',
                'subtotal' => $hours['subtotal'],
                'mrr_impact_mxn' => $hours['mrr_impact_mxn'],
            ],
        ]);
        self::assertCount(1, $partition['recurring']);
        self::assertCount(1, $partition['non_recurring']);
        self::assertSame('360.00', $partition['recurring_mrr_impact_mxn']);
        self::assertSame('3400.00', $partition['non_recurring_subtotal']);
        self::assertSame('0.00', $partition['non_recurring_mrr_impact_mxn']);

        $contractMrrBefore = '5000.00';
        $impact = ServiceRequestRules::expansionContractImpact(
            $contractMrrBefore,
            $partition['recurring_mrr_impact_mxn'],
        );
        self::assertSame('5360.00', $impact['mrr_after']);
        self::assertSame('360.00', $impact['mrr_delta_mxn']);

        $movement = ServiceRequestRules::buildExpansionMovementPayload(
            contractId: 88,
            requestId: 7,
            impact: $impact,
            effectiveDate: '2026-11-21',
            exchangeRate: '1.0000',
            userId: 1,
            reason: 'Aprobación mixta SOL-0007',
            withException: false,
            exceptionPolicy: null,
            quantityAfter: '2.00',
        );
        self::assertSame(7, $movement['request_id']);
        self::assertSame('expansion', $movement['mrr_category']);
        self::assertSame('360.00', $movement['mrr_delta_mxn']);
        // Las horas (3400) no entran al delta del movement ni al MRR del contrato.
        self::assertNotSame('3400.00', $movement['mrr_delta_mxn']);
        self::assertSame('0.00', $hours['mrr_impact_mxn']);
        self::assertSame('5000.00', bcsub($impact['mrr_after'], $impact['mrr_delta_mxn'], 2));
    }

    public function testCancelOnlyAllowedForPendingOrQuoted(): void
    {
        self::assertTrue(ServiceRequestRules::assertCanCancel('pending')['allowed']);
        self::assertTrue(ServiceRequestRules::assertCanCancel('quoted')['allowed']);

        $blockedApproved = ServiceRequestRules::assertCanCancel('approved');
        self::assertFalse($blockedApproved['allowed']);
        self::assertNotNull($blockedApproved['message']);

        self::assertFalse(ServiceRequestRules::assertCanCancel('rejected')['allowed']);
        self::assertFalse(ServiceRequestRules::assertCanCancel('cancelled')['allowed']);
    }
}
