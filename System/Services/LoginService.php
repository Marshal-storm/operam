<?php

declare(strict_types=1);

namespace System\Services;

use Firebase\JWT\JWT;
use System\Core\Context;
use System\Core\Result;
use System\Models\ActiveSessionsModel;
use System\Models\AuthAttemptModel;
use System\Models\UsersModel;

/**
 * Login empresarial: rate-limit, password_verify, sesion_activa y JWT HS256.
 */
final class LoginService extends CustomService
{
    private const MAX_FAILURES = 5;
    private const WINDOW_MINUTES = 15;
    private const DEFAULT_TOKEN_TTL_SECONDS = 3600;

    public function handle(Context $context): Result
    {
        $this->runHook('before_create', $context);
        if ($context->hasResult()) {
            return $context->getResult();
        }

        if (!$context->getData('login')) {
            return Result::fail('Login no autorizado por hooks.', 401);
        }

        /** @var array<string, mixed> $jti */
        $jti = $context->getData('jti', []);
        $key = getenv('JWT_TOKEN_KEY') ?: '';
        if ($key === '') {
            return Result::fail('JWT_TOKEN_KEY no configurada.', 500);
        }

        $issuer = getenv('JWT_TOKEN_ISSUER') ?: 'http://localhost:8080/';
        $ttlRaw = getenv('DEF_EXPIRATION_TIME');
        $ttl = is_numeric($ttlRaw) ? (int) $ttlRaw : self::DEFAULT_TOKEN_TTL_SECONDS;
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_TOKEN_TTL_SECONDS;
        }

        $now = time();
        $payload = [
            'iss' => $issuer,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => $jti,
        ];

        $token = JWT::encode($payload, $key, 'HS256');

        return Result::ok([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'user' => [
                'id' => $jti['user_id'] ?? null,
                'email' => $jti['email'] ?? null,
                'nombre' => $jti['nombre'] ?? null,
                'rol' => $jti['rol'] ?? null,
                'scope' => $jti['scope'] ?? 'enterprise',
            ],
        ], 'Sesión iniciada');
    }

    /**
     * Hook recomendado para Api/index.php → $loginHooks['before_create'].
     */
    public static function beforeCreateHook(Context $context): void
    {
        $request = $context->getRequest();
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $password = (string) ($request['password'] ?? '');
        // getHeader() debe devolver null (no "") para que el fallback ?? funcione.
        $forwardedFor = $context->getHeader('X-Forwarded-For');
        $ip = (string) ($forwardedFor
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0');
        // X-Forwarded-For puede traer lista "client, proxy"; se toma el primero.
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip, 2)[0]);
        }
        if ($ip === '') {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        }
        $userAgent = substr((string) ($context->getHeader('User-Agent') ?? ''), 0, 255);
        $nowUtc = gmdate('Y-m-d H:i:s');

        if ($email === '' || $password === '') {
            $context->setResult(Result::fail('Correo y contraseña son obligatorios.', 422));

            return;
        }

        $windowStart = gmdate('Y-m-d H:i:s', time() - (self::WINDOW_MINUTES * 60));
        $failures = AuthAttemptModel::query()
            ->where('email', $email)
            ->where('exitoso', false)
            ->where('created_at', '>=', $windowStart)
            ->count();

        if ($failures >= self::MAX_FAILURES) {
            $context->setResult(Result::fail(
                'Demasiados intentos fallidos. Intenta de nuevo en 15 minutos.',
                429
            ));

            return;
        }

        /** @var UsersModel|null $user */
        $user = UsersModel::query()
            ->where('email', $email)
            ->where('activo', true)
            ->first();

        if (!$user || !password_verify($password, (string) $user->password_hash)) {
            AuthAttemptModel::query()->create([
                'email' => $email,
                'ip_address' => $ip,
                'exitoso' => false,
                'created_at' => $nowUtc,
            ]);
            $context->setResult(Result::fail('Credenciales inválidas.', 401));

            return;
        }

        AuthAttemptModel::query()->create([
            'email' => $email,
            'ip_address' => $ip,
            'exitoso' => true,
            'created_at' => $nowUtc,
        ]);

        $sessionUid = self::uuidV4();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);

        ActiveSessionsModel::query()->create([
            'session_uid' => $sessionUid,
            'usuario_id' => (int) $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'expira_en' => $expiresAt,
            'revocada' => false,
            'created_at' => $nowUtc,
        ]);

        $user->ultimo_acceso = $nowUtc;
        $user->save();

        $context->setData('login', true);
        $context->setData('jti', [
            'session_uid' => $sessionUid,
            'user_id' => (int) $user->id,
            'email' => (string) $user->email,
            'nombre' => (string) $user->nombre,
            'rol' => (string) $user->rol,
            'scope' => 'enterprise',
        ]);
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
