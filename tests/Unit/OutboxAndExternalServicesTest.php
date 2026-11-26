<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\Integrations\ExternalServiceTester;
use System\Support\Integrations\IncomingEventRecorder;
use System\Support\Integrations\IntegrationTransport;
use System\Support\Integrations\OutboxDispatcher;
use System\Support\Integrations\SecretResolver;

/**
 * Bloque C Paso 1 — outbox transaccional, webhooks entrantes y servicios externos (RNF-2 / RNF-8 / RNF-12).
 * Usa SQLite en memoria con el mismo esquema lógico de la migración 20261125000004.
 */
final class OutboxAndExternalServicesTest extends TestCase
{
    private const TEST_SECRET_REF = 'OPERAM_TEST_ERP_MASTER_KEY_REF';

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = Capsule::schema();
        $schema->create('external_service_configs', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('service_key', 40)->unique();
            $table->string('display_name', 100);
            $table->boolean('enabled')->default(false);
            $table->string('mode', 20)->default('sandbox');
            $table->text('config_json')->nullable();
            $table->text('secret_refs_json')->nullable();
            $table->dateTime('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->default('never');
            $table->string('last_test_message', 255)->nullable();
            $table->unsignedInteger('updated_by_user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $schema->create('outbox', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('destination', 30);
            $table->string('operation', 80);
            $table->string('idempotency_key', 140)->unique();
            $table->unsignedInteger('issuer_id')->nullable();
            $table->unsignedInteger('segment_id')->nullable();
            $table->string('entity_type', 60)->nullable();
            $table->string('entity_id', 50)->nullable();
            $table->text('payload_json');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->text('response_json')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('slack_alerted_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $schema->create('incoming_events', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('origin', 30);
            $table->string('external_id', 140);
            $table->string('event_type', 80);
            $table->text('payload_json');
            $table->boolean('processed')->default(false);
            $table->dateTime('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->unique(['origin', 'external_id']);
        });

        $this->seedService('jobs_outbox', false, 'sandbox', ['max_attempts' => 3, 'retry_backoff_minutes' => 5]);
        $this->seedService('erp_master', false, 'sandbox', ['base_url' => '', 'timeout_seconds' => 15]);
        $this->seedService('slack', false, 'sandbox', ['failure_threshold' => 3]);
        ExternalServiceRegistry::clearCache();
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::TEST_SECRET_REF]);
        ExternalServiceRegistry::clearCache();
    }

    public function testEnqueueIsIdempotentByKey(): void
    {
        $dispatcher = new OutboxDispatcher($this->failingTransport('No debe llamarse'));

        $first = $dispatcher->enqueue('erp_master', 'adjust_users', 'adjust_users:contract:10:created', ['users' => 5]);
        $second = $dispatcher->enqueue('erp_master', 'adjust_users', 'adjust_users:contract:10:created', ['users' => 99]);

        self::assertFalse($first['is_duplicate']);
        self::assertTrue($second['is_duplicate']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, Capsule::table('outbox')->count());
        // El payload original se conserva: el duplicado no sobrescribe.
        self::assertSame(['users' => 5], $second['payload']);
        self::assertSame('pending', $second['status']);
        self::assertSame(3, $second['max_attempts']);
    }

    public function testSandboxProcessingMarksMessageAsSentWithoutNetwork(): void
    {
        $dispatcher = new OutboxDispatcher($this->failingTransport('Sandbox no debe tocar la red'));
        $message = $dispatcher->enqueue('erp_master', 'suspend_instance', 'suspend_instance:contract:7:movement:1', ['reason' => 'churn']);

        $summary = $dispatcher->processPending();

        self::assertSame(1, $summary['processed']);
        self::assertSame(1, $summary['sent']);
        self::assertSame(0, $summary['failed']);

        $row = Capsule::table('outbox')->where('id', $message['id'])->first();
        self::assertNotNull($row);
        self::assertSame('sent', (string) $row->status);
        self::assertSame(1, (int) $row->attempts);
        self::assertNotNull($row->sent_at);
        $response = json_decode((string) $row->response_json, true);
        self::assertIsArray($response);
        self::assertTrue($response['sandbox']);
        self::assertSame('suspend_instance', $response['operation']);

        // Una segunda corrida no reprocesa mensajes enviados.
        self::assertSame(0, $dispatcher->processPending()['processed']);
    }

    public function testProductionFailureAppliesBackoffAndAlertsSlackOnce(): void
    {
        $_ENV[self::TEST_SECRET_REF] = 'test-only-value';
        $this->updateService('erp_master', true, 'production', ['base_url' => 'https://erp.example.test/api', 'timeout_seconds' => 5], ['api_key_ref' => self::TEST_SECRET_REF]);

        $transport = $this->failingTransport('HTTP 503: servicio no disponible');
        $dispatcher = new OutboxDispatcher($transport);
        $message = $dispatcher->enqueue('erp_master', 'adjust_users', 'adjust_users:contract:3:movement:9', ['users' => 2]);

        $before = time();
        $first = $dispatcher->processPending();
        self::assertSame(1, $first['failed']);
        self::assertSame(0, $first['slack_alerts']);

        $row = Capsule::table('outbox')->where('id', $message['id'])->first();
        self::assertSame('failed', (string) $row->status);
        self::assertSame(1, (int) $row->attempts);
        self::assertStringContainsString('HTTP 503', (string) $row->last_error);
        // Backoff = retry_backoff_minutes (5) × intentos (1).
        $delay = strtotime((string) $row->next_attempt_at . ' UTC') - $before;
        self::assertGreaterThanOrEqual(5 * 60 - 5, $delay);
        self::assertLessThanOrEqual(5 * 60 + 5, $delay);
        self::assertNull($row->slack_alerted_at);

        // Headers de producción: Bearer resuelto desde el entorno + llave de idempotencia.
        self::assertSame('https://erp.example.test/api', $transport->calls[0]['url']);
        self::assertSame('adjust_users:contract:3:movement:9', $transport->calls[0]['headers']['Idempotency-Key']);

        for ($i = 0; $i < 2; $i++) {
            $this->makeDueNow((int) $message['id']);
            $dispatcher->processPending();
        }

        $row = Capsule::table('outbox')->where('id', $message['id'])->first();
        self::assertSame(3, (int) $row->attempts);
        self::assertNotNull($row->slack_alerted_at, 'Al llegar al umbral se alerta a Slack (simulado en sandbox).');

        // Intentos agotados: el worker ya no lo toma aunque esté vencido.
        $this->makeDueNow((int) $message['id']);
        self::assertSame(0, $dispatcher->processPending()['processed']);
    }

