<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\ContractPricingEngine;
use System\Support\FinancialCalculator;

/**
 * Certifica el motor BCMath de nuevo contrato:
 * RF-2 (12 → 2×5 + 2), anual/12, USD→MXN y promoción porcentual.
 */
final class ContractPricingEngineTest extends TestCase
{
    public function testRf2ExpandTwelveUsersIntoPackagesAndSingles(): void
    {
        $lines = ContractPricingEngine::expandLineWithRf2(
            serviceId: 10,
            serviceName: 'Usuario Individual',
            serviceType: 'recurring',
            billingFrequency: 'monthly',
            quantity: 12,
            unitPrice: '180.00',
            allowPackageConversion: true,
            packageServiceId: 11,
            packageServiceName: 'Paquete 5 Usuarios',
            packageFactor: 5,
            packageUnitPrice: '750.00',
        );

        self::assertCount(2, $lines);
        self::assertSame(11, $lines[0]['service_id']);
        self::assertSame(2, $lines[0]['quantity']);
        self::assertSame('750.00', $lines[0]['unit_price']);
        self::assertSame('300.00', $lines[0]['rf2_savings']);
        self::assertSame(10, $lines[1]['service_id']);
        self::assertSame(2, $lines[1]['quantity']);
        self::assertSame('180.00', $lines[1]['unit_price']);
    }

    public function testAnnualLineMrrDividedByTwelve(): void
    {
        $subtotal = ContractPricingEngine::lineSubtotal(5, '1836.00');
        self::assertSame('9180.00', $subtotal);

        $mrr = ContractPricingEngine::lineMrrMxn('recurring', 'annual', $subtotal, '1.0000');
        self::assertSame('765.00', $mrr);
    }

    public function testUsdLineConvertedWithExchangeRate(): void
    {
        $subtotal = ContractPricingEngine::lineSubtotal(2, '50.00');
        self::assertSame('100.00', $subtotal);

        $mrr = ContractPricingEngine::lineMrrMxn('recurring', 'monthly', $subtotal, '19.5000');
        self::assertSame('1950.00', $mrr);
    }

    public function testPercentagePromotionDiscount(): void
    {
        $promo = ContractPricingEngine::applyPromotionDiscount('4359.00', 'percentage', '15.00');
        self::assertSame('653.85', $promo['discount_amount']);
    }

    public function testOneTimeAndConsumptionDoNotContributeMrr(): void
    {
        self::assertSame(
            '0.00',
            ContractPricingEngine::lineMrrMxn('single', 'single', '5000.00', '1.0000'),
        );
        self::assertSame(
            '0.00',
            ContractPricingEngine::lineMrrMxn('consumption', 'monthly', '120.00', '1.0000'),
        );
    }

    public function testFullNewContractScenarioMatchesOfficialNumbers(): void
    {
        // 12 usuarios RF-2 → 2 paquetes + 2 sueltos = 1860; + base 2499 = 4359 MRR MXN
        $opt = FinancialCalculator::optimizeUserPackages(12, '180.00', '750.00');
        self::assertSame('1860.00', $opt['optimizedTotal']);
        self::assertSame('300.00', $opt['savings']);

        $base = '2499.00';
        $users = $opt['optimizedTotal'];
        $recurring = bcadd($base, $users, 2);
        self::assertSame('4359.00', $recurring);

        $promo = ContractPricingEngine::applyPromotionDiscount($recurring, 'percentage', '10.00');
        self::assertSame('435.90', $promo['discount_amount']);

        $afterPromo = bcsub($recurring, $promo['discount_amount'], 2);
        self::assertSame('3923.10', $afterPromo);

        // Anual USD: bcdiv(100,12,2)=8.33 → bcmul(8.33,19.50,2)=162.43 (escala 2 intermedia)
        $annualUsd = ContractPricingEngine::lineMrrMxn('recurring', 'annual', '100.00', '19.5000');
        self::assertSame('162.43', $annualUsd);
    }

    /**
     * Contrato mixto: Licencia Base + 6 Paquetes de 5 + 97 Timbres CFDI (consumo).
     * Los timbres SÍ suman a initial_invoice_total y aportan 0.00 a mrr_mxn.
     */
    public function testMixedContractConsumptionAddsToInvoiceNotMrr(): void
    {
        $baseSubtotal = ContractPricingEngine::lineSubtotal(1, '2499.00');
        $packsSubtotal = ContractPricingEngine::lineSubtotal(6, '750.00');
        $stampsSubtotal = ContractPricingEngine::lineSubtotal(97, '1.50');

        self::assertSame('2499.00', $baseSubtotal);
        self::assertSame('4500.00', $packsSubtotal);
        // 97 × $1.50 = $145.50
        self::assertSame('145.50', $stampsSubtotal);

        $baseMrr = ContractPricingEngine::lineMrrMxn('recurring', 'monthly', $baseSubtotal, '1.0000');
        $packsMrr = ContractPricingEngine::lineMrrMxn('recurring', 'monthly', $packsSubtotal, '1.0000');
        $stampsMrr = ContractPricingEngine::lineMrrMxn('consumption', 'single', $stampsSubtotal, '1.0000');

        self::assertSame('2499.00', $baseMrr);
        self::assertSame('4500.00', $packsMrr);
        self::assertSame('0.00', $stampsMrr);

        $summary = ContractPricingEngine::summarizeInvoiceAndMrr([
            ['service_type' => 'recurring', 'subtotal' => $baseSubtotal, 'mrr_mxn' => $baseMrr],
            ['service_type' => 'recurring', 'subtotal' => $packsSubtotal, 'mrr_mxn' => $packsMrr],
            ['service_type' => 'consumption', 'subtotal' => $stampsSubtotal, 'mrr_mxn' => $stampsMrr],
        ]);

        self::assertSame('6999.00', $summary['recurring_subtotal']);
        self::assertSame('145.50', $summary['one_time_subtotal']);
        // Factura inicial = recurrente + consumo
        self::assertSame('7144.50', $summary['initial_invoice_total']);
        // Impacto MRR del mes: solo recurrentes
        self::assertSame('6999.00', $summary['mrr_mxn']);
    }

    public function testOutOfRangeQuantityThrowsDescriptiveError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cantidad fuera de los rangos configurados en esta lista (máx. 4)');

        ContractPricingEngine::resolveTierUnitPrice([
            ['min_quantity' => 1, 'max_quantity' => 2, 'unit_price' => '100.00'],
            ['min_quantity' => 3, 'max_quantity' => 4, 'unit_price' => '90.00'],
        ], 14);
    }
}
