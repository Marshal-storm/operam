<?php

declare(strict_types=1);

namespace System\Support\Integrations;

/**
 * Resuelve referencias del cofre de secretos (RNF-8).
 *
 * MySQL solo guarda el NOMBRE de la variable (p. ej. `SMTP_PASSWORD_REF`); el valor real vive en
 * `.env` local o en variables/volúmenes montados por Cloud Run + Secret Manager.
 * Orden de lectura: `$_ENV` → `getenv()` → `$_SERVER` → archivo indicado en `{REF}_FILE`.
 *
 * El valor resuelto jamás se registra en logs ni se devuelve al Frontend; la UI solo consume
 * `isConfigured()` para mostrar "Referencia configurada" / "Pendiente en .env".
 */
final class SecretResolver
{
    /** Formato permitido de nombres de referencia: MAYÚSCULAS, dígitos y guion bajo. */
    private const REF_PATTERN = '/^[A-Z][A-Z0-9_]{2,99}$/';

    public static function isValidRefName(string $refName): bool
    {
        return preg_match(self::REF_PATTERN, $refName) === 1;
    }

    public static function isConfigured(string $refName): bool
    {
        $value = self::resolve($refName);

        return $value !== null && $value !== '';
    }

    /** Alias semántico de `resolve()` para validaciones de autenticación (p. ej. `GCP_JOB_SECRET_REF`). */
    public static function get(string $refName): ?string
    {
        return self::resolve($refName);
    }

    /**
     * Uso exclusivo de adaptadores backend. Nunca serializar el resultado en respuestas HTTP.
     */
    public static function resolve(string $refName): ?string
    {
        $refName = trim($refName);
        if (!self::isValidRefName($refName)) {
            return null;
        }

        foreach ([$_ENV[$refName] ?? null, getenv($refName), $_SERVER[$refName] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        // Secret Manager montado como archivo (Cloud Run: volumen + variable `{REF}_FILE`).
        $filePath = getenv($refName . '_FILE');
        if (is_string($filePath) && $filePath !== '' && is_file($filePath) && is_readable($filePath)) {
            $contents = file_get_contents($filePath);
            if (is_string($contents) && trim($contents) !== '') {
                return trim($contents);
            }
        }

        return null;
    }
}
