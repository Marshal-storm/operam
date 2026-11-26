<?php

declare(strict_types=1);

namespace System\Support\Integrations;

use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;

/**
 * Catálogo y lectura de `external_service_configs` (RNF-8).
 *
 * Define qué parámetros NO sensibles acepta cada servicio y qué referencias de secreto
 * requiere. Toda URL, host o umbral operativo se lee de aquí — nunca de constantes en código.
 */
final class ExternalServiceRegistry
{
    public const SERVICE_KEYS = ['smtp', 'erp_master', 'slack', 'banxico', 'jobs_outbox'];

    public const MODES = ['sandbox', 'production'];

    /**
     * Esquema por servicio: tipo de cada parámetro de `config_json` + llaves de `secret_refs_json`.
     * Tipos: string | int:min:max | url | email | enum:a|b | time | timezone.
     *
     * @var array<string, array{config: array<string, string>, secrets: list<string>}>
     */
    private const DEFINITIONS = [
        'smtp' => [
            'config' => [
                'provider' => 'string',
                'host' => 'string',
                'port' => 'int:1:65535',
                'encryption' => 'enum:tls|ssl|none',
                'username' => 'string',
                'from_email' => 'email',
                'from_name' => 'string',
            ],
            'secrets' => ['password_ref'],
        ],
        'erp_master' => [
            'config' => [
                'base_url' => 'url',
                'timeout_seconds' => 'int:1:120',
            ],
            'secrets' => ['api_key_ref'],
        ],
        'slack' => [
            'config' => [
                'channel_label' => 'string',
                'failure_threshold' => 'int:1:10',
            ],
            'secrets' => ['webhook_url_ref'],
        ],
        'banxico' => [
            'config' => [
                'api_url' => 'url',
                'series_id' => 'string',
            ],
            'secrets' => ['token_ref'],
        ],
        'jobs_outbox' => [
            'config' => [
                'timezone' => 'timezone',
                'max_attempts' => 'int:1:10',
                'retry_backoff_minutes' => 'int:1:1440',
                'daily_billing_hour' => 'time',
                'daily_collections_hour' => 'time',
            ],
            'secrets' => ['job_secret_ref'],
        ],
    ];

    /** @var array<string, array<string, mixed>|null> Caché por petición. */
    private static array $cache = [];

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    public static function isKnownService(string $serviceKey): bool
    {
        return in_array($serviceKey, self::SERVICE_KEYS, true);
    }

    /** @return list<string> */
    public static function secretFields(string $serviceKey): array
    {
        return self::DEFINITIONS[$serviceKey]['secrets'] ?? [];
    }

    /** @return array<string, string> */
    public static function configFields(string $serviceKey): array
    {
        return self::DEFINITIONS[$serviceKey]['config'] ?? [];
    }

    /**
     * Fila decodificada del servicio o null si no está sembrada.
     *
     * @return array{
     *   id: int,
     *   service_key: string,
     *   display_name: string,
     *   enabled: bool,
     *   mode: string,
     *   config: array<string, mixed>,
     *   secret_refs: array<string, string>,
     *   last_tested_at: string|null,
     *   last_test_status: string,
     *   last_test_message: string|null,
     *   updated_by_user_id: int|null,
     *   created_at: string|null,
     *   updated_at: string|null
     * }|null
     */
    public static function find(string $serviceKey): ?array
    {
        if (array_key_exists($serviceKey, self::$cache)) {
            return self::$cache[$serviceKey];
        }

        $row = Capsule::table('external_service_configs')->where('service_key', $serviceKey)->first();
        self::$cache[$serviceKey] = $row !== null ? self::mapRow($row) : null;

        return self::$cache[$serviceKey];
    }

    /**
     * Solo hay tráfico real si el servicio está habilitado y en modo producción.
     */
    public static function isProduction(string $serviceKey): bool
    {
        $service = self::find($serviceKey);

        return $service !== null && $service['enabled'] && $service['mode'] === 'production';
    }

