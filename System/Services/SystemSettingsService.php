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
    private const KEY_PORTAL_IDLE_TIMEOUT = 'portal_idle_timeout_minutes';
    private const KEY_PORTAL_LINK_TTL = 'portal_magic_link_ttl_minutes';

    public const DEFAULT_PORTAL_IDLE_TIMEOUT_MINUTES = 5;
    public const MIN_PORTAL_IDLE_TIMEOUT_MINUTES = 1;
    public const MAX_PORTAL_IDLE_TIMEOUT_MINUTES = 240;

    public const DEFAULT_PORTAL_LINK_TTL_MINUTES = 15;
    public const MIN_PORTAL_LINK_TTL_MINUTES = 1;
    public const MAX_PORTAL_LINK_TTL_MINUTES = 1440;

    /**
     * Lee los tiempos dinámicos del Portal Cliente desde `system_settings` (acotados y con fallback).
     *
     * @return array{portal_idle_timeout_minutes: int, portal_magic_link_ttl_minutes: int}
     */
    public static function getPortalTimeouts(): array
    {
        $idle = self::DEFAULT_PORTAL_IDLE_TIMEOUT_MINUTES;
        $ttl = self::DEFAULT_PORTAL_LINK_TTL_MINUTES;

        try {
            $rows = Capsule::table('system_settings')
                ->whereIn('setting_key', [self::KEY_PORTAL_IDLE_TIMEOUT, self::KEY_PORTAL_LINK_TTL])
                ->pluck('setting_value', 'setting_key')
                ->all();

            if (isset($rows[self::KEY_PORTAL_IDLE_TIMEOUT])) {
                $parsed = filter_var($rows[self::KEY_PORTAL_IDLE_TIMEOUT], FILTER_VALIDATE_INT);
                if ($parsed !== false) {
                    $idle = max(
                        self::MIN_PORTAL_IDLE_TIMEOUT_MINUTES,
                        min(self::MAX_PORTAL_IDLE_TIMEOUT_MINUTES, (int) $parsed),
                    );
                }
            }
            if (isset($rows[self::KEY_PORTAL_LINK_TTL])) {
                $parsed = filter_var($rows[self::KEY_PORTAL_LINK_TTL], FILTER_VALIDATE_INT);
                if ($parsed !== false) {
                    $ttl = max(
                        self::MIN_PORTAL_LINK_TTL_MINUTES,
                        min(self::MAX_PORTAL_LINK_TTL_MINUTES, (int) $parsed),
                    );
                }
            }
        } catch (Throwable) {
            // Fallback seguro a valores por defecto si la tabla no está disponible.
        }

        return [
            'portal_idle_timeout_minutes' => $idle,
            'portal_magic_link_ttl_minutes' => $ttl,
        ];
    }

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

        $hasAudit = array_key_exists('audit_logs_enabled', $request);
        $hasIdle = array_key_exists('portal_idle_timeout_minutes', $request);
        $hasLinkTtl = array_key_exists('portal_magic_link_ttl_minutes', $request);

        if (!$hasAudit && !$hasIdle && !$hasLinkTtl) {
            return Result::fail(
                'Envía al menos un parámetro de configuración válido (audit_logs_enabled, portal_idle_timeout_minutes o portal_magic_link_ttl_minutes).',
                422,
            );
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = isset($auth['user_id']) ? (int) $auth['user_id'] : null;
        $updatedBy = ($userId !== null && $userId > 0) ? $userId : null;

        $beforeAudit = $this->readAuditFlag();
        $beforeTimeouts = self::getPortalTimeouts();
        $oldValues = [];
        $newValues = [];
        $descriptions = [];

        if ($hasAudit) {
            $enabled = filter_var($request['audit_logs_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) {
                return Result::fail('audit_logs_enabled debe ser boolean.', 422);
            }
            $oldValues['audit_logs_enabled'] = $beforeAudit;
            $newValues['audit_logs_enabled'] = $enabled;
            $descriptions[] = $enabled
                ? 'Se activó el registro automático de auditoría'
                : 'Se pausó el registro automático de auditoría';
        }

        $nextIdle = null;
        if ($hasIdle) {
            $parsedIdle = filter_var($request['portal_idle_timeout_minutes'], FILTER_VALIDATE_INT);
            if (
                $parsedIdle === false
                || (int) $parsedIdle < self::MIN_PORTAL_IDLE_TIMEOUT_MINUTES
                || (int) $parsedIdle > self::MAX_PORTAL_IDLE_TIMEOUT_MINUTES
            ) {
                return Result::fail(
                    sprintf(
                        'portal_idle_timeout_minutes debe ser un entero entre %d y %d.',
                        self::MIN_PORTAL_IDLE_TIMEOUT_MINUTES,
                        self::MAX_PORTAL_IDLE_TIMEOUT_MINUTES,
                    ),
                    422,
                );
            }
            $nextIdle = (int) $parsedIdle;
            $oldValues['portal_idle_timeout_minutes'] = $beforeTimeouts['portal_idle_timeout_minutes'];
            $newValues['portal_idle_timeout_minutes'] = $nextIdle;
            $descriptions[] = sprintf('Timeout de inactividad del portal: %d min', $nextIdle);
        }

        $nextLinkTtl = null;
        if ($hasLinkTtl) {
            $parsedTtl = filter_var($request['portal_magic_link_ttl_minutes'], FILTER_VALIDATE_INT);
            if (
                $parsedTtl === false
                || (int) $parsedTtl < self::MIN_PORTAL_LINK_TTL_MINUTES
                || (int) $parsedTtl > self::MAX_PORTAL_LINK_TTL_MINUTES
            ) {
                return Result::fail(
                    sprintf(
                        'portal_magic_link_ttl_minutes debe ser un entero entre %d y %d.',
                        self::MIN_PORTAL_LINK_TTL_MINUTES,
                        self::MAX_PORTAL_LINK_TTL_MINUTES,
                    ),
                    422,
                );
            }
            $nextLinkTtl = (int) $parsedTtl;
            $oldValues['portal_magic_link_ttl_minutes'] = $beforeTimeouts['portal_magic_link_ttl_minutes'];
            $newValues['portal_magic_link_ttl_minutes'] = $nextLinkTtl;
            $descriptions[] = sprintf('Vigencia de liga mágica: %d min', $nextLinkTtl);
        }

        if ($hasAudit) {
            Capsule::table('system_settings')->updateOrInsert(
                ['setting_key' => self::KEY_AUDIT],
                [
                    'setting_value' => $newValues['audit_logs_enabled'] ? '1' : '0',
                    'value_type' => 'boolean',
                    'description' => 'Activa o pausa el registro automático en audit_logs',
                    'updated_by' => $updatedBy,
                    'updated_at' => $nowUtc,
                ],
            );
            AuditLogger::clearEnabledCache();
        }

        if ($nextIdle !== null) {
            Capsule::table('system_settings')->updateOrInsert(
                ['setting_key' => self::KEY_PORTAL_IDLE_TIMEOUT],
                [
                    'setting_value' => (string) $nextIdle,
                    'value_type' => 'integer',
                    'description' => 'Minutos de inactividad antes de expirar la sesión del Portal Cliente',
                    'updated_by' => $updatedBy,
                    'updated_at' => $nowUtc,
                ],
            );
        }

        if ($nextLinkTtl !== null) {
            Capsule::table('system_settings')->updateOrInsert(
                ['setting_key' => self::KEY_PORTAL_LINK_TTL],
                [
                    'setting_value' => (string) $nextLinkTtl,
                    'value_type' => 'integer',
                    'description' => 'Minutos de vigencia de una liga mágica del Portal Cliente antes de usarse',
                    'updated_by' => $updatedBy,
                    'updated_at' => $nowUtc,
                ],
            );
        }

        $onlyAuditToggle = $hasAudit && !$hasIdle && !$hasLinkTtl;
        AuditLogger::log(
            $auth,
            self::MODULE,
            'system_settings',
            $onlyAuditToggle ? self::KEY_AUDIT : 'portal_timeouts',
            $onlyAuditToggle ? 'status_change' : 'update',
            implode(' · ', $descriptions),
            $oldValues,
            $newValues,
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
        $timeouts = self::getPortalTimeouts();

        return [
            'audit_logs_enabled' => $enabled,
            'audit_logs_total' => $total,
            'audit_logs_oldest_at' => $oldest !== null ? (string) $oldest : null,
            'portal_idle_timeout_minutes' => $timeouts['portal_idle_timeout_minutes'],
            'portal_magic_link_ttl_minutes' => $timeouts['portal_magic_link_ttl_minutes'],
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
