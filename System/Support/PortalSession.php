<?php

declare(strict_types=1);

namespace System\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use System\Core\Context;

/**
 * Sesión sin contraseña del Portal del Cliente (RF-7.1 / RNF-7).
 *
 * El JWT del portal es un token HS256 de 8 horas con `scope = 'client_portal'` firmado con la
 * misma `JWT_TOKEN_KEY` del framework. A diferencia del JWT empresarial (claim `jti` con objeto),
 * los claims del portal viajan en la raíz del payload (`client_id`, `contact_id`, …) para que la
 * guardia de scope los distinga sin consultar `active_sessions`.
 */
final class PortalSession
{
    /** Valor del claim `scope` que aísla el portal del panel interno. */
    public const SCOPE = 'client_portal';

    /** Vigencia de la sesión del portal: 8 horas (RNF-7). */
    public const TTL_SECONDS = 28800;

    /** Cookie HttpOnly que respalda la sesión tras un F5 (el JWT en React vive solo en memoria). */
    public const COOKIE_NAME = 'operam_portal_session';

    /** Ruta estricta de enjaulamiento: el navegador jamás envía esta cookie a rutas internas. */
    public const COOKIE_PATH = '/api/portal';

    /**
     * Emite el JWT del portal.
     *
     * @param array{client_id:int, contact_id:int|null, contact_name:string, contact_email:string, contract_id:int|null} $profile
     */
    public static function issueToken(array $profile, ?int $now = null): string
    {
        $key = self::signingKey();
        $issuedAt = $now ?? time();

        return JWT::encode([
            'iss' => getenv('JWT_TOKEN_ISSUER') ?: 'http://localhost:8080/',
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::TTL_SECONDS,
            'scope' => self::SCOPE,
            'client_id' => $profile['client_id'],
            'contact_id' => $profile['contact_id'],
            'contact_name' => $profile['contact_name'],
            'contact_email' => $profile['contact_email'],
            'contract_id' => $profile['contract_id'],
        ], $key, 'HS256');
    }

    /**
     * Decodifica y valida firma/expiración de cualquier JWT del framework.
     *
     * @throws \Throwable Firma inválida, token expirado o malformado.
     */
    public static function decode(string $jwt): object
    {
        return JWT::decode($jwt, new Key(self::signingKey(), 'HS256'));
    }

    /**
     * Scope declarado por un JWT decodificado (raíz para el portal; `jti.scope` para el panel).
     */
    public static function scopeOf(object $decoded): ?string
    {
        if (isset($decoded->scope) && is_string($decoded->scope)) {
            return $decoded->scope;
        }
        $jti = $decoded->jti ?? null;
        if (is_object($jti) && isset($jti->scope) && is_string($jti->scope)) {
            return $jti->scope;
        }

        return null;
    }

    /**
     * Normaliza los claims del portal en un arreglo tipado.
     *
     * @return array{scope:string, client_id:int, contact_id:int|null, contact_name:string, contact_email:string, contract_id:int|null}
     */
    public static function claimsFrom(object $decoded): array
    {
        return [
            'scope' => self::SCOPE,
            'client_id' => (int) ($decoded->client_id ?? 0),
            'contact_id' => isset($decoded->contact_id) && (int) $decoded->contact_id > 0
                ? (int) $decoded->contact_id
                : null,
            'contact_name' => (string) ($decoded->contact_name ?? ''),
            'contact_email' => (string) ($decoded->contact_email ?? ''),
            'contract_id' => isset($decoded->contract_id) && (int) $decoded->contract_id > 0
                ? (int) $decoded->contract_id
                : null,
        ];
    }

    /** Token Bearer de la petición o null. */
    public static function bearerToken(Context $context): ?string
    {
        $header = $context->getHeader('Authorization') ?? '';

        return preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1 ? $matches[1] : null;
    }

    /** Token de la cookie HttpOnly `operam_portal_session` (solo en rutas `/api/portal/*`). */
    public static function cookieToken(Context $context): ?string
    {
        $raw = $context->getHeader('Cookie');
        if ($raw !== null) {
            foreach (explode(';', $raw) as $pair) {
                [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
                if ($name === self::COOKIE_NAME && $value !== '') {
                    return urldecode($value);
                }
            }
        }
        $fromGlobal = $_COOKIE[self::COOKIE_NAME] ?? null;

        return is_string($fromGlobal) && $fromGlobal !== '' ? $fromGlobal : null;
    }

    /** Escribe la cookie `operam_portal_session` (`HttpOnly; SameSite=Lax; Path=/api/portal` + `Secure` bajo HTTPS). */
    public static function setCookie(string $jwt): void
    {
        self::sendCookie($jwt, time() + self::TTL_SECONDS);
    }

    /** Expira únicamente `operam_portal_session` en `Path=/api/portal` (logout del portal). */
    public static function clearCookie(): void
    {
        self::sendCookie('', time() - 3600);
    }

    private static function sendCookie(string $value, int $expires): void
    {
        // En CLI / pruebas ya hay salida enviada: no se emiten cabeceras.
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE_NAME, $value, [
            'expires' => $expires,
            'path' => self::COOKIE_PATH,
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

        return ($https !== '' && $https !== 'off') || $proto === 'https';
    }

    private static function signingKey(): string
    {
        $key = getenv('JWT_TOKEN_KEY') ?: '';
        if ($key === '') {
            throw new RuntimeException('JWT_TOKEN_KEY no configurada.');
        }

        return $key;
    }
}
