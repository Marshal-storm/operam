<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\DataValidator;
use System\Core\Result;
use System\Support\AuditLogger;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\Integrations\ExternalServiceTester;
use System\Support\Integrations\SecretResolver;

/**
 * Configuración → Servicios externos (RNF-8).
 *
 * GET  /api/external-services                     — 5 servicios globales (can_view)
 * GET  /api/external-services/{serviceKey}        — detalle (can_view)
 * PUT  /api/external-services/{serviceKey}        — guardar configuración (can_edit, alcance Todos)
 * POST /api/external-services/{serviceKey}/test   — probar conexión (can_edit, alcance Todos)
 *
 * Nunca expone valores de secretos: solo el nombre de la referencia y si existe en el entorno.
 */
final class ExternalServicesService extends CustomService
{
    private const MODULE_KEY = 'config_external_services';

    /** @var array<string, mixed> */
    private array $authContext = [];

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $parts = explode('/', strtolower(trim($context->getUri(), '/')));
        $serviceKey = $parts[1] ?? null;
        $action = $parts[2] ?? null;

        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
            return Result::fail('No tienes permiso para ver los servicios externos.', 403);
        }

        if ($serviceKey !== null && !ExternalServiceRegistry::isKnownService($serviceKey)) {
            return Result::fail('Servicio externo no reconocido.', 404);
        }

        if ($method === 'GET') {
            if ($serviceKey === null) {
                return Result::ok(['items' => $this->listServices()], 'Servicios externos');
            }
            $item = $this->findPublic($serviceKey);

            return $item !== null
                ? Result::ok($item, 'Servicio externo')
                : Result::fail('Servicio externo no sembrado. Ejecuta ExternalServicesSeeder.', 404);
        }

        if ($serviceKey === null) {
            return Result::fail('Indica el servicio externo en la ruta.', 422);
        }

        $writeGate = $this->assertCanWrite();
        if ($writeGate !== null) {
            return $writeGate;
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            return $this->updateService($serviceKey, $context->getRequest());
        }
        if ($method === 'POST' && $action === 'test') {
            return $this->testService($serviceKey);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * La configuración es global: solo usuarios con alcance "Todos" pueden modificarla.
     */
    private function assertCanWrite(): ?Result
    {
        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
            return Result::fail('No tienes permiso para editar los servicios externos.', 403);
        }
        if (($this->authContext['segment_id'] ?? null) !== null) {
            return Result::fail('Los servicios externos son globales; requieren alcance de segmento "Todos".', 403);
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function listServices(): array
    {
        $rows = Capsule::table('external_service_configs as es')
            ->leftJoin('users as u', 'u.id', '=', 'es.updated_by_user_id')
            ->orderByRaw('COALESCE(es.updated_at, es.created_at) DESC')
            ->orderByDesc('es.id')
            ->get(['es.*', 'u.name as updated_by_name']);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->toPublic($row);
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    private function findPublic(string $serviceKey): ?array
    {
        $row = Capsule::table('external_service_configs as es')
            ->leftJoin('users as u', 'u.id', '=', 'es.updated_by_user_id')
            ->where('es.service_key', $serviceKey)
            ->first(['es.*', 'u.name as updated_by_name']);

        return $row !== null ? $this->toPublic($row) : null;
    }

    /**
     * Proyección segura para el Frontend (sin valores de secretos).
     *
     * @return array<string, mixed>
     */
    private function toPublic(object $row): array
    {
        $mapped = ExternalServiceRegistry::mapRow($row);
        $secretRefs = [];
        foreach (ExternalServiceRegistry::secretFields($mapped['service_key']) as $field) {
            $ref = (string) ($mapped['secret_refs'][$field] ?? '');
            $secretRefs[] = [
                'field' => $field,
                'ref' => $ref,
                'is_configured' => $ref !== '' && SecretResolver::isConfigured($ref),
            ];
        }

        return [
            'id' => $mapped['id'],
            'service_key' => $mapped['service_key'],
            'display_name' => $mapped['display_name'],
            'enabled' => $mapped['enabled'],
            'mode' => $mapped['mode'],
            'config' => $mapped['config'],
            'secret_refs' => $secretRefs,
            'last_tested_at' => $mapped['last_tested_at'],
            'last_test_status' => $mapped['last_test_status'],
            'last_test_message' => $mapped['last_test_message'],
            'updated_by_name' => isset($row->updated_by_name) && $row->updated_by_name !== null
                ? (string) $row->updated_by_name
                : null,
            'created_at' => $mapped['created_at'],
            'updated_at' => $mapped['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function updateService(string $serviceKey, array $request): Result
    {
        $current = ExternalServiceRegistry::find($serviceKey);
        if ($current === null) {
            return Result::fail('Servicio externo no sembrado. Ejecuta ExternalServicesSeeder.', 404);
        }

        $validation = (new DataValidator())
            ->setField('display_name', DataTypes::String, false, $current['display_name'])
            ->setField('mode', DataTypes::String, false, $current['mode'])
            ->startValidation($request);
        if (!$validation['ok']) {
            return Result::fail(implode(' ', $validation['errors']), 422);
        }

        $displayName = trim((string) $validation['data']['display_name']);
        if ($displayName === '' || mb_strlen($displayName) > 100) {
            return Result::fail('El nombre visible es obligatorio (máx. 100 caracteres).', 422);
        }
        $mode = strtolower(trim((string) $validation['data']['mode']));
        if (!in_array($mode, ExternalServiceRegistry::MODES, true)) {
            return Result::fail('mode debe ser sandbox o production.', 422);
        }

        $enabled = $current['enabled'];
        if (array_key_exists('enabled', $request)) {
            $parsed = filter_var($request['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                return Result::fail('enabled debe ser boolean.', 422);
            }
            $enabled = $parsed;
        }

        $configInput = $request['config'] ?? [];
        $refsInput = $request['secret_refs'] ?? [];
        if (!is_array($configInput) || !is_array($refsInput)) {
            return Result::fail('config y secret_refs deben ser objetos JSON.', 422);
        }

        // InvalidArgumentException → HTTP 422 vía manejador global de Api/index.php.
        $config = ExternalServiceRegistry::normalizeConfig($serviceKey, $configInput, $current['config']);
        $secretRefs = ExternalServiceRegistry::normalizeSecretRefs($serviceKey, $refsInput, $current['secret_refs']);

        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = (int) ($this->authContext['user_id'] ?? 0);
        Capsule::table('external_service_configs')->where('id', $current['id'])->update([
            'display_name' => $displayName,
            'enabled' => $enabled ? 1 : 0,
            'mode' => $mode,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_refs_json' => json_encode($secretRefs, JSON_UNESCAPED_UNICODE),
            'updated_by_user_id' => $userId > 0 ? $userId : null,
            'updated_at' => $nowUtc,
        ]);
        ExternalServiceRegistry::clearCache();

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'external_service_configs',
            $serviceKey,
            'update',
            'Se actualizó la configuración del servicio externo ' . $displayName,
            [
                'display_name' => $current['display_name'],
                'enabled' => $current['enabled'],
                'mode' => $current['mode'],
                'config' => $current['config'],
                'secret_refs' => $current['secret_refs'],
            ],
            [
                'display_name' => $displayName,
                'enabled' => $enabled,
                'mode' => $mode,
                'config' => $config,
                'secret_refs' => $secretRefs,
            ],
        );

        return Result::ok($this->findPublic($serviceKey), 'Servicio externo actualizado');
    }

    private function testService(string $serviceKey): Result
    {
        $current = ExternalServiceRegistry::find($serviceKey);
        if ($current === null) {
            return Result::fail('Servicio externo no sembrado. Ejecuta ExternalServicesSeeder.', 404);
        }

        $outcome = (new ExternalServiceTester())->test($serviceKey);
        $nowUtc = gmdate('Y-m-d H:i:s');
        Capsule::table('external_service_configs')->where('id', $current['id'])->update([
            'last_tested_at' => $nowUtc,
            'last_test_status' => $outcome['status'],
            'last_test_message' => $outcome['message'],
            'updated_at' => $nowUtc,
        ]);
        ExternalServiceRegistry::clearCache();

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'external_service_configs',
            $serviceKey,
            'status_change',
            sprintf(
                'Prueba de conexión de %s: %s',
                $current['display_name'],
                $outcome['status'] === 'ok' ? 'correcta' : 'con error',
            ),
            ['last_test_status' => $current['last_test_status']],
            [
                'last_test_status' => $outcome['status'],
                'last_test_message' => $outcome['message'],
                'sandbox' => $outcome['sandbox'],
            ],
        );

        return Result::ok([
            'service' => $this->findPublic($serviceKey),
            'test' => $outcome,
        ], $outcome['status'] === 'ok' ? 'Conexión verificada' : 'La prueba de conexión falló');
    }
}
