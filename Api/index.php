<?php

declare(strict_types=1);

/**
 * Entrada API del framework operam/servicios.
 * Solo `login`, `GET portal/auth/config` y `POST portal/auth/{request-link|exchange}` son públicos;
 * el resto exige JWT (AppService::before_get). Un JWT `client_portal` queda confinado a `/api/portal/*` (403 fuera).
 *
 * RNF-15: jamás devolver HTML de fatales PHP; excepciones → JSON 422/500.
 */

use System\Core\Context;
use System\Core\Result;
use System\Models\UsersModel;
use System\Services\AppService;
use System\Services\AuditLogsService;
use System\Services\BalancesService;
use System\Services\BenefitRulesService;
use System\Services\ClientContactsService;
use System\Services\ClientsService;
use System\Services\CollectionsService;
use System\Services\ContractsService;
use System\Services\DashboardMetricsService;
use System\Services\ExternalServicesService;
use System\Services\IncomingEventsService;
use System\Services\InstanceApiService;
use System\Services\IntegroPayWebhookService;
use System\Services\InstancesService;
use System\Services\IssuersService;
use System\Services\JobsService;
use System\Services\LoginService;
use System\Services\MovementsService;
use System\Services\OutboxService;
use System\Services\PriceListItemsService;
use System\Services\PortalAuthService;
use System\Services\PortalService;
use System\Services\PriceListsService;
use System\Services\PromotionsService;
use System\Services\RbacAuthHelper;
use System\Services\RolesService;
use System\Services\SegmentProfitabilityService;
use System\Services\SegmentsService;
use System\Services\ServiceRequestsService;
use System\Services\ServicesCatalogService;
use System\Services\SystemSettingsService;
use System\Services\UsersManagementService;

// Blindaje RNF-15: PHP no debe mezclar HTML de errores en respuestas JSON.
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/backend/bootstrap/env.php';
require_once dirname(__DIR__) . '/backend/bootstrap/eloquent.php';

operam_load_env();
operam_boot_eloquent();

// CORS local (Vite → API). Preflight OPTIONS sin body.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With, X-Instance-Token, X-Job-Secret, X-Webhook-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Emite un envelope JSON de error y termina la petición.
 *
 * @param array<string, mixed> $payload
 */