    public function testManualRetryResetsAttemptsAndDeliversInSandbox(): void
    {
        $dispatcher = new OutboxDispatcher($this->failingTransport('No debe llamarse'));
        $message = $dispatcher->enqueue('erp_master', 'adjust_users', 'adjust_users:request:44', []);
        Capsule::table('outbox')->where('id', $message['id'])->update([
            'status' => 'failed',
            'attempts' => 3,
            'last_error' => 'HTTP 500',
        ]);

        $result = $dispatcher->retry((int) $message['id']);

        self::assertSame('sent', $result['status']);
        self::assertSame(1, $result['attempts']);
        self::assertNull($result['last_error']);
    }

    public function testIncomingEventsAreDeduplicatedByOriginAndExternalId(): void
    {
        $first = IncomingEventRecorder::record('integropay', 'pay_abc123', 'payment.succeeded', ['amount' => '1160.00']);
        $replay = IncomingEventRecorder::record('integropay', 'pay_abc123', 'payment.succeeded', ['amount' => '9999.00']);
        $otherOrigin = IncomingEventRecorder::record('erp_issuer', 'pay_abc123', 'cfdi.stamped', []);

        self::assertFalse($first['is_duplicate']);
        self::assertTrue($replay['is_duplicate']);
        self::assertSame($first['event']['id'], $replay['event']['id']);
        self::assertSame(['amount' => '1160.00'], $replay['event']['payload']);
        self::assertFalse($otherOrigin['is_duplicate']);
        self::assertSame(2, Capsule::table('incoming_events')->count());
    }

    public function testSecretRefsRejectPlainTextSecrets(): void
    {
        self::assertTrue(SecretResolver::isValidRefName('SMTP_PASSWORD_REF'));
        self::assertFalse(SecretResolver::isValidRefName('miPassword123!'));
        self::assertFalse(SecretResolver::isConfigured('OPERAM_REF_THAT_DOES_NOT_EXIST'));

        $refs = ExternalServiceRegistry::normalizeSecretRefs('smtp', ['password_ref' => 'SMTP_PASSWORD_REF']);
        self::assertSame(['password_ref' => 'SMTP_PASSWORD_REF'], $refs);

        $this->expectException(InvalidArgumentException::class);
        ExternalServiceRegistry::normalizeSecretRefs('smtp', ['password_ref' => 'sk_live_51Hx9-secret']);
    }

    public function testParsesBanxicoFixRateWithFourDecimals(): void
    {
        $parsed = ExternalServiceTester::parseBanxicoRate([
            'bmx' => ['series' => [['idSerie' => 'SF43718', 'datos' => [['fecha' => '24/11/2026', 'dato' => '18.4321']]]]],
        ]);

        self::assertSame(['rate_date' => '2026-11-24', 'rate' => '18.4321'], $parsed);
    }

    /** @param array<string, mixed> $config */
    private function seedService(string $key, bool $enabled, string $mode, array $config): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Capsule::table('external_service_configs')->insert([
            'service_key' => $key,
            'display_name' => $key,
            'enabled' => $enabled ? 1 : 0,
            'mode' => $mode,
            'config_json' => json_encode($config),
            'secret_refs_json' => json_encode([]),
            'last_test_status' => 'never',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $refs
     */
    private function updateService(string $key, bool $enabled, string $mode, array $config, array $refs): void
    {
        Capsule::table('external_service_configs')->where('service_key', $key)->update([
            'enabled' => $enabled ? 1 : 0,
            'mode' => $mode,
            'config_json' => json_encode($config),
            'secret_refs_json' => json_encode($refs),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        ExternalServiceRegistry::clearCache();
    }

    private function makeDueNow(int $id): void
    {
        Capsule::table('outbox')->where('id', $id)->update([
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);
    }

    /**
     * Transporte falso que registra llamadas y siempre lanza el error indicado.
     */
    private function failingTransport(string $message): IntegrationTransport
    {
        return new class ($message) implements IntegrationTransport {
            /** @var list<array{url: string, headers: array<string, string>, payload: array<string, mixed>}> */
            public array $calls = [];

            public function __construct(private readonly string $message)
            {
            }

            public function postJson(string $url, array $headers, array $payload, int $timeoutSeconds): array
            {
                $this->calls[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];
                throw new RuntimeException($this->message);
            }

            public function getJson(string $url, array $headers, int $timeoutSeconds): array
            {
                throw new RuntimeException($this->message);
            }

            public function sendEmail(array $smtp, array $message): array
            {
                throw new RuntimeException($this->message);
            }

            public function probeSmtp(array $smtp): void
            {
                throw new RuntimeException($this->message);
            }
        };
    }
}
