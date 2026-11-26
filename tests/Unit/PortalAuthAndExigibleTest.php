<?php

declare(strict_types=1);

namespace Operam\Tests\Unit;

use Firebase\JWT\JWT;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use System\Core\Context;
use System\Services\AppService;
use System\Services\PortalAuthService;
use System\Services\PortalService;
use System\Support\ExigibleCalculator;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\PortalMagicLinkIssuer;
use System\Support\PortalSession;

/**
 * Bloque D Paso 1 — Portal del Cliente: motor de exigible devengado (BCMath), Magic Link de un
 * solo uso, expiración y aislamiento de scope `client_portal` (RNF-7 / RF-7).
 * Usa SQLite en memoria con el esquema lógico mínimo de las migraciones del portal.
 */
final class PortalAuthAndExigibleTest extends TestCase
{
    private const FREE_URIS = ['login', 'POST portal/auth/request-link', 'POST portal/auth/exchange'];

    private ?string $previousKey = null;

    protected function setUp(): void
    {
        $this->previousKey = getenv('JWT_TOKEN_KEY') !== false ? (string) getenv('JWT_TOKEN_KEY') : null;
        putenv('JWT_TOKEN_KEY=test-only-portal-signing-key-0123456789abcdef');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = Capsule::schema();
        $schema->create('clients', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 160);
            $table->unsignedInteger('segment_id')->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_email', 160)->nullable();
            $table->boolean('active')->default(true);
        });
        $schema->create('client_contacts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->string('name', 160);
            $table->string('email', 160);
            $table->boolean('active')->default(true);
            $table->boolean('receives_portal')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $schema->create('contracts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->string('folio', 40)->nullable();
        });
        $schema->create('portal_access_tokens', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('contract_id')->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('origin', 20);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
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

        $now = gmdate('Y-m-d H:i:s');
        Capsule::table('external_service_configs')->insert([
            'service_key' => 'smtp',
            'display_name' => 'smtp',
            'enabled' => 0,
            'mode' => 'sandbox',
            'config_json' => json_encode(['max_attempts' => 3, 'retry_backoff_minutes' => 5]),
            'secret_refs_json' => json_encode([]),
            'last_test_status' => 'never',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        ExternalServiceRegistry::clearCache();

        Capsule::table('clients')->insert([
            ['id' => 1, 'name' => 'Cliente Uno', 'segment_id' => 1, 'active' => 1],
            ['id' => 2, 'name' => 'Cliente Dos', 'segment_id' => 1, 'active' => 1],
        ]);
        Capsule::table('client_contacts')->insert([
            'id' => 10,
            'client_id' => 1,
            'name' => 'Ana Pérez',
            'email' => 'ana@cliente-uno.test',
            'active' => 1,
            'receives_portal' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Capsule::table('contracts')->insert([
            ['id' => 100, 'client_id' => 1, 'folio' => 'PF-1001'],
            ['id' => 200, 'client_id' => 2, 'folio' => 'PF-2001'],
        ]);
    }

    protected function tearDown(): void
    {
        ExternalServiceRegistry::clearCache();
        if ($this->previousKey === null) {
            putenv('JWT_TOKEN_KEY');
        } else {
            putenv('JWT_TOKEN_KEY=' . $this->previousKey);
        }
    }

    // ── Motor de exigible (BCMath) ──────────────────────────────────────────────────────

    public function testAnnualContractAtMonthTwoOwesTwelfthsAndIsCurrentAfterPaying(): void
    {
        // Ejemplo Pág. 10: anualidad de 12,000.00; en agosto-septiembre se deben 2/12.
        $input = [
            'previous_overdue' => '0.00',
            'period_total' => '12000.00',
            'period_months' => 12,
            'elapsed_months' => 2,
            'paid_in_period' => '0.00',
            'period_start' => '2026-08-01',
            'frequency' => 'annual',
            'contract_folio' => 'PF-2291',
        ];

        $owed = ExigibleCalculator::compute($input);
        self::assertSame('2000.00', $owed['exigible_amount']);
        self::assertFalse($owed['is_current']);
        self::assertSame('Agosto y septiembre de tu anualidad PF-2291', $owed['detail']);

        $input['paid_in_period'] = '2000.00';
        $paid = ExigibleCalculator::compute($input);
        self::assertSame('0.00', $paid['exigible_amount']);
        self::assertTrue($paid['is_current']);

        // Un mes después (mes 3) vuelve a haber adeudo de 1/12 exacto.
        $input['elapsed_months'] = 3;
        $next = ExigibleCalculator::compute($input);
        self::assertSame('1000.00', $next['exigible_amount']);
        self::assertSame('Octubre de tu anualidad PF-2291', $next['detail']);
    }

    public function testAccruedAmountMultipliesBeforeDividingAndRoundsHalfUp(): void
    {
        // 11,000.00 × 2 ÷ 12 = 1,833.333… → 1,833.33 (sin acumular error por mes).
        self::assertSame('1833.33', ExigibleCalculator::accruedAmount('11000.00', 12, 2));
        self::assertSame('916.67', ExigibleCalculator::effectiveMonthlyAmount('11000.00', 12));
        self::assertSame('0.00', ExigibleCalculator::accruedAmount('11000.00', 12, 0));
    }

    public function testPreviousOverdueIsAddedAndOverpaymentNeverGoesNegative(): void
    {
        $withDebt = ExigibleCalculator::compute([
            'previous_overdue' => '500.00',
            'period_total' => '1160.00',
            'period_months' => 1,
            'elapsed_months' => 1,
            'paid_in_period' => '0.00',
            'period_start' => '2026-09-01',
            'frequency' => 'monthly',
            'contract_folio' => 'PF-1001',
        ]);
        self::assertSame('1660.00', $withDebt['exigible_amount']);

        $overpaid = ExigibleCalculator::compute([
            'previous_overdue' => '0.00',
            'period_total' => '1160.00',
            'period_months' => 1,
            'elapsed_months' => 1,
            'paid_in_period' => '5000.00',
            'period_start' => '2026-09-01',
            'frequency' => 'monthly',
        ]);
        self::assertSame('0.00', $overpaid['exigible_amount']);
        self::assertTrue($overpaid['is_current']);
    }

    public function testPeriodStartAndElapsedMonthsFollowTheContractAnniversary(): void
    {
        self::assertSame(
            '2026-08-15',
            ExigibleCalculator::currentPeriodStart('2026-08-15', '2026-09-20', 'annual'),
        );
        self::assertSame(2, ExigibleCalculator::elapsedMonths('2026-08-15', '2026-09-20', 12));
        self::assertSame(1, ExigibleCalculator::elapsedMonths('2026-08-15', '2026-08-15', 12));
        self::assertNull(ExigibleCalculator::currentPeriodStart('2027-01-01', '2026-09-20', 'annual'));
    }

    // ── Magic Link ───────────────────────────────────────────────────────────────────────

    public function testMagicLinkIsSingleUseAndIssuesClientPortalJwt(): void
    {
        $token = $this->issueTokenForContact();
        $service = new PortalAuthService();

        $first = $service->exchange($token);
        self::assertTrue($first->isSuccess());
        $data = $first->getData();
        self::assertIsArray($data);
        self::assertSame('client_portal', $data['profile']['scope']);
        self::assertSame(1, $data['profile']['client_id']);
        self::assertSame(PortalSession::TTL_SECONDS, $data['expires_in']);

        $decoded = PortalSession::decode((string) $data['token']);
        self::assertSame(PortalSession::SCOPE, PortalSession::scopeOf($decoded));
        self::assertSame(1, (int) $decoded->client_id);
        self::assertSame(PortalSession::TTL_SECONDS, (int) $decoded->exp - (int) $decoded->iat);

        // Segundo canje del mismo token → 401 con el mensaje oficial.
        $second = $service->exchange($token);
        self::assertFalse($second->isSuccess());
        self::assertSame(401, $second->getHttpCode());
        self::assertSame(PortalAuthService::INVALID_LINK_MESSAGE, $second->getMessage());
    }

    public function testOnlyTheTokenHashIsPersisted(): void
    {
        $token = $this->issueTokenForContact();

        $row = Capsule::table('portal_access_tokens')->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $token), (string) $row->token_hash);
        self::assertNotSame($token, (string) $row->token_hash);
    }

    public function testExpiredMagicLinkIsRejected(): void
    {
        $plain = bin2hex(random_bytes(32));
        Capsule::table('portal_access_tokens')->insert([
            'client_id' => 1,
            'contact_id' => 10,
            'token_hash' => hash('sha256', $plain),
            'origin' => 'email',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
            'created_at' => gmdate('Y-m-d H:i:s', time() - 960),
        ]);

        $result = (new PortalAuthService())->exchange($plain);
        self::assertFalse($result->isSuccess());
        self::assertSame(401, $result->getHttpCode());
    }

    public function testMalformedOrUnknownTokenIsRejected(): void
    {
        $service = new PortalAuthService();

        self::assertSame(401, $service->exchange('not-a-token')->getHttpCode());
        self::assertSame(401, $service->exchange(str_repeat('a', 64))->getHttpCode());
    }

    public function testRequestLinkAnswersTheSameMessageForKnownAndUnknownEmails(): void
    {
        $service = new PortalAuthService();

        $known = $service->requestLink('ANA@cliente-uno.test');
        $unknown = $service->requestLink('nadie@desconocido.test');

        self::assertTrue($known->isSuccess());
        self::assertTrue($unknown->isSuccess());
        self::assertSame(PortalAuthService::GENERIC_REQUEST_MESSAGE, $known->getMessage());
        self::assertSame($known->getMessage(), $unknown->getMessage());
        // Solo el contacto registrado genera una liga.
        self::assertSame(1, Capsule::table('portal_access_tokens')->count());
        self::assertSame(1, Capsule::table('outbox')->where('operation', 'send_magic_link')->count());
        self::assertSame(422, $service->requestLink('no-es-correo')->getHttpCode());
    }

    public function testInactiveContactCannotExchangeAnIssuedLink(): void
    {
        $token = $this->issueTokenForContact();
        Capsule::table('client_contacts')->where('id', 10)->update(['receives_portal' => 0]);

        self::assertSame(401, (new PortalAuthService())->exchange($token)->getHttpCode());
    }

    // ── Aislamiento de scope y RLS ───────────────────────────────────────────────────────

    public function testClientPortalJwtIsForbiddenOutsidePortalRoutes(): void
    {
        $jwt = PortalSession::issueToken([
            'client_id' => 1,
            'contact_id' => 10,
            'contact_name' => 'Ana Pérez',
            'contact_email' => 'ana@cliente-uno.test',
            'contract_id' => null,
        ]);

        foreach (['contracts', 'clients', 'users', 'issuers', 'dashboard'] as $adminUri) {
            $context = $this->contextFor('GET', $adminUri, $jwt);
            AppService::beforeGetHook($context, self::FREE_URIS);
            self::assertTrue($context->hasResult(), "La ruta {$adminUri} debe bloquearse");
            self::assertSame(403, $context->getResult()?->getHttpCode());
        }

        // Dentro del portal sí pasa y expone el perfil aislado por client_id.
        $portal = $this->contextFor('GET', 'portal/overview', $jwt);
        AppService::beforeGetHook($portal, self::FREE_URIS);
        self::assertFalse($portal->hasResult());
        self::assertSame(1, $portal->getData('portal_auth')['client_id']);
    }

    public function testEnterpriseJwtCannotUsePortalRoutes(): void
    {
        $jwt = JWT::encode([
            'iat' => time(),
            'exp' => time() + 600,
            'jti' => ['scope' => 'enterprise', 'session_uid' => 'abc', 'user_id' => 1],
        ], (string) getenv('JWT_TOKEN_KEY'), 'HS256');

        $context = $this->contextFor('GET', 'portal/overview', $jwt);
        AppService::beforeGetHook($context, self::FREE_URIS);

        self::assertTrue($context->hasResult());
        self::assertSame(403, $context->getResult()?->getHttpCode());
    }

    public function testBidirectionalScopeWallAndCagedPortalCookie(): void
    {
        self::assertSame('operam_portal_session', PortalSession::COOKIE_NAME);
        self::assertSame('/api/portal', PortalSession::COOKIE_PATH);

        $portalJwt = PortalSession::issueToken([
            'client_id' => 1,
            'contact_id' => 10,
            'contact_name' => 'Ana Pérez',
            'contact_email' => 'ana@cliente-uno.test',
            'contract_id' => null,
        ]);

        // 1. JWT del portal (`scope: 'client_portal'`) → HTTP 403 en `/api/contracts`.
        $contractsWithPortalBearer = $this->contextFor('GET', 'contracts', $portalJwt);
        AppService::beforeGetHook($contractsWithPortalBearer, self::FREE_URIS);
        self::assertTrue($contractsWithPortalBearer->hasResult());
        self::assertSame(403, $contractsWithPortalBearer->getResult()?->getHttpCode());

        // 2. JWT interno (`scope: 'enterprise'`) en raíz o en `jti` → HTTP 403 en `/api/portal/overview`.
        $enterpriseRootJwt = JWT::encode([
            'iat' => time(),
            'exp' => time() + 600,
            'scope' => 'enterprise',
            'jti' => ['scope' => 'enterprise', 'session_uid' => 'sess-1', 'user_id' => 1],
        ], (string) getenv('JWT_TOKEN_KEY'), 'HS256');
        $portalWithEnterpriseBearer = $this->contextFor('GET', 'portal/overview', $enterpriseRootJwt);
        AppService::beforeGetHook($portalWithEnterpriseBearer, self::FREE_URIS);
        self::assertTrue($portalWithEnterpriseBearer->hasResult());
        self::assertSame(403, $portalWithEnterpriseBearer->getResult()?->getHttpCode());

        // 3. Cualquier otro `scope !== 'client_portal'` → HTTP 403 en `/api/portal/overview`.
        $foreignScopeJwt = JWT::encode([
            'iat' => time(),
            'exp' => time() + 600,
            'scope' => 'instance_only',
        ], (string) getenv('JWT_TOKEN_KEY'), 'HS256');
        $portalWithForeignScope = $this->contextFor('GET', 'portal/overview', $foreignScopeJwt);
        AppService::beforeGetHook($portalWithForeignScope, self::FREE_URIS);
        self::assertTrue($portalWithForeignScope->hasResult());
        self::assertSame(403, $portalWithForeignScope->getResult()?->getHttpCode());

        // 4. Cookie `operam_portal_session` ignorada en `/api/contracts` (401) y aceptada en `/api/portal/overview`.
        $cookieHeader = ['Cookie' => PortalSession::COOKIE_NAME . '=' . $portalJwt];
        $internalWithPortalCookie = new Context([], $cookieHeader);
        $internalWithPortalCookie->setMethod('GET');
        $internalWithPortalCookie->setUri('contracts');
        AppService::beforeGetHook($internalWithPortalCookie, self::FREE_URIS);
        self::assertTrue($internalWithPortalCookie->hasResult());
        self::assertSame(401, $internalWithPortalCookie->getResult()?->getHttpCode());

        $portalWithCookie = new Context([], $cookieHeader);
        $portalWithCookie->setMethod('GET');
        $portalWithCookie->setUri('portal/overview');
        AppService::beforeGetHook($portalWithCookie, self::FREE_URIS);
        self::assertFalse($portalWithCookie->hasResult());
        self::assertSame(1, $portalWithCookie->getData('portal_auth')['client_id']);
    }

    public function testOnlyThePortalAuthPostRoutesAreFree(): void
    {
        $free = $this->contextFor('POST', 'portal/auth/exchange', null);
        AppService::beforeGetHook($free, self::FREE_URIS);
        self::assertFalse($free->hasResult());

        // `GET portal/auth/me` y el resto del portal exigen sesión.
        foreach ([['GET', 'portal/auth/me'], ['GET', 'portal/overview'], ['GET', 'contracts']] as [$method, $uri]) {
            $context = $this->contextFor($method, $uri, null);
            AppService::beforeGetHook($context, self::FREE_URIS);
            self::assertSame(401, $context->getResult()?->getHttpCode(), "{$method} {$uri}");
        }
    }

    public function testForeignContractDetailIsForbidden(): void
    {
        $service = new PortalService();

        foreach ([200, 9999] as $contractId) {
            $context = $this->contextFor('GET', 'portal/contracts/' . $contractId, null);
            $context->setData('portal_auth', [
                'client_id' => 1,
                'contact_id' => 10,
                'contact_name' => 'Ana Pérez',
                'contact_email' => 'ana@cliente-uno.test',
                'contract_id' => null,
            ]);
            $context->setData('_resource_id', null);

            $result = $service->handle($context);
            self::assertFalse($result->isSuccess());
            self::assertSame(403, $result->getHttpCode());
            self::assertSame('Este contrato no pertenece a tu cuenta.', $result->getMessage());
        }
    }

    public function testPortalServiceRequiresAPortalSession(): void
    {
        $context = $this->contextFor('GET', 'portal/overview', null);

        self::assertSame(401, (new PortalService())->handle($context)->getHttpCode());
    }

    // ── Utilidades ───────────────────────────────────────────────────────────────────────

    /** Emite una liga para el contacto 10 y devuelve el token en claro extraído de la URL. */
    private function issueTokenForContact(): string
    {
        $contact = Capsule::table('client_contacts')->where('id', 10)->first();
        self::assertNotNull($contact);
        $issued = PortalMagicLinkIssuer::issueForContact($contact);

        self::assertSame(1, preg_match('/\/portal\/acceso\?t=([a-f0-9]{64})$/', $issued['url'], $matches));

        return $matches[1];
    }

    private function contextFor(string $method, string $uri, ?string $jwt): Context
    {
        $headers = $jwt !== null ? ['Authorization' => 'Bearer ' . $jwt] : [];
        $context = new Context([], $headers);
        $context->setMethod($method);
        $context->setUri($uri);

        return $context;
    }
}