    public static function configValue(string $serviceKey, string $field, mixed $default = null): mixed
    {
        $service = self::find($serviceKey);
        if ($service === null) {
            return $default;
        }
        $value = $service['config'][$field] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public static function secretRef(string $serviceKey, string $field): ?string
    {
        $service = self::find($serviceKey);
        $ref = $service['secret_refs'][$field] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /** Valor real del secreto (uso exclusivo de adaptadores backend). */
    public static function resolveSecret(string $serviceKey, string $field): ?string
    {
        $ref = self::secretRef($serviceKey, $field);

        return $ref !== null ? SecretResolver::resolve($ref) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapRow(object $row): array
    {
        $config = self::decodeJson($row->config_json ?? null);
        $refs = self::decodeJson($row->secret_refs_json ?? null);

        return [
            'id' => (int) $row->id,
            'service_key' => (string) $row->service_key,
            'display_name' => (string) $row->display_name,
            'enabled' => (bool) $row->enabled,
            'mode' => (string) $row->mode,
            'config' => $config,
            'secret_refs' => array_map('strval', $refs),
            'last_tested_at' => $row->last_tested_at !== null ? (string) $row->last_tested_at : null,
            'last_test_status' => (string) ($row->last_test_status ?? 'never'),
            'last_test_message' => $row->last_test_message !== null ? (string) $row->last_test_message : null,
            'updated_by_user_id' => $row->updated_by_user_id !== null ? (int) $row->updated_by_user_id : null,
            'created_at' => $row->created_at !== null ? (string) $row->created_at : null,
            'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
        ];
    }

    /**
     * Valida y normaliza `config_json` contra el esquema del servicio.
     * Rechaza llaves desconocidas para que nadie cuele secretos como "parámetro".
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function normalizeConfig(string $serviceKey, array $input, array $current = []): array
    {
        $schema = self::configFields($serviceKey);
        foreach (array_keys($input) as $key) {
            if (!array_key_exists((string) $key, $schema)) {
                throw new InvalidArgumentException(sprintf('Parámetro no permitido en %s: %s.', $serviceKey, (string) $key));
            }
        }

        $out = [];
        foreach ($schema as $field => $type) {
            $raw = array_key_exists($field, $input) ? $input[$field] : ($current[$field] ?? null);
            $out[$field] = self::normalizeValue($field, $type, $raw);
        }

        return $out;
    }

    /**
     * Valida `secret_refs_json`: solo nombres de variable (MAYÚSCULAS_CON_GUION_BAJO).
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $current
     * @return array<string, string>
     */
    public static function normalizeSecretRefs(string $serviceKey, array $input, array $current = []): array
    {
        $allowed = self::secretFields($serviceKey);
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Referencia no permitida en %s: %s.', $serviceKey, (string) $key));
            }
        }

        $out = [];
        foreach ($allowed as $field) {
            $raw = array_key_exists($field, $input) ? $input[$field] : ($current[$field] ?? '');
            // Sin normalizar mayúsculas: un secreto pegado por error (minúsculas/símbolos) se rechaza.
            $ref = trim((string) $raw);
            if ($ref === '') {
                $out[$field] = '';
                continue;
            }
            if (!SecretResolver::isValidRefName($ref)) {
                throw new InvalidArgumentException(
                    'La referencia ' . $field . ' debe ser un nombre de variable (A-Z, 0-9 y _), nunca el secreto en texto plano.',
                );
            }
            $out[$field] = $ref;
        }

        return $out;
    }

    private static function normalizeValue(string $field, string $type, mixed $raw): mixed
    {
        $value = is_string($raw) ? trim($raw) : $raw;

        if (str_starts_with($type, 'int')) {
            [, $min, $max] = array_pad(explode(':', $type), 3, null);
            $str = trim((string) ($value ?? ''));
            if ($str === '' || preg_match('/^\d+$/', $str) !== 1) {
                throw new InvalidArgumentException(sprintf('%s debe ser un entero.', $field));
            }
            $int = (int) $str;
            if (($min !== null && $int < (int) $min) || ($max !== null && $int > (int) $max)) {
                throw new InvalidArgumentException(sprintf('%s debe estar entre %s y %s.', $field, $min, $max));
            }

            return $int;
        }

        if (str_starts_with($type, 'enum:')) {
            $options = explode('|', substr($type, 5));
            $str = strtolower((string) ($value ?? ''));
            if (!in_array($str, $options, true)) {
                throw new InvalidArgumentException(sprintf('%s debe ser uno de: %s.', $field, implode(', ', $options)));
            }

            return $str;
        }

        $str = mb_substr((string) ($value ?? ''), 0, 500);

        return match ($type) {
            'url' => self::assertUrl($field, $str),
            'email' => self::assertEmail($field, $str),
            'time' => self::assertTime($field, $str),
            'timezone' => self::assertTimezone($field, $str),
            default => $str,
        };
    }

    private static function assertUrl(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(sprintf('%s debe ser una URL http(s) válida.', $field));
        }

        return rtrim($value, '/');
    }

    private static function assertEmail(string $field, string $value): string
    {
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf('%s debe ser un correo válido.', $field));
        }

        return strtolower($value);
    }

    private static function assertTime(string $field, string $value): string
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s debe tener formato HH:MM (24 h).', $field));
        }

        return $value;
    }

    private static function assertTimezone(string $field, string $value): string
    {
        if (!in_array($value, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(sprintf('%s debe ser una zona horaria IANA válida.', $field));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
