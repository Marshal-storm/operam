<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use System\Core\Context;
use System\Services\CollectionsService;
use System\Services\JobsService;
use System\Support\AuditLogger;
use System\Support\BulkPriceAdjustmentProcessor;
use System\Support\BulkPriceAdjustmentRules;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\Jobs\BillingCycleRules;
use System\Support\Jobs\JobsRunner;

/**
 * Bloque C Paso 2 — Jobs de GCP (facturación diaria, reinicio de bolsas, cobranza, outbox),
 * reactivación automática RF-6.3 y Ajuste Masivo de Precios RF-3.6.
 * SQLite en memoria con el esquema lógico de las migraciones 20261125000004/5.
 */
final class JobsReactivationAndBulkPriceTest extends TestCase
{
    private const TEST_JOB_SECRET_REF = 'OPERAM_TEST_JOB_SECRET_REF';

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->createSchema();
        $this->seedCatalogs();
        ExternalServiceRegistry::clearCache();
        AuditLogger::clearEnabledCache();
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::TEST_JOB_SECRET_REF]);
        ExternalServiceRegistry::clearCache();
        AuditLogger::clearEnabledCache();
    }

    // ── Reglas puras del ciclo de facturación ───────────────────────────────

    public function testAnniversaryOn31stClampsToLastDayOfShortMonths(): void
    {
        self::assertSame('2026-02-28', BillingCycleRules::nextBillingDate('2026-01-31', 'monthly', 31));
        self::assertSame('2026-03-31', BillingCycleRules::nextBillingDate('2026-02-28', 'monthly', 31));
        self::assertSame('2026-04-30', BillingCycleRules::nextBillingDate('2026-03-31', 'monthly', 31));
        self::assertSame('2028-02-29', BillingCycleRules::nextBillingDate('2028-01-31', 'monthly', 31));
        self::assertSame('2027-02-28', BillingCycleRules::nextBillingDate('2026-11-30', 'quarterly', 30));
        self::assertSame('2026-02-27', BillingCycleRules::periodEnd('2026-01-31', 'monthly', 31));
        self::assertSame('2026-12-25', BillingCycleRules::rollForwardTo('2026-08-25', '2026-11-26', 'monthly', 25));
    }

    // ── Job 1: daily_billing ───────────────────────────────────────────────

    public function testDailyBillingCreatesInvoiceAdvancesDateEnqueuesPreinvoiceAndIsIdempotent(): void
    {
        $contractId = $this->createContract([
            'folio' => 'CT-0101',
            'anniversary_date' => '2026-01-31',
            'next_cutoff_date' => '2026-01-31',
            'mrr_mxn' => '1999.99',
        ]);
        $this->createLine($contractId, 1, ['unit_price' => '1500.00', 'subtotal' => '1500.00', 'mrr_mxn' => '1500.00']);
        $this->createLine($contractId, 2, ['unit_price' => '499.99', 'subtotal' => '499.99', 'mrr_mxn' => '499.99']);
        // Las líneas de consumo no forman parte de la factura recurrente.
        $this->createLine($contractId, 1, ['service_type' => 'consumption', 'unit_price' => '50.00', 'subtotal' => '50.00', 'mrr_mxn' => '0.00']);

        $run = (new JobsRunner('2026-01-31'))->run('daily_billing', 'cli', ['name' => 'Test CLI']);

        self::assertSame('completed', $run['status']);
        self::assertSame(1, $run['processed_count']);
        $invoice = Capsule::table('invoices')->where('contract_id', $contractId)->first();
        self::assertNotNull($invoice);
        self::assertSame('FAC-0001', (string) $invoice->folio);
        self::assertSame('recurring', (string) $invoice->invoice_type);
        self::assertSame('2026-01-31', (string) $invoice->period_start);
        self::assertSame('2026-02-27', (string) $invoice->period_end);
        self::assertSame('1999.99', $this->money($invoice->subtotal));
        $expectedTax = bcmul('1999.99', '0.16', 2);
        self::assertSame($expectedTax, $this->money($invoice->tax_amount));
        self::assertSame(bcadd('1999.99', $expectedTax, 2), $this->money($invoice->total));
        self::assertSame('2026-02-28', $this->contractValue($contractId, 'next_cutoff_date'));

        $outbox = Capsule::table('outbox')->where('operation', 'create_preinvoice')->get();
        self::assertCount(1, $outbox);
        self::assertSame('erp_issuer', (string) $outbox[0]->destination);
        self::assertSame(sprintf('preinvoice:%d:2026-01-31', $contractId), (string) $outbox[0]->idempotency_key);
        $payload = json_decode((string) $outbox[0]->payload_json, true);
        self::assertTrue($payload['stamp_on_issue']);
        self::assertSame('2026-01-31', $payload['invoice']['period_start']);

        // Segunda corrida el mismo día: el contrato ya no está vencido → cero duplicados.
        $second = (new JobsRunner('2026-01-31'))->run('daily_billing', 'cli', []);
        self::assertSame(0, $second['processed_count']);

        // Re-ejecución forzada del mismo periodo (p. ej. fecha revertida): se detecta el duplicado.
        Capsule::table('contracts')->where('id', $contractId)->update(['next_cutoff_date' => '2026-01-31']);
        $replay = (new JobsRunner('2026-01-31'))->run('daily_billing', 'cli', []);
        self::assertSame(0, $replay['processed_count']);
        self::assertSame(1, $replay['summary']['duplicates_skipped']);
        self::assertSame(1, Capsule::table('invoices')->count());
        self::assertSame(1, Capsule::table('outbox')->where('operation', 'create_preinvoice')->count());
        self::assertSame('2026-02-28', $this->contractValue($contractId, 'next_cutoff_date'));

        // Ciclo completo 31 ene → 28 feb → 31 mar → 30 abr.
        (new JobsRunner('2026-02-28'))->run('daily_billing', 'cli', []);
        self::assertSame('2026-03-31', $this->contractValue($contractId, 'next_cutoff_date'));
        (new JobsRunner('2026-03-31'))->run('daily_billing', 'cli', []);
        self::assertSame('2026-04-30', $this->contractValue($contractId, 'next_cutoff_date'));
        self::assertSame(3, Capsule::table('invoices')->count());
        self::assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31'],
            Capsule::table('invoices')->orderBy('id')->pluck('period_start')->map(static fn ($d): string => (string) $d)->all(),
        );

        $runs = Capsule::table('job_runs')->where('job_name', 'daily_billing')->get();
        self::assertCount(5, $runs);
        self::assertSame(['completed'], $runs->pluck('status')->unique()->values()->all());
        self::assertSame(['cli'], $runs->pluck('trigger_source')->unique()->values()->all());
    }

    // ── Job 2: reset_included_balances ─────────────────────────────────────

    public function testResetIncludedBalancesRecomputesGrantZeroesConsumptionAndLogsReset(): void
    {
        $contractId = $this->createContract(['folio' => 'CT-0102', 'anniversary_date' => '2026-03-15', 'next_cutoff_date' => '2026-12-15']);
        $this->createLine($contractId, 1, ['quantity' => 4, 'unit_price' => '100.00', 'subtotal' => '400.00', 'mrr_mxn' => '400.00']);
        Capsule::table('benefit_rules')->insert([
            'source_service_id' => 1,
            'benefit_service_id' => 3,
            'units_per_unit' => 100,
            'reset_period' => 'monthly',
            'is_cumulative' => 0,
            'active' => 1,
        ]);
        $balanceId = (int) Capsule::table('balances')->insertGetId([
            'contract_id' => $contractId,
            'service_id' => 3,
            'bucket' => 'included',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'granted_units' => 300,
            'consumed_units' => 120,
            'created_at' => '2026-10-01 06:00:00',
            'updated_at' => '2026-10-20 06:00:00',
        ]);

        $run = (new JobsRunner('2026-11-25'))->run('reset_included_balances', 'cloud_scheduler', ['name' => 'Cloud Scheduler']);

        self::assertSame('completed', $run['status']);
        self::assertSame(1, $run['processed_count']);
        $balance = Capsule::table('balances')->where('id', $balanceId)->first();
        self::assertSame(0, (int) $balance->consumed_units);
        self::assertSame(400, (int) $balance->granted_units, '4 unidades × 100 por unidad con la regla vigente.');
        self::assertSame('2026-11-01', (string) $balance->period_start);
        self::assertSame('2026-11-30', (string) $balance->period_end);

        $movement = Capsule::table('balance_movements')->where('balance_id', $balanceId)->first();
        self::assertNotNull($movement);
        self::assertSame('reset', (string) $movement->movement_type);
        self::assertSame(400, (int) $movement->units);
        self::assertStringContainsString('consumidas 120 → 0', (string) $movement->notes);

        // La bolsa ya está en periodo vigente: una segunda corrida no la vuelve a reiniciar.
        $again = (new JobsRunner('2026-11-25'))->run('reset_included_balances', 'cloud_scheduler', []);
        self::assertSame(0, $again['processed_count']);
        self::assertSame(1, Capsule::table('balance_movements')->count());
    }

    // ── RF-6.3: reactivación automática ────────────────────────────────────

    public function testPaymentSettlingLicenseDebtReactivatesContractAndEnqueuesInstance(): void
    {
        $today = $this->todayMx();
        $contractId = $this->createSuspendedContract('CT-0103', '1000.00');
        // Licencia vencida +120 días ya liquidada por el pago #77.
        $this->createInvoice($contractId, 'recurring', '0.00', $this->daysAgo(120), 'paid');
        // Otros servicios vencidos NO bloquean la reactivación (Pág. 6 del PDF).
        $this->createInvoice($contractId, 'one_time', '580.00', $this->daysAgo(120), 'overdue');

        $status = (new CollectionsService())
            ->withAuthContext(['user_id' => 1, 'name' => 'Tester'])
            ->recalculateContractCollectionStatus($contractId, 77);

        self::assertTrue($status['reactivated']);
        self::assertFalse($status['is_suspended']);
        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        self::assertSame('active', (string) $contract->status);
        self::assertSame('1000.00', $this->money($contract->mrr_mxn));
        self::assertGreaterThanOrEqual($today, (string) $contract->next_cutoff_date, 'Los periodos suspendidos no se facturan.');
        self::assertSame(1, (int) Capsule::table('contract_lines')->where('contract_id', $contractId)->where('active', 1)->count());
        self::assertSame(0, (int) Capsule::table('collection_statuses')->where('contract_id', $contractId)->value('is_suspended'));

        $movement = Capsule::table('movements')->where('contract_id', $contractId)->where('mrr_category', 'reactivation')->first();
        self::assertNotNull($movement);
        self::assertSame('0.00', $this->money($movement->mrr_before));
        self::assertSame('1000.00', $this->money($movement->mrr_after));
        self::assertSame('1000.00', $this->money($movement->mrr_delta_mxn));
        self::assertSame('Reactivación automática por liquidación de saldo vencido >90 días', (string) $movement->reason);
        self::assertSame('1000.00', $this->money(
            Capsule::table('monthly_mrr')->where('category', 'reactivation')->where('month', substr($today, 0, 7))->value('amount'),
        ));

        $outbox = Capsule::table('outbox')->where('operation', 'reactivate_instance')->first();
        self::assertNotNull($outbox);
        self::assertSame('erp_master', (string) $outbox->destination);
        self::assertSame(sprintf('reactivate:%d:77', $contractId), (string) $outbox->idempotency_key);

        // Idempotente: recalcular de nuevo no genera otra reactivación.
        (new CollectionsService())->recalculateContractCollectionStatus($contractId, 77);
        self::assertSame(1, Capsule::table('movements')->where('mrr_category', 'reactivation')->count());
    }

    public function testSuspendedContractWithLicenseDebtOver90DaysStaysSuspended(): void
    {
        $contractId = $this->createSuspendedContract('CT-0104', '800.00');
        $this->createInvoice($contractId, 'recurring', '928.00', $this->daysAgo(100), 'overdue');

        $status = (new CollectionsService())->recalculateContractCollectionStatus($contractId);

        self::assertFalse($status['reactivated']);
        self::assertTrue($status['is_suspended']);
        self::assertSame(90, $status['warning_level']);
        self::assertSame('suspended', $this->contractValue($contractId, 'status'));
        self::assertSame(0, Capsule::table('movements')->where('mrr_category', 'reactivation')->count());
        self::assertSame(0, Capsule::table('outbox')->count());
    }

    // ── RF-3.6: Ajuste Masivo de Precios ───────────────────────────────────

    public function testBulkPriceAdjustmentPlusTenPercentIsExactToTheCent(): void
    {
        // A: una línea con cupón (se quema) + una de consumo intacta.
        $contractA = $this->createContract(['folio' => 'CT-0201', 'mrr_mxn' => '1134.56']);
        $lineA = $this->createLine($contractA, 1, [
            'unit_price' => '1234.56', 'discount_amount' => '100.00', 'subtotal' => '1134.56', 'mrr_mxn' => '1134.56',
        ]);
        $consumption = $this->createLine($contractA, 1, ['service_type' => 'consumption', 'unit_price' => '10.00', 'subtotal' => '10.00', 'mrr_mxn' => '0.00']);
        // B: dos servicios recurrentes.
        $contractB = $this->createContract(['folio' => 'CT-0202', 'mrr_mxn' => '1199.99']);
        $this->createLine($contractB, 1, ['quantity' => 3, 'unit_price' => '333.33', 'subtotal' => '999.99', 'mrr_mxn' => '999.99']);
        $this->createLine($contractB, 2, ['unit_price' => '200.00', 'subtotal' => '200.00', 'mrr_mxn' => '200.00']);

        $filters = BulkPriceAdjustmentProcessor::normalizeFilters([]);
        $percentage = BulkPriceAdjustmentRules::normalizePercentage('10');
        $processor = new BulkPriceAdjustmentProcessor();

        // Vista previa UI-9: cero escrituras.
        $preview = $processor->preview($filters, $percentage);
        self::assertSame(2, $preview['summary']['affected_contracts_count']);
        self::assertSame(3, $preview['summary']['affected_lines_count']);
        self::assertSame('2334.55', $preview['summary']['current_total_mrr_mxn']);
        self::assertSame('2678.00', $preview['summary']['new_total_mrr_mxn']);
        self::assertSame('343.45', $preview['summary']['total_mrr_delta_mxn']);
        self::assertSame(0, Capsule::table('movements')->count());
        self::assertSame('1234.56', $this->money(Capsule::table('contract_lines')->where('id', $lineA)->value('unit_price')));

        $outcome = Capsule::connection()->transaction(
            static fn (): array => $processor->apply($filters, $percentage, 'Ajuste inflacionario 2027', 1, '2026-11-25'),
        );

        self::assertSame('AJ-2026-001', $outcome['batch_code']);
        $line = Capsule::table('contract_lines')->where('id', $lineA)->first();
        self::assertSame('1358.02', $this->money($line->unit_price));
        self::assertSame('0.00', $this->money($line->discount_amount), 'El cupón se quema al ajustar.');
        self::assertSame('1358.02', $this->money($line->subtotal));
        self::assertSame('10.00', $this->money(Capsule::table('contract_lines')->where('id', $consumption)->value('unit_price')));
        self::assertSame('1358.02', $this->money($this->contractValue($contractA, 'mrr_mxn')));
        self::assertSame('1319.98', $this->money($this->contractValue($contractB, 'mrr_mxn')));

        $movements = Capsule::table('movements')->where('mrr_category', 'price_adjustment')->orderBy('id')->get();
        self::assertCount(2, $movements);
        $deltas = [];
        foreach ($movements as $movement) {
            $deltas[(int) $movement->contract_id] = $this->money($movement->mrr_delta_mxn);
            self::assertStringStartsWith('AJ-2026-001: Ajuste inflacionario 2027', (string) $movement->reason);
        }
        ksort($deltas);
        self::assertSame([$contractA => '223.46', $contractB => '119.99'], $deltas);

        $monthlyTotal = '0.00';
        foreach (Capsule::table('monthly_mrr')->where('month', '2026-11')->where('category', 'price_adjustment')->pluck('amount') as $amount) {
            $monthlyTotal = bcadd($monthlyTotal, $this->money($amount), 2);
        }
        self::assertSame('343.45', $monthlyTotal);

        $batch = Capsule::table('bulk_price_adjustments')->first();
        self::assertSame('AJ-2026-001', (string) $batch->batch_code);
        self::assertSame('10.00', $this->money($batch->percentage));
        self::assertSame(2, (int) $batch->affected_contracts_count);
        self::assertSame('343.45', $this->money($batch->mrr_delta_total_mxn));

        // El siguiente lote del año incrementa la secuencia.
        $next = Capsule::connection()->transaction(
            static fn (): array => $processor->apply($filters, BulkPriceAdjustmentRules::normalizePercentage('-5'), 'Corrección comercial', 1, '2026-11-26'),
        );
        self::assertSame('AJ-2026-002', $next['batch_code']);
    }

    public function testBulkPriceAdjustmentRejectsZeroPercentageAndShortReason(): void
    {
        try {
            BulkPriceAdjustmentRules::normalizePercentage('0');
            self::fail('Debe rechazar 0%.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        BulkPriceAdjustmentRules::normalizeReason('abc');
    }

    // ── Entrada HTTP de Cloud Scheduler ────────────────────────────────────

    public function testSchedulerEndpointRequiresConfiguredSecretWithConstantTimeComparison(): void
    {
        $context = $this->schedulerContext('process_outbox', 'valor-de-prueba');
        self::assertTrue(JobsService::isSchedulerRequest($context));

        $missing = (new JobsService())->handleSchedulerRequest($context);
        self::assertSame(503, $missing->getHttpCode(), 'Sin secreto en el entorno se niega el acceso.');

        $_ENV[self::TEST_JOB_SECRET_REF] = 'valor-de-prueba';
        $wrong = (new JobsService())->handleSchedulerRequest($this->schedulerContext('process_outbox', 'otro-valor'));
        self::assertSame(401, $wrong->getHttpCode());
        self::assertSame(0, Capsule::table('job_runs')->count());

        $ok = (new JobsService())->handleSchedulerRequest($context);
        self::assertTrue($ok->isSuccess(), $ok->getMessage());
        self::assertSame('cloud_scheduler', (string) Capsule::table('job_runs')->value('trigger_source'));
    }

    // ── Utilidades de prueba ───────────────────────────────────────────────

    private function schedulerContext(string $jobName, string $secret): Context
    {
        $context = new Context([], [JobsService::SECRET_HEADER => $secret]);
        $context->setUri('jobs/run/' . $jobName);
        $context->setMethod('POST');

        return $context;
    }

    private function createSuspendedContract(string $folio, string $lineSubtotal): int
    {
        $suspendedAt = '2026-09-01 10:00:00';
        $contractId = $this->createContract([
            'folio' => $folio,
            'status' => 'suspended',
            'anniversary_date' => '2026-01-25',
            'next_cutoff_date' => '2026-08-25',
            'mrr_mxn' => '0.00',
        ]);
        $this->createLine($contractId, 1, [
            'unit_price' => $lineSubtotal,
            'subtotal' => $lineSubtotal,
            'mrr_mxn' => '0.00',
            'active' => 0,
            'updated_at' => $suspendedAt,
        ]);
        Capsule::table('collection_statuses')->insert([
            'contract_id' => $contractId,
            'overdue_balance' => $lineSubtotal,
            'overdue_days' => 95,
            'notice_level' => 4,
            'is_suspended' => 1,
            'updated_at' => $suspendedAt,
        ]);
        Capsule::table('movements')->insert([
            'contract_id' => $contractId,
            'movement_type' => 'churn',
            'mrr_category' => 'churn',
            'effective_date' => '2026-09-01',
            'quantity_before' => '1.00',
            'quantity_after' => '0.00',
            'mrr_before' => $lineSubtotal,
            'mrr_after' => '0.00',
            'mrr_delta_mxn' => '-' . $lineSubtotal,
            'exchange_rate' => '1.000000',
            'reason' => 'Suspensión automática por morosidad (+90 días vencidos)',
            'has_exception' => 0,
            'created_at' => $suspendedAt,
        ]);

        return $contractId;
    }

    /** @param array<string, mixed> $overrides */
    private function createContract(array $overrides): int
    {
        return (int) Capsule::table('contracts')->insertGetId(array_merge([
            'folio' => 'CT-0000',
            'client_id' => 1,
            'segment_id' => 1,
            'issuer_id' => 1,
            'instance_id' => 1,
            'status' => 'active',
            'frequency' => 'monthly',
            'anniversary_date' => '2026-01-15',
            'next_cutoff_date' => '2026-12-15',
            'credit_days' => 15,
            'currency' => 'MXN',
            'exchange_rate' => '1.000000',
            'stamp_on_issue' => 1,
            'mrr_mxn' => '0.00',
            'termination_reason' => null,
            'created_at' => '2026-01-01 06:00:00',
            'updated_at' => '2026-01-01 06:00:00',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createLine(int $contractId, int $serviceId, array $overrides): int
    {
        return (int) Capsule::table('contract_lines')->insertGetId(array_merge([
            'contract_id' => $contractId,
            'service_id' => $serviceId,
            'service_type' => 'recurring',
            'billing_frequency' => 'monthly',
            'quantity' => 1,
            'unit_price' => '0.00',
            'discount_amount' => '0.00',
            'subtotal' => '0.00',
            'mrr_mxn' => '0.00',
            'is_package_converted' => 0,
            'active' => 1,
            'created_at' => '2026-01-01 06:00:00',
            'updated_at' => '2026-01-01 06:00:00',
        ], $overrides));
    }

    private function createInvoice(int $contractId, string $type, string $balance, string $dueDate, string $status): void
    {
        static $sequence = 0;
        $sequence++;
        Capsule::table('invoices')->insert([
            'folio' => sprintf('FAC-T%03d', $sequence),
            'contract_id' => $contractId,
            'client_id' => 1,
            'segment_id' => 1,
            'issuer_id' => 1,
            'invoice_type' => $type,
            'concept' => 'Prueba',
            'currency' => 'MXN',
            'subtotal' => '1000.00',
            'tax_amount' => '160.00',
            'total' => '1160.00',
            'paid_amount' => bcsub('1160.00', $balance, 2),
            'balance' => $balance,
            'issue_date' => $dueDate,
            'due_date' => $dueDate,
            'status' => $status,
            'created_at' => '2026-06-01 06:00:00',
            'updated_at' => '2026-06-01 06:00:00',
        ]);
    }

    private function contractValue(int $contractId, string $column): string
    {
        return (string) Capsule::table('contracts')->where('id', $contractId)->value($column);
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }

    private function todayMx(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    }

    private function daysAgo(int $days): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->modify(sprintf('-%d days', $days))->format('Y-m-d');
    }

    private function seedCatalogs(): void
    {
        $now = '2026-01-01 06:00:00';
        Capsule::table('users')->insert(['id' => 1, 'name' => 'Tester']);
        Capsule::table('segments')->insert(['id' => 1, 'name' => 'Corporativo']);
        Capsule::table('issuers')->insert(['id' => 1, 'name' => 'Emisor Demo', 'rfc' => 'EDE010101AAA', 'is_active' => 1]);
        Capsule::table('clients')->insert(['id' => 1, 'name' => 'Cliente Demo', 'rfc' => 'CDE010101AAA', 'erp_client_id' => 'ERP-1', 'issuer_id' => 1, 'segment_id' => 1]);
        Capsule::table('instances')->insert(['id' => 1, 'code' => 'INST-1', 'name' => 'Instancia Demo', 'access_url' => null]);
        Capsule::table('services')->insert([
            ['id' => 1, 'code' => 'LIC-USR', 'name' => 'Licencia por usuario', 'unit_of_measure' => 'user', 'package_factor' => 0, 'package_service_id' => null],
            ['id' => 2, 'code' => 'SOP-PREM', 'name' => 'Soporte premium', 'unit_of_measure' => 'service', 'package_factor' => 0, 'package_service_id' => null],
            ['id' => 3, 'code' => 'TIMB', 'name' => 'Timbres incluidos', 'unit_of_measure' => 'stamp', 'package_factor' => 0, 'package_service_id' => null],
        ]);
        foreach ([
            'jobs_outbox' => [['timezone' => 'America/Mexico_City', 'max_attempts' => 3, 'retry_backoff_minutes' => 5], ['job_secret_ref' => self::TEST_JOB_SECRET_REF]],
            'erp_master' => [['base_url' => '', 'timeout_seconds' => 15], []],
            'slack' => [['failure_threshold' => 3], []],
        ] as $key => [$config, $refs]) {
            Capsule::table('external_service_configs')->insert([
                'service_key' => $key,
                'display_name' => $key,
                'enabled' => 0,
                'mode' => 'sandbox',
                'config_json' => json_encode($config),
                'secret_refs_json' => json_encode($refs),
                'last_test_status' => 'never',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function createSchema(): void
    {
        $schema = Capsule::schema();
        $schema->create('users', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name', 120);
        });
        $schema->create('segments', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name', 80);
        });
        $schema->create('issuers', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name', 150);
            $t->string('rfc', 13);
            $t->boolean('is_active')->default(true);
        });
        $schema->create('clients', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name', 150);
            $t->string('rfc', 13)->nullable();
            $t->string('erp_client_id', 60)->nullable();
            $t->unsignedInteger('issuer_id')->nullable();
            $t->unsignedInteger('segment_id')->nullable();
        });
        $schema->create('instances', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('access_url', 255)->nullable();
        });
        $schema->create('services', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('code', 40);
            $t->string('name', 150);
            $t->string('unit_of_measure', 30)->nullable();
            $t->unsignedInteger('package_factor')->default(0);
            $t->unsignedInteger('package_service_id')->nullable();
        });
        $schema->create('contracts', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('folio', 20);
            $t->unsignedInteger('client_id');
            $t->unsignedInteger('segment_id');
            $t->unsignedInteger('issuer_id');
            $t->unsignedInteger('instance_id')->nullable();
            $t->string('status', 20);
            $t->string('frequency', 20);
            $t->date('anniversary_date');
            $t->date('next_cutoff_date')->nullable();
            $t->unsignedInteger('credit_days')->default(0);
            $t->string('currency', 3)->default('MXN');
            $t->decimal('exchange_rate', 12, 6)->default('1.000000');
            $t->boolean('stamp_on_issue')->default(false);
            $t->decimal('mrr_mxn', 14, 2)->default('0.00');
            $t->string('termination_reason', 255)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('contract_lines', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('contract_id');
            $t->unsignedInteger('service_id');
            $t->string('service_type', 20);
            $t->string('billing_frequency', 20);
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 14, 2);
            $t->decimal('discount_amount', 14, 2)->default('0.00');
            $t->decimal('subtotal', 14, 2);
            $t->decimal('mrr_mxn', 14, 2);
            $t->boolean('is_package_converted')->default(false);
            $t->boolean('active')->default(true);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('invoices', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('folio', 20);
            $t->unsignedInteger('contract_id')->nullable();
            $t->unsignedInteger('client_id');
            $t->unsignedInteger('segment_id');
            $t->unsignedInteger('issuer_id');
            $t->unsignedInteger('request_id')->nullable();
            $t->string('invoice_type', 20);
            $t->string('concept', 255);
            $t->string('currency', 3);
            $t->decimal('subtotal', 14, 2);
            $t->decimal('tax_amount', 14, 2);
            $t->decimal('total', 14, 2);
            $t->decimal('paid_amount', 14, 2)->default('0.00');
            $t->decimal('balance', 14, 2);
            $t->date('issue_date');
            $t->date('due_date');
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->string('status', 20);
            $t->string('stamped_uuid', 60)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by_user_id')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['contract_id', 'period_start']);
        });
        $schema->create('collection_statuses', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('contract_id')->unique();
            $t->decimal('overdue_balance', 14, 2)->default('0.00');
            $t->unsignedInteger('overdue_days')->default(0);
            $t->unsignedTinyInteger('notice_level')->default(0);
            $t->boolean('is_suspended')->default(false);
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('movements', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('contract_id');
            $t->unsignedInteger('contract_line_id')->nullable();
            $t->unsignedInteger('service_id')->nullable();
            $t->string('movement_type', 40);
            $t->string('mrr_category', 30);
            $t->date('effective_date');
            $t->decimal('quantity_before', 14, 2)->default('0.00');
            $t->decimal('quantity_after', 14, 2)->default('0.00');
            $t->decimal('mrr_before', 14, 2);
            $t->decimal('mrr_after', 14, 2);
            $t->decimal('mrr_delta_mxn', 14, 2);
            $t->decimal('exchange_rate', 12, 6);
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('request_id')->nullable();
            $t->string('reason', 255)->nullable();
            $t->boolean('has_exception')->default(false);
            $t->string('excepted_policy', 60)->nullable();
            $t->unsignedInteger('reversed_movement_id')->nullable();
            $t->dateTime('created_at');
        });
        $schema->create('monthly_mrr', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('month', 7);
            $t->unsignedInteger('segment_id');
            $t->unsignedInteger('issuer_id')->nullable();
            $t->unsignedInteger('service_id')->nullable();
            $t->string('category', 30);
            $t->decimal('amount', 14, 2);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('balances', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('contract_id');
            $t->unsignedInteger('service_id');
            $t->string('bucket', 20);
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->unsignedInteger('granted_units')->default(0);
            $t->unsignedInteger('consumed_units')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('balance_movements', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('balance_id');
            $t->string('movement_type', 20);
            $t->integer('units');
            $t->unsignedInteger('request_id')->nullable();
            $t->unsignedInteger('promotion_id')->nullable();
            $t->string('notes', 255)->nullable();
            $t->unsignedInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $schema->create('benefit_rules', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('source_service_id');
            $t->unsignedInteger('benefit_service_id');
            $t->unsignedInteger('units_per_unit');
            $t->string('reset_period', 20);
            $t->boolean('is_cumulative')->default(false);
            $t->boolean('active')->default(true);
        });
        $schema->create('job_runs', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('job_name', 40);
            $t->string('trigger_source', 20);
            $t->string('status', 20);
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->unsignedInteger('processed_count')->default(0);
            $t->text('summary_json')->nullable();
            $t->text('error_message')->nullable();
            $t->unsignedInteger('triggered_by_user_id')->nullable();
        });
        $schema->create('bulk_price_adjustments', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('batch_code', 30)->unique();
            $t->decimal('percentage', 6, 2);
            $t->text('filters_json')->nullable();
            $t->unsignedInteger('affected_contracts_count')->default(0);
            $t->decimal('mrr_delta_total_mxn', 14, 2);
            $t->string('reason', 255);
            $t->unsignedInteger('executed_by_user_id');
            $t->dateTime('created_at');
        });
        $schema->create('external_service_configs', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('service_key', 40)->unique();
            $t->string('display_name', 100);
            $t->boolean('enabled')->default(false);
            $t->string('mode', 20)->default('sandbox');
            $t->text('config_json')->nullable();
            $t->text('secret_refs_json')->nullable();
            $t->dateTime('last_tested_at')->nullable();
            $t->string('last_test_status', 20)->default('never');
            $t->string('last_test_message', 255)->nullable();
            $t->unsignedInteger('updated_by_user_id')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('outbox', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('destination', 30);
            $t->string('operation', 80);
            $t->string('idempotency_key', 140)->unique();
            $t->unsignedInteger('issuer_id')->nullable();
            $t->unsignedInteger('segment_id')->nullable();
            $t->string('entity_type', 60)->nullable();
            $t->string('entity_id', 50)->nullable();
            $t->text('payload_json');
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('max_attempts')->default(3);
            $t->dateTime('next_attempt_at')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->text('response_json')->nullable();
            $t->text('last_error')->nullable();
            $t->dateTime('slack_alerted_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('audit_logs', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('user_name_snapshot', 120)->nullable();
            $t->string('user_email_snapshot', 150)->nullable();
            $t->unsignedInteger('segment_id')->nullable();
            $t->string('module_key', 50);
            $t->string('entity_type', 60);
            $t->string('entity_id', 50)->nullable();
            $t->string('action', 30);
            $t->string('description', 255);
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at');
        });
    }
}
