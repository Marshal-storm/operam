<?php

declare(strict_types=1);

namespace System\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Throwable;

/**
 * Auditoría global — única puerta de entrada a `audit_logs`.
 * Respeta `system_settings.audit_logs_enabled` salvo fuerza (módulo Sistema / purge).
 */
final class AuditLogger
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'secret_ref',
        'token',
        'integropay_merchant_token_ref',
        'integropay_device_token_ref',
    ];

    private static ?bool $enabledCache = null;

    /**
     * Determina si el evento proviene del Portal del Cliente (`client_portal` o `/api/portal*`).
     * El tráfico de clientes finales (+1,000 usuarios/día) queda excluido de `audit_logs`.
     *
     * @param array<string, mixed> $auth
     */
    public static function shouldSkipPortalContext(array $auth, ?string $requestUri = null): bool
    {
        $scope = strtolower(trim((string) ($auth['scope'] ?? '')));
        if ($scope === 'client_portal') {
            return true;
        }

        $uri = $requestUri ?? (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = trim((string) (parse_url($uri, PHP_URL_PATH) ?? $uri));
        if ($path !== '' && preg_match('#^/?api/portal(?:/|$)#i', $path) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $auth Contexto JWT / sesión
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public static function log(
        array $auth,
        string $moduleKey,
        string $entityType,
        ?string $entityId,
        string $action,
        string $description,
        ?array $oldValues = null,
        ?array $newValues = null,
        bool $force = false,
    ): void {
        // Optimización de alto tráfico: `audit_logs` registra únicamente acciones del Panel Interno.
        if (self::shouldSkipPortalContext($auth)) {
            return;
        }

        $moduleKey = trim($moduleKey);
        $isSystemModule = $moduleKey === 'config_system' || $entityType === 'system_settings';
        $alwaysForce = $force || $isSystemModule || $action === 'purge_logs';

        if (!$alwaysForce && !self::isEnabled()) {
            return;
        }

        try {
            $oldSan = $oldValues !== null ? self::sanitize($oldValues) : null;
            $newSan = $newValues !== null ? self::sanitize($newValues) : null;

            if ($oldSan !== null && $newSan !== null && in_array($action, ['update', 'patch', 'status_change'], true)) {
                $diff = self::diff($oldSan, $newSan);
                $oldSan = $diff['old'];
                $newSan = $diff['new'];
                if ($oldSan === [] && $newSan === []) {
                    // Sin cambios reales tras sanitizar: no ensucia la bitácora.
                    if (!$alwaysForce) {
                        return;
                    }
                }
            }

            Capsule::table('audit_logs')->insert([
                'user_id' => isset($auth['user_id']) && (int) $auth['user_id'] > 0
                    ? (int) $auth['user_id']
                    : null,
                'user_name_snapshot' => self::nullableString($auth['name'] ?? $auth['user_name'] ?? null, 120),
                'user_email_snapshot' => self::nullableString($auth['email'] ?? null, 150),
                'segment_id' => isset($auth['segment_id']) && $auth['segment_id'] !== null && $auth['segment_id'] !== ''
                    ? (int) $auth['segment_id']
                    : null,
                'module_key' => mb_substr($moduleKey !== '' ? $moduleKey : 'unknown', 0, 50),
                'entity_type' => mb_substr($entityType !== '' ? $entityType : 'unknown', 0, 60),
                'entity_id' => $entityId !== null && $entityId !== ''
                    ? mb_substr((string) $entityId, 0, 50)
                    : null,
                'action' => $action,
                'description' => mb_substr(trim($description) !== '' ? trim($description) : 'Evento de auditoría', 0, 255),
                'old_values' => $oldSan !== null ? json_encode($oldSan, JSON_UNESCAPED_UNICODE) : null,
                'new_values' => $newSan !== null ? json_encode($newSan, JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => self::clientIp(),
                'user_agent' => self::clientUserAgent(),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // La auditoría nunca debe romper la operación de negocio.
        }
    }

    /** Invalida la caché de bandera (tras cambiar system_settings). */
    public static function clearEnabledCache(): void
    {
        self::$enabledCache = null;
    }

    public static function isEnabled(): bool
    {
        if (self::$enabledCache !== null) {
            return self::$enabledCache;
        }

        try {
            $row = Capsule::table('system_settings')
                ->where('setting_key', 'audit_logs_enabled')
                ->first(['setting_value', 'value_type']);
            if ($row === null) {
                self::$enabledCache = true;

                return true;
            }
            $value = (string) $row->setting_value;
            self::$enabledCache = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);

            return self::$enabledCache;
        } catch (Throwable) {
            self::$enabledCache = true;

            return true;
        }
    }

    /**
     * Redacta secretos en profundidad.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            if (self::isSensitiveKey($keyStr)) {
                $out[$keyStr] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$keyStr] = self::sanitize($value);
                continue;
            }
            $out[$keyStr] = $value;
        }

        return $out;
    }

    /**
     * Diff de campos modificados (solo claves cambiadas).
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function diff(array $old, array $new): array
    {
        $oldOut = [];
        $newOut = [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        foreach ($keys as $key) {
            $o = $old[$key] ?? null;
            $n = $new[$key] ?? null;
            if (self::valuesEqual($o, $n)) {
                continue;
            }
            $oldOut[$key] = $o;
            $newOut[$key] = $n;
        }

        return ['old' => $oldOut, 'new' => $newOut];
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($lower === $sensitive || str_contains($lower, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        return (string) $a === (string) $b;
    }

    private static function clientIp(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $ip = is_string($forwarded) && $forwarded !== ''
            ? trim(explode(',', $forwarded, 2)[0])
            : (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return mb_substr($ip !== '' ? $ip : '0.0.0.0', 0, 45);
    }

    private static function clientUserAgent(): ?string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            return null;
        }

        return mb_substr($ua, 0, 255);
    }

    private static function nullableString(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);

        return $str === '' ? null : mb_substr($str, 0, $limit);
    }
}
