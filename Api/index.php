<?php

declare(strict_types=1);

/**
 * Entrada API del framework operam/servicios.
 * Solo `login` es público; el resto exige JWT enterprise (AppService::before_get).
 */

use System\Core\Context;
use System\Core\Result;
use System\Models\UsersModel;
use System\Services\AppService;
use System\Services\AuditLogsService;
use System\Services\ClientsService;
use System\Services\ContractsService;
use System\Services\DashboardMetricsService;
use System\Services\InstancesService;
use System\Services\IssuersService;
use System\Services\LoginService;
use System\Services\MovementsService;
use System\Services\PriceListItemsService;
use System\Services\PriceListsService;
use System\Services\PromotionsService;
use System\Services\RbacAuthHelper;
use System\Services\RolesService;
use System\Services\SegmentsService;
use System\Services\ServiceRequestsService;
use System\Services\ServicesCatalogService;
use System\Services\SystemSettingsService;
use System\Services\UsersManagementService;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/backend/bootstrap/env.php';
require_once dirname(__DIR__) . '/backend/bootstrap/eloquent.php';

operam_load_env();
operam_boot_eloquent();

// CORS local (Vite → API). Preflight OPTIONS sin body.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** Única URI libre del acceso interno empresarial. */
$freeuris = ['login'];

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

if ($uri === 'login' && $method === 'POST') {
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
            'emisores', 'emitters', 'catalog/issuers' => 'issuers',
            'clientes', 'catalog/clients' => 'clients',
            'contratos', 'catalog/contracts' => 'contracts',
            'solicitudes', 'service-requests', 'service_requests' => 'requests',
            'movimientos', 'catalog/movements' => 'movements',
            'dashboard/mrr', 'mrr' => 'dashboard',
            'roles-permissions', 'rbac/roles' => 'roles',
            'users-management', 'usuarios' => 'users',
            'session', 'profile' => 'me',
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
            'issuers' => (new IssuersService())->handle($context),
            'clients' => (new ClientsService())->handle($context),
            'contracts' => (new ContractsService())->handle($context),
            'requests' => (new ServiceRequestsService())->handle($context),
            'movements' => (new MovementsService())->handle($context),
            'dashboard' => (new DashboardMetricsService())->handle($context),
            'segments' => (new SegmentsService())->handle($context),
            'system-settings' => (new SystemSettingsService())->handle($context),
            'audit-logs' => (new AuditLogsService())->handle($context),
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
