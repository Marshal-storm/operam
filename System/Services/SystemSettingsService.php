<?php

declare(strict_types=1);

namespace System\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\Result;
use System\Support\AuditLogger;
use Throwable;

/**
 * Configuración del sistema + depuración controlada de audit_logs.
 * GET/PUT/PATCH /api/system-settings · POST /api/system-settings/purge-audit-logs
 */
final class SystemSettingsService extends CustomService
{
    private const MODULE = 'config_system';
    private const KEY_AUDIT = 'audit_logs_enabled';

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $uri = trim((string) $context->getUri(), '/');
        $auth = $context->getData('auth', []);
        $request = $context->getRequest();

        if (str_ends_with($uri, 'system-settings/purge-audit-logs') && $method === 'POST') {
            return $this->purgeAuditLogs($auth, $request);
        }

        if ($method === 'GET') {
            if (!RbacAuthHelper::can($auth, self::MODULE, 'view')) {
                return Result::fail('No tienes permiso para ver la configuración del sistema.', 403);
            }

            return Result::ok($this->snapshot(), 'Configuración del sistema');
        }

        if (!in_array($method, ['PUT', 'PATCH'], true)) {
            return Result::fail('Método no soportado.', 405);
        }

        if (!RbacAuthHelper::can($auth, self::MODULE, 'edit')) {
            return Result::fail('No tienes permiso para editar la configuración del sistema.', 403);
        }

        if (!array_key_exists('audit_logs_enabled', $request)) {
            return Result::fail('Envía audit_logs_enabled (boolean).', 422);
        }

        $enabled = filter_var($request['audit_logs_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return Result::fail('audit_logs_enabled debe ser boolean.', 422);
        }

        $before = $this->readAuditFlag();
        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = isset($auth['user_id']) ? (int) $auth['user_id'] : null;

        Capsule::table('system_settings')->updateOrInsert(
            ['setting_key' => self::KEY_AUDIT],
            [
                'setting_value' => $enabled ? '1' : '0',
                'value_type' => 'boolean',
                'description' => 'Activa o pausa el registro automático en audit_logs',
                'updated_by' => $userId > 0 ? $userId : null,
                'updated_at' => $nowUtc,
                'created_at' => $nowUtc,
            ],
        );

        AuditLogger::clearEnabledCache();

        AuditLogger::log(
            $auth,
            self::MODULE,
            'system_settings',
            self::KEY_AUDIT,
            'status_change',
            $enabled
                ? 'Se activó el registro automático de auditoría'
                : 'Se pausó el registro automático de auditoría',
            ['audit_logs_enabled' => $before],
            ['audit_logs_enabled' => $enabled],
            true,
        );

        return Result::ok($this->snapshot(), 'Configuración actualizada');
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $request
     */
    private function purgeAuditLogs(array $auth, array $request): Result
    {
        if (!RbacAuthHelper::can($auth, self::MODULE, 'delete')) {
            return Result::fail('No tienes permiso para depurar la bitácora de auditoría.', 403);
        }

        $upToDate = trim((string) ($request['up_to_date'] ?? ''));
        if ($upToDate === '') {
            $upToDate = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))
                ->format('Y-m-d');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $upToDate) !== 1) {
            return Result::fail('up_to_date debe ser YYYY-MM-DD.', 422);
        }

        // Fin del día en CDMX → UTC.
        $cutoffLocal = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $upToDate . ' 23:59:59',
            new DateTimeZone('America/Mexico_City'),
        );
        if ($cutoffLocal === false) {
            return Result::fail('Fecha límite inválida.', 422);
        }
        $cutoffUtc = $cutoffLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        try {
            $deletedCount = 0;
            Capsule::connection()->transaction(function () use ($cutoffUtc, &$deletedCount, $auth, $upToDate): void {
                Capsule::connection()->statement('SET @allow_audit_purge = 1');
                try {
                    $deletedCount = Capsule::table('audit_logs')
                        ->where('created_at', '<=', $cutoffUtc)
                        ->delete();
                } finally {
                    Capsule::connection()->statement('SET @allow_audit_purge = 0');
                }

                // Registro testigo (forzado).
                AuditLogger::log(
                    $auth,
                    self::MODULE,
                    'audit_logs',
                    null,
                    'purge_logs',
                    sprintf(
                        'Depuración de auditoría: %d registro(s) eliminados hasta %s (CDMX)',
                        $deletedCount,
                        $upToDate,
                    ),
                    null,
                    [
                        'deleted_count' => $deletedCount,
                        'up_to_date' => $upToDate,
                        'cutoff_utc' => $cutoffUtc,
                    ],
                    true,
                );
            });

            return Result::ok([
                'deleted_count' => $deletedCount,
                'up_to_date' => $upToDate,
                'cutoff_utc' => $cutoffUtc,
            ], 'Historácora depurada');
        } catch (Throwable $e) {
            try {
                Capsule::connection()->statement('SET @allow_audit_purge = 0');
            } catch (Throwable) {
            }

            return Result::fail('No se pudo depurar la bitácora: ' . $e->getMessage(), 500);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $enabled = $this->readAuditFlag();
        $total = (int) Capsule::table('audit_logs')->count();
        $oldest = Capsule::table('audit_logs')->min('created_at');

        return [
            'audit_logs_enabled' => $enabled,
            'audit_logs_total' => $total,
            'audit_logs_oldest_at' => $oldest !== null ? (string) $oldest : null,
        ];
    }

    private function readAuditFlag(): bool
    {
        $row = Capsule::table('system_settings')
            ->where('setting_key', self::KEY_AUDIT)
            ->first(['setting_value']);
        if ($row === null) {
            return true;
        }

        return in_array(strtolower((string) $row->setting_value), ['1', 'true', 'yes', 'on'], true);
    }
}
