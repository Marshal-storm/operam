<?php

declare(strict_types=1);

/**
 * Entrada API del framework operam/servicios.
 * Solo `login` es público; el resto exige JWT enterprise (AppService::before_get).
 */

use System\Core\Context;
use System\Core\Result;
use System\Services\AppService;
use System\Services\LoginService;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/backend/bootstrap/eloquent.php';

operam_boot_eloquent();

header('Content-Type: application/json; charset=utf-8');

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
        // Rutas protegidas de negocio (Dashboard / movimientos) se despachan aquí.
        // Mientras no exista el handler de dominio, respondemos 200 autenticado.
        $result = Result::ok([
            'uri' => $uri,
            'auth' => $context->getData('auth'),
        ], 'Autenticado');
    }
}

if (!$result instanceof Result) {
    $result = Result::fail('Sin resultado de servicio.', 500);
}

http_response_code($result->getHttpCode());
echo json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);