function operam_api_json_error(int $httpCode, array $payload): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /**
     * URIs libres: `login` (acceso interno), `GET portal/auth/config` (TTL/inactividad sin datos
     * sensibles) y los dos POST de autenticación sin contraseña del Portal Cliente. Cualquier otra
     * ruta `portal/*` exige JWT `client_portal` (RNF-7).
     * El webhook `webhooks/integropay/{issuerId}` NO va aquí: se despacha antes de la guardia con
     * su propio token por emisor (agregar `webhooks` liberaría el listado de `incoming-events`).
     */
    $freeuris = [
        'login',
        'GET portal/auth/config',
        'POST portal/auth/request-link',
        'POST portal/auth/exchange',
    ];

    $loginHooks = [
        'before_create' => [
            static function (Context $context): void {
                LoginService::beforeCreateHook($context);
            },
        ],
    ];

    $appHooks = [
        'before_get' => [
            static function (Context $context) use ($freeuris): void {
                AppService::beforeGetHook($context, $freeuris);
            },
        ],
    ];

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#^/api#', '', $path) ?: '/';
    $uri = trim((string) $path, '/');

    // Blindaje HTTP del Portal del Cliente: cero caché y sin fuga de Referer en todas las respuestas.
    $normalizedUri = strtolower($uri);
    $isPortalRoute = $normalizedUri === 'portal' || str_starts_with($normalizedUri, 'portal/');
    if ($isPortalRoute) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Referrer-Policy: no-referrer');
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $jsonBody = json_decode($rawBody, true);
    $requestPayload = is_array($jsonBody) ? $jsonBody : [];
    if ($method === 'GET') {
        $requestPayload = array_merge($requestPayload, $_GET);
    }

    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $context = new Context($requestPayload, $headers);
    $context->setMethod($method);
    $context->setUri($uri);

    $result = null;

    // Parámetros de query disponibles también en POST (p. ej. `?token=` del webhook de IntegroPAY).
    $context->setData('_query', $_GET);

    // API de instancias: autenticación por token propio (no JWT enterprise).
    if (str_starts_with($uri, 'instance-api')) {
        $result = (new InstanceApiService())->handle($context);
    } elseif (IntegroPayWebhookService::isWebhookRequest($uri)) {
        // Webhook IntegroPAY: token del emisor (`hash_equals`) + verificación por ID contra la pasarela.
        // Se despacha ANTES de la guardia JWT sin agregar `webhooks` a `$freeuris`: ese alias expone
        // el listado interno de `incoming-events`, que debe seguir protegido por JWT + RBAC.
        $result = (new IntegroPayWebhookService())->handle($context);
    } elseif (JobsService::isSchedulerRequest($context)) {
        // Cloud Scheduler: secreto compartido `X-Job-Secret` resuelto desde el cofre (RNF-8).
        $result = (new JobsService())->handleSchedulerRequest($context);
    } elseif ($uri === 'login' && $method === 'POST') {
        $loginService = new LoginService($loginHooks);
        $result = $loginService->handle($context);
    } else {
        $appService = new AppService($freeuris, $appHooks);
        $gate = $appService->handle($context);
        if (!$gate->isSuccess()) {
            $result = $gate;
        } else {
            $parts = $uri === '' ? [] : explode('/', $uri);
            $resource = strtolower((string) ($parts[0] ?? ''));
            $resourceId = isset($parts[1]) && ctype_digit((string) $parts[1])
                ? (int) $parts[1]
                : null;
            $context->setData('_resource_id', $resourceId);

            // Alias de recursos del Módulo 2 + RBAC.
            $resource = match ($resource) {
                'services_catalog', 'catalog/services' => 'services',
                'price_lists', 'catalog/price-lists' => 'price-lists',
                'price_list_items', 'price-list-items' => 'price-list-items',
                'catalog/instances' => 'instances',
                'catalog/promotions' => 'promotions',
                'benefit-rules', 'benefit_rules', 'catalog/benefit-rules' => 'benefit-rules',
                'client-contacts', 'client_contacts' => 'client-contacts',
                'emisores', 'emitters', 'catalog/issuers' => 'issuers',
                'clientes', 'catalog/clients' => 'clients',
                'contratos', 'catalog/contracts' => 'contracts',
                'solicitudes', 'service-requests', 'service_requests' => 'requests',
                'cobranza', 'collections' => 'collections',
                'movimientos', 'catalog/movements' => 'movements',
                'dashboard/mrr', 'mrr' => 'dashboard',
                'segment-profitability', 'utilidad-segmento', 'utilidad' => 'segment-profitability',
                'roles-permissions', 'rbac/roles' => 'roles',
                'users-management', 'usuarios' => 'users',
                'session', 'profile' => 'me',
                'external_services', 'servicios-externos' => 'external-services',
                'incoming_events', 'webhooks' => 'incoming-events',
                default => $resource,
            };

            $result = match ($resource) {
                'me' => $method === 'GET'
                    ? (static function (Context $context): Result {
                        // Perfil fresco desde MySQL (incluye módulos como config_issuers).
                        $auth = $context->getData('auth', []);
                        $userId = (int) ($auth['user_id'] ?? 0);
                        /** @var UsersModel|null $user */
                        $user = $userId > 0 ? UsersModel::query()->find($userId) : null;
                        if ($user === null) {
                            return Result::fail('Usuario de sesión no encontrado.', 401);
                        }
                        $rbac = RbacAuthHelper::buildProfileForUser($user);

                        return Result::ok([
                            'user' => [
                                'id' => (int) $user->id,
                                'email' => (string) $user->email,
                                'name' => (string) $user->name,
                                'role_id' => $rbac['role_id'],
                                'role_name' => $rbac['role_name'],
                                'role_code' => $rbac['role_code'],
                                'segment_id' => $rbac['segment_id'],
                                'segment_name' => $rbac['segment_name'],
                                'permissions' => $rbac['permissions'],
                                'can_authorize_exceptions' => (bool) ($user->can_authorize_exceptions ?? false),
                                'scope' => 'enterprise',
                            ],
                        ], 'Sesión activa');
                    })($context)
                    : Result::fail('Método no soportado.', 405),
                'roles' => (new RolesService())->handle($context),
                'users' => (new UsersManagementService())->handle($context),
                'services' => (new ServicesCatalogService())->handle($context),
                'instances' => (new InstancesService())->handle($context),
                'price-lists' => (new PriceListsService())->handle($context),
                'price-list-items' => (new PriceListItemsService())->handle($context),
                'promotions' => (new PromotionsService())->handle($context),
                'benefit-rules' => (new BenefitRulesService())->handle($context),
                'client-contacts' => (new ClientContactsService())->handle($context),
                'issuers' => (new IssuersService())->handle($context),
                'clients' => (new ClientsService())->handle($context),
                'contracts' => (new ContractsService())->handle($context),
                'requests' => (new ServiceRequestsService())->handle($context),
                'collections' => (new CollectionsService())->handle($context),
                'movements' => (new MovementsService())->handle($context),
                'dashboard' => (new DashboardMetricsService())->handle($context),
                'segment-profitability' => (new SegmentProfitabilityService())->handle($context),
                'balances' => (new BalancesService())->handle($context),
                'segments' => (new SegmentsService())->handle($context),
                'system-settings' => (new SystemSettingsService())->handle($context),
                'audit-logs' => (new AuditLogsService())->handle($context),
                'external-services' => (new ExternalServicesService())->handle($context),
                'outbox' => (new OutboxService())->handle($context),
                'incoming-events' => (new IncomingEventsService())->handle($context),
                'jobs' => (new JobsService())->handle($context),
                // Portal Cliente: autenticación sin contraseña + lectura aislada por client_id.
                'portal' => str_starts_with($uri, 'portal/auth')
                    ? (new PortalAuthService())->handle($context)
                    : (new PortalService())->handle($context),
                default => Result::ok([
                    'uri' => $uri,
                    'auth' => $context->getData('auth'),
                ], 'Autenticado'),
            };
        }
    }

    if (!$result instanceof Result) {
        $result = Result::fail('Sin resultado de servicio.', 500);
    }

    http_response_code($result->getHttpCode());
    echo json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);
} catch (\InvalidArgumentException $e) {
    // Validación de dominio (p. ej. FinancialCalculator::normalizeMoney).
    operam_api_json_error(422, [
        'success' => false,
        'message' => $e->getMessage() !== ''
            ? $e->getMessage()
            : 'Los datos enviados no son válidos.',
        'data' => null,
    ]);
} catch (\Throwable $e) {
    $errorId = bin2hex(random_bytes(6));
    error_log(sprintf(
        '[API %s] %s in %s:%d',
        $errorId,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));
    operam_api_json_error(500, [
        'success' => false,
        'message' => sprintf(
            'Ocurrió un error interno al procesar la solicitud (Ref: %s).',
            $errorId,
        ),
        'error_id' => $errorId,
        'data' => null,
    ]);
}
