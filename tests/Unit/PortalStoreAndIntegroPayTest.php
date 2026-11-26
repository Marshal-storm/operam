<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use System\Core\Context;
use System\Core\Result;
use System\Services\CustomService;
use System\Services\IntegroPayWebhookService;
use System\Services\PortalPaymentsService;
use System\Services\PortalRequestsService;
use System\Services\PortalStoreService;
use System\Support\AuditLogger;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\Integrations\IntegroPayGateway;

/**
 * Bloque D Paso 2 — Tienda del Portal con promociones de unidades extra (RF-2.8), adaptador
 * IntegroPAY (liga sandbox + webhook verificado e idempotente), reactivación RF-6.3 al pagar el
 * exigible desde el portal y solicitudes del portal con candado de cartera (RF-8.1).
 * SQLite en memoria con el esquema lógico de las migraciones 20261125000004–7.
 */
final class PortalStoreAndIntegroPayTest extends TestCase
{
    private const WEBHOOK_TOKEN_REF = 'OPERAM_TEST_INTEGROPAY_WEBHOOK_REF';

    private const WEBHOOK_TOKEN = 'token-webhook-de-prueba';

    private const SUSPENDED_AT = '2026-09-01 10:00:00';

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
        unset($_ENV[self::WEBHOOK_TOKEN_REF]);
        ExternalServiceRegistry::clearCache();
        AuditLogger::clearEnabledCache();
    }

    // ── RF-2.8: Tienda con promoción de unidades extra ─────────────────────

    public function testStorePurchaseOfThousandUnitsWithPromoGrantsSeparateMovementsAndLeavesMrrUntouched(): void
    {
        $contractId = $this->createContract(['folio' => 'CT-0301', 'mrr_mxn' => '1500.00']);
        $this->createLine($contractId, 1, ['quantity' => 10, 'unit_price' => '150.00', 'subtotal' => '1500.00', 'mrr_mxn' => '1500.00']);

        $quote = $this->call(new PortalStoreService(), 'GET', 'portal/store/quote', [
            'contract_id' => (string) $contractId,
            'service_id' => '3',
            'quantity' => '1000',
        ]);
        self::assertTrue($quote->isSuccess(), $quote->getMessage());
        $q = $quote->getData();
        self::assertSame('1.00', $q['unit_price'], 'Escalón de volumen ≥ 1,000 unidades.');
        self::assertSame('1000.00', $q['subtotal']);
        self::assertSame(bcmul('1000.00', '0.16', 2), $q['tax_amount']);
        self::assertSame('1160.00', $q['total']);
        self::assertSame(250, $q['extra_units']);
        self::assertSame(1250, $q['total_units_to_receive']);
        self::assertSame('25.00', $q['extra_units_pct']);

        $checkout = $this->call(new PortalStoreService(), 'POST', 'portal/store/checkout', [
            'contract_id' => $contractId,
            'service_id' => 3,
            'quantity' => 1000,
            'payment_method' => 'card_integropay',
        ]);
        self::assertSame(201, $checkout->getHttpCode(), $checkout->getMessage());
        $link = $checkout->getData()['payment_link'];
        self::assertTrue($link['is_sandbox']);
        self::assertStringStartsWith(IntegroPayGateway::SANDBOX_LINK_PREFIX, $link['external_link_id']);
        self::assertStringEndsWith('/portal/sandbox-pay/' . $link['external_link_id'], $link['url']);
        self::assertSame('1160.00', $link['amount']);
        $purchaseId = (int) $checkout->getData()['purchase_id'];
        self::assertSame('pending', (string) Capsule::table('purchases')->where('id', $purchaseId)->value('status'));
        self::assertSame(0, Capsule::table('balance_movements')->count(), 'Nada se acredita antes del pago.');

        $confirm = $this->call(new PortalPaymentsService(), 'POST', 'portal/sandbox-pay/' . $link['external_link_id'] . '/confirm');
        self::assertTrue($confirm->isSuccess(), $confirm->getMessage());

        $purchase = Capsule::table('purchases')->where('id', $purchaseId)->first();
        self::assertSame('paid', (string) $purchase->status);
        self::assertSame(250, (int) $purchase->gifted_units);

        $invoice = Capsule::table('invoices')->where('purchase_id', $purchaseId)->first();
        self::assertNotNull($invoice);
        self::assertSame('paid', (string) $invoice->status);
        self::assertSame('consumption', (string) $invoice->invoice_type);
        self::assertSame('1160.00', $this->money($invoice->total));
        self::assertSame('0.00', $this->money($invoice->balance));
        $payments = Capsule::table('payments')->where('invoice_id', (int) $invoice->id)->get();
        self::assertCount(1, $payments);
        self::assertSame('card_integropay', (string) $payments[0]->payment_method);
        self::assertSame('1160.00', $this->money($payments[0]->amount));

        $balance = Capsule::table('balances')->where('contract_id', $contractId)->where('service_id', 3)->where('bucket', 'prepaid')->first();
        self::assertNotNull($balance);
        self::assertSame(1250, (int) $balance->granted_units);
        $movements = Capsule::table('balance_movements')->where('balance_id', (int) $balance->id)->orderBy('id')->get();
        self::assertCount(2, $movements, 'Compra y regalo en movimientos SEPARADOS.');
        self::assertSame('purchase', (string) $movements[0]->movement_type);
        self::assertSame(1000, (int) $movements[0]->units);
        self::assertNull($movements[0]->promotion_id);
        self::assertSame('promotion', (string) $movements[1]->movement_type);
        self::assertSame(250, (int) $movements[1]->units);
        self::assertSame(1, (int) $movements[1]->promotion_id);

        // RF-2.8: la compra jamás toca precio pactado, líneas ni MRR.
        self::assertSame('1500.00', $this->money(Capsule::table('contracts')->where('id', $contractId)->value('mrr_mxn')));
        self::assertSame(1, Capsule::table('contract_lines')->where('contract_id', $contractId)->count());
        self::assertSame('150.00', $this->money(Capsule::table('contract_lines')->where('contract_id', $contractId)->value('unit_price')));
        self::assertSame(0, Capsule::table('movements')->count());
        self::assertSame(0, Capsule::table('monthly_mrr')->count());

        $paidLink = Capsule::table('payment_links')->where('external_link_id', $link['external_link_id'])->first();
        self::assertSame('paid', (string) $paidLink->status);
        self::assertSame(IntegroPayGateway::sandboxTransactionIdFor($link['external_link_id']), (string) $paidLink->transaction_id);
        self::assertSame((int) $payments[0]->id, (int) $paidLink->payment_id);
        self::assertSame(1, Capsule::table('outbox')->where('operation', 'create_preinvoice')->count());
    }

    // ── Webhook verificado e idempotente ───────────────────────────────────

    public function testWebhookRequiresTokenAndSameTransactionIdAppliesOnlyOnce(): void
    {
        $contractId = $this->createContract(['folio' => 'CT-0302', 'mrr_mxn' => '1000.00']);
        $invoiceId = $this->createInvoice($contractId, 'recurring', '1160.00', $this->daysAgo(5), 'overdue');

        $created = $this->call(new PortalPaymentsService(), 'POST', 'portal/payments/create-link', [
            'contract_id' => $contractId,
            'mode' => 'custom',
            'amount' => '500.00',
        ]);
        self::assertSame(201, $created->getHttpCode(), $created->getMessage());
        $externalLinkId = (string) $created->getData()['external_link_id'];
        $transactionId = IntegroPayGateway::sandboxTransactionIdFor($externalLinkId);

        // Sin token configurado / token inválido → 401 sin tocar nada.
        $_ENV[self::WEBHOOK_TOKEN_REF] = self::WEBHOOK_TOKEN;
        $forged = $this->webhook(1, ['transaction_id' => $transactionId, 'amount' => '99999.00'], 'token-falso');
        self::assertSame(401, $forged->getHttpCode());
        self::assertSame(0, Capsule::table('incoming_events')->count());

        // El monto del payload se ignora: se aplica lo verificado contra la pasarela.
        $first = $this->webhook(1, ['transaction_id' => $transactionId, 'amount' => '99999.00'], self::WEBHOOK_TOKEN);
        self::assertTrue($first->isSuccess(), $first->getMessage());
        self::assertSame('500.00', $first->getData()['amount']);

        $replay = $this->webhook(1, ['transaction_id' => $transactionId], self::WEBHOOK_TOKEN);
        self::assertTrue($replay->isSuccess());
        self::assertSame(200, $replay->getHttpCode());
        self::assertTrue($replay->getData()['idempotent_replay']);

        // Tercer intento por el simulador del portal: mismo id determinista → réplica.
        $simulated = $this->call(new PortalPaymentsService(), 'POST', 'portal/sandbox-pay/' . $externalLinkId . '/confirm');
        self::assertTrue($simulated->isSuccess(), $simulated->getMessage());

        self::assertSame(1, Capsule::table('payments')->count(), 'Un solo pago por transacción.');
        self::assertSame(1, Capsule::table('incoming_events')->count());
        self::assertSame(1, (int) Capsule::table('incoming_events')->value('processed'));
        $invoice = Capsule::table('invoices')->where('id', $invoiceId)->first();
        self::assertSame('500.00', $this->money($invoice->paid_amount));
        self::assertSame('660.00', $this->money($invoice->balance));
        self::assertSame('overdue', (string) $invoice->status, 'Abono parcial a un documento vencido: sigue vencido.');
    }

    // ── RF-6.3: reactivación al pagar el exigible desde el portal ──────────

    public function testPayingExigibleOnContractSuspendedOverNinetyDaysReactivatesIt(): void
    {
        $contractId = $this->createSuspendedContract('CT-0303', '1000.00');
        $this->createInvoice($contractId, 'recurring', '1160.00', $this->daysAgo(120), 'overdue');

        $created = $this->call(new PortalPaymentsService(), 'POST', 'portal/payments/create-link', [
            'contract_id' => $contractId,
            'mode' => 'exigible',
        ]);
        self::assertSame(201, $created->getHttpCode(), $created->getMessage());
        self::assertSame('1160.00', $created->getData()['amount']);

        $confirm = $this->call(new PortalPaymentsService(), 'POST', 'portal/sandbox-pay/' . $created->getData()['external_link_id'] . '/confirm');
        self::assertTrue($confirm->isSuccess(), $confirm->getMessage());
        self::assertTrue($confirm->getData()['contract_reactivated']);

        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        self::assertSame('active', (string) $contract->status);
        self::assertSame('1000.00', $this->money($contract->mrr_mxn));
        self::assertSame(0, (int) Capsule::table('collection_statuses')->where('contract_id', $contractId)->value('is_suspended'));
        self::assertSame(1, Capsule::table('movements')->where('contract_id', $contractId)->where('mrr_category', 'reactivation')->count());

        $paymentId = (int) Capsule::table('payments')->value('id');
        $outbox = Capsule::table('outbox')->where('operation', 'reactivate_instance')->first();
        self::assertNotNull($outbox);
        self::assertSame(sprintf('reactivate:%d:%d', $contractId, $paymentId), (string) $outbox->idempotency_key);
    }

    // ── RF-8.1: solicitudes del portal con candado de cartera ──────────────

    public function testAddUsersRequestIsBlockedWhenNotCurrentAndCreatedWithPortalOriginWhenCurrent(): void
    {
        $contractId = $this->createContract(['folio' => 'CT-0304', 'mrr_mxn' => '0.00']);
        $this->createLine($contractId, 1, ['quantity' => 2, 'unit_price' => '150.00', 'subtotal' => '300.00', 'mrr_mxn' => '0.00']);
        $overdueId = $this->createInvoice($contractId, 'recurring', '1160.00', $this->daysAgo(40), 'overdue');

        $payload = ['contract_id' => $contractId, 'request_type' => 'add_users', 'service_id' => 1, 'quantity' => 3, 'notes' => 'Área de ventas'];
        $blocked = $this->call(new PortalRequestsService(), 'POST', 'portal/requests', $payload);
        self::assertSame(422, $blocked->getHttpCode());
        self::assertSame(PortalRequestsService::NOT_CURRENT_MESSAGE, $blocked->getMessage());
        self::assertSame(0, Capsule::table('service_requests')->count());

        // Los servicios extra siguen permitidos con adeudo.
        $extra = $this->call(new PortalRequestsService(), 'POST', 'portal/requests', [
            'contract_id' => $contractId,
            'request_type' => 'extra_service',
            'service_id' => 3,
            'quantity' => 100,
        ]);
        self::assertSame(201, $extra->getHttpCode(), $extra->getMessage());

        // Al corriente: el alta de usuarios se registra con origen `portal`.
        Capsule::table('invoices')->where('id', $overdueId)->update([
            'paid_amount' => '1160.00',
            'balance' => '0.00',
            'status' => 'paid',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $created = $this->call(new PortalRequestsService(), 'POST', 'portal/requests', $payload);
        self::assertSame(201, $created->getHttpCode(), $created->getMessage());
        $data = $created->getData();
        self::assertSame('portal', $data['origin']);
        self::assertSame('add_users', $data['portal_request_type']);
        self::assertSame('recurring_expansion', $data['request_type']);
        self::assertSame('received', $data['portal_status']);
        self::assertSame('450.00', $data['subtotal']);
        self::assertSame([['service_name' => 'Licencia por usuario', 'quantity' => 3]], $data['items']);

        $row = Capsule::table('service_requests')->where('id', (int) $data['id'])->first();
        self::assertSame('portal', (string) $row->origin);
        self::assertSame('pending', (string) $row->status);
        self::assertSame('Contacto Demo', (string) $row->requested_by_name);
        self::assertSame(
            1,
            Capsule::table('outbox')->where('idempotency_key', 'portal_request_received:' . (int) $data['id'])->count(),
            'Acuse de recibo por correo vía outbox.',
        );
        self::assertSame(1, Capsule::table('audit_logs')->where('entity_type', 'service_requests')->where('entity_id', (string) $data['id'])->count());
    }

    // ── Utilidades de prueba ───────────────────────────────────────────────

    /** @param array<string, mixed> $request */
    private function call(CustomService $service, string $method, string $uri, array $request = []): Result
    {
        $context = new Context($request, []);
        $context->setUri($uri);
        $context->setMethod($method);
        $context->setData('portal_auth', [
            'client_id' => 1,
            'contact_name' => 'Contacto Demo',
            'contact_email' => 'contacto@cliente.test',
        ]);

        return $service->handle($context);
    }

    /** @param array<string, mixed> $payload */
    private function webhook(int $issuerId, array $payload, string $token): Result
    {
        $context = new Context($payload, ['X-Webhook-Token' => $token]);
        $context->setUri('webhooks/integropay/' . $issuerId);
        $context->setMethod('POST');
        $context->setData('_query', []);

        return (new IntegroPayWebhookService())->handle($context);
    }

    private function createSuspendedContract(string $folio, string $lineSubtotal): int
    {
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
            'updated_at' => self::SUSPENDED_AT,
        ]);
        Capsule::table('collection_statuses')->insert([
            'contract_id' => $contractId,
            'overdue_balance' => '1160.00',
            'overdue_days' => 120,
            'notice_level' => 4,
            'is_suspended' => 1,
            'updated_at' => self::SUSPENDED_AT,
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
            'created_at' => self::SUSPENDED_AT,
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
            'price_list_id' => 1,
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

    private function createInvoice(int $contractId, string $type, string $balance, string $dueDate, string $status): int
    {
        static $sequence = 0;
        $sequence++;

        return (int) Capsule::table('invoices')->insertGetId([
            'folio' => sprintf('FAC-P%03d', $sequence),
            'contract_id' => $contractId,
            'client_id' => 1,
            'segment_id' => 1,
            'issuer_id' => 1,
            'invoice_type' => $type,
            'concept' => 'Licencias',
            'currency' => 'MXN',
            'subtotal' => '1000.00',
            'tax_amount' => '160.00',
            'total' => '1160.00',
            'paid_amount' => bcsub('1160.00', $balance, 2),
            'balance' => $balance,
            'issue_date' => $dueDate,
            'due_date' => $dueDate,
            'period_start' => $dueDate,
            'status' => $status,
            'created_at' => '2026-06-01 06:00:00',
            'updated_at' => '2026-06-01 06:00:00',
        ]);
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
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
        Capsule::table('issuers')->insert([
            'id' => 1,
            'name' => 'Emisor Demo',
            'rfc' => 'EDE010101AAA',
            'is_active' => 1,
            'spei_bank_name' => 'Banco Demo',
            'spei_clabe' => '012345678901234567',
            'spei_account_holder' => 'Emisor Demo SA de CV',
            'spei_reference_hint' => null,
            'integropay_merchant_token_ref' => null,
            'integropay_device_token_ref' => null,
            'integropay_webhook_token_ref' => self::WEBHOOK_TOKEN_REF,
        ]);
        Capsule::table('clients')->insert(['id' => 1, 'name' => 'Cliente Demo', 'rfc' => 'CDE010101AAA', 'erp_client_id' => 'ERP-1', 'issuer_id' => 1, 'segment_id' => 1]);
        Capsule::table('instances')->insert(['id' => 1, 'code' => 'INST-1', 'name' => 'Instancia Demo', 'access_url' => null]);
        $service = static fn (int $id, string $code, string $name, string $unit, string $type, bool $public): array => [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'description' => null,
            'unit_of_measure' => $unit,
            'service_type' => $type,
            'segment_id' => 1,
            'is_active' => 1,
            'is_public' => $public ? 1 : 0,
            'allow_package_conversion' => 0,
            'package_factor' => 0,
            'package_service_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        Capsule::table('services')->insert([
            $service(1, 'LIC-USR', 'Licencia por usuario', 'user', 'recurring', false),
            $service(3, 'TIMB', 'Timbres', 'stamp', 'consumption', true),
        ]);
        Capsule::table('price_lists')->insert([
            'id' => 1,
            'segment_id' => 1,
            'name' => 'Lista Corporativo',
            'currency' => 'MXN',
            'is_default' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Capsule::table('price_list_items')->insert([
            ['price_list_id' => 1, 'service_id' => 1, 'frequency' => 'monthly', 'min_quantity' => 1, 'max_quantity' => null, 'unit_price' => '150.00'],
            ['price_list_id' => 1, 'service_id' => 3, 'frequency' => 'single', 'min_quantity' => 1, 'max_quantity' => 999, 'unit_price' => '1.20'],
            ['price_list_id' => 1, 'service_id' => 3, 'frequency' => 'single', 'min_quantity' => 1000, 'max_quantity' => null, 'unit_price' => '1.00'],
        ]);
        Capsule::table('promotions')->insert([
            'id' => 1,
            'code' => 'FOLIOS25',
            'name' => '+25% folios gratis',
            'is_active' => 1,
            'promo_mode' => 'extra_units',
            'valid_from' => '2020-01-01',
            'valid_until' => null,
            'service_id' => 3,
            'segment_id' => null,
            'extra_units_pct' => '25.00',
            'min_quantity' => 1000,
            'visible_in_portal' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            'jobs_outbox' => ['timezone' => 'America/Mexico_City', 'max_attempts' => 3, 'retry_backoff_minutes' => 5],
            'erp_master' => ['base_url' => '', 'timeout_seconds' => 15],
            'erp_issuer' => ['base_url' => '', 'timeout_seconds' => 15],
            'smtp' => ['host' => '', 'port' => 587],
            'slack' => ['failure_threshold' => 3],
            'integropay' => ['api_url' => '', 'timeout_seconds' => 20, 'link_ttl_hours' => 24],
        ] as $key => $config) {
            Capsule::table('external_service_configs')->insert([
                'service_key' => $key,
                'display_name' => $key,
                'enabled' => 0,
                'mode' => 'sandbox',
                'config_json' => json_encode($config),
                'secret_refs_json' => json_encode([]),
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
            $t->string('spei_bank_name', 120)->nullable();
            $t->string('spei_clabe', 18)->nullable();
            $t->string('spei_account_holder', 160)->nullable();
            $t->string('spei_reference_hint', 160)->nullable();
            $t->string('integropay_merchant_token_ref', 120)->nullable();
            $t->string('integropay_device_token_ref', 120)->nullable();
            $t->string('integropay_webhook_token_ref', 120)->nullable();
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
            $t->text('description')->nullable();
            $t->string('unit_of_measure', 30)->nullable();
            $t->string('service_type', 20);
            $t->unsignedInteger('segment_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_public')->default(false);
            $t->boolean('allow_package_conversion')->default(false);
            $t->unsignedInteger('package_factor')->default(0);
            $t->unsignedInteger('package_service_id')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('price_lists', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('segment_id');
            $t->string('name', 120);
            $t->string('currency', 3);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('price_list_items', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('price_list_id');
            $t->unsignedInteger('service_id');
            $t->string('frequency', 20);
            $t->unsignedInteger('min_quantity');
            $t->unsignedInteger('max_quantity')->nullable();
            $t->decimal('unit_price', 14, 4);
        });
        $schema->create('promotions', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('code', 40);
            $t->string('name', 120);
            $t->boolean('is_active')->default(true);
            $t->string('promo_mode', 20);
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->unsignedInteger('service_id')->nullable();
            $t->unsignedInteger('segment_id')->nullable();
            $t->decimal('extra_units_pct', 6, 2)->nullable();
            $t->unsignedInteger('min_quantity')->nullable();
            $t->boolean('visible_in_portal')->default(false);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('promotion_scopes', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('promotion_id');
            $t->unsignedInteger('segment_id')->nullable();
            $t->unsignedInteger('client_id')->nullable();
        });
        $schema->create('contracts', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('folio', 20);
            $t->unsignedInteger('client_id');
            $t->unsignedInteger('segment_id');
            $t->unsignedInteger('issuer_id');
            $t->unsignedInteger('instance_id')->nullable();
            $t->unsignedInteger('price_list_id')->nullable();
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
        $schema->create('purchases', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('client_id');
            $t->unsignedInteger('contract_id')->nullable();
            $t->string('status', 20)->default('pending');
            $t->decimal('subtotal', 14, 2);
            $t->decimal('tax_amount', 14, 2);
            $t->decimal('total', 14, 2);
            $t->string('currency', 3)->default('MXN');
            $t->string('origin', 20)->default('portal');
            $t->string('payment_method', 20)->default('card_integropay');
            $t->unsignedInteger('promotion_id')->nullable();
            $t->unsignedInteger('gifted_units')->default(0);
            $t->text('details_json')->nullable();
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
            $t->unsignedInteger('purchase_id')->nullable();
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
        });
        $schema->create('payments', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('invoice_id');
            $t->unsignedInteger('contract_id')->nullable();
            $t->unsignedInteger('client_id');
            $t->decimal('amount', 14, 2);
            $t->string('payment_method', 30);
            $t->string('reference', 120)->nullable();
            $t->date('payment_date');
            $t->text('notes')->nullable();
            $t->unsignedInteger('recorded_by_user_id')->nullable();
            $t->boolean('is_reversed')->default(false);
            $t->dateTime('reversed_at')->nullable();
            $t->string('reversal_reason', 255)->nullable();
            $t->unsignedInteger('reversed_by_user_id')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('payment_links', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('issuer_id');
            $t->unsignedInteger('client_id');
            $t->unsignedInteger('purchase_id')->nullable();
            $t->unsignedInteger('contract_id')->nullable();
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3)->default('MXN');
            $t->string('concept', 200);
            $t->string('gateway', 20)->default('integropay');
            $t->string('external_link_id', 120)->nullable();
            $t->string('transaction_id', 120)->nullable();
            $t->string('url', 500);
            $t->string('status', 20)->default('created');
            $t->dateTime('expires_at');
            $t->unsignedInteger('payment_id')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('payment_link_documents', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('payment_link_id');
            $t->unsignedInteger('invoice_id');
            $t->decimal('amount', 14, 2);
        });
        $schema->create('incoming_events', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('origin', 30);
            $t->string('external_id', 140);
            $t->string('event_type', 80);
            $t->text('payload_json');
            $t->boolean('processed')->default(false);
            $t->dateTime('processed_at')->nullable();
            $t->text('error')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->unique(['origin', 'external_id']);
        });
        $schema->create('service_requests', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('folio', 20);
            $t->unsignedInteger('contract_id');
            $t->unsignedInteger('client_id');
            $t->unsignedInteger('segment_id');
            $t->unsignedInteger('issuer_id');
            $t->string('origin', 20);
            $t->string('request_type', 30);
            $t->string('portal_request_type', 30)->nullable();
            $t->string('status', 20);
            $t->string('currency', 3);
            $t->decimal('subtotal', 14, 2);
            $t->decimal('mrr_impact_mxn', 14, 2);
            $t->text('notes')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->boolean('with_exception')->default(false);
            $t->string('exception_policy', 60)->nullable();
            $t->unsignedInteger('requested_by_user_id')->nullable();
            $t->string('requested_by_name', 120)->nullable();
            $t->unsignedInteger('resolved_by_user_id')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('service_request_items', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('service_request_id');
            $t->unsignedInteger('service_id');
            $t->string('service_type', 20);
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 14, 2);
            $t->decimal('subtotal', 14, 2);
            $t->decimal('mrr_impact_mxn', 14, 2);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
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
