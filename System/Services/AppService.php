<?php

declare(strict_types=1);

namespace System\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use System\Core\Context;
use System\Core\Result;
use System\Models\ActiveSessionsModel;
use Throwable;

/**
 * AppService: protege rutas en before_get con JWT HS256 + sesion_activa.
 */
final class AppService extends CustomService
{
    /** @param list<string> $freeUris */
    public function __construct(
        private array $freeUris,
        array $hooks = [],
    ) {
        parent::__construct($hooks);
    }

    public function handle(Context $context): Result
    {
        $this->runHook('before_get', $context);
        if ($context->hasResult()) {
            return $context->getResult();
        }

        return Result::ok(['uri' => $context->getUri()], 'OK');
    }

    /**
     * Hook recomendado para Api/index.php → $appHooks['before_get'].
     *
     * @param list<string> $freeUris
     */
    public static function beforeGetHook(Context $context, array $freeUris): void
    {
        $uri = strtolower(trim($context->getUri(), '/'));
        $base = explode('/', $uri)[0] ?? '';

        if (in_array($base, $freeUris, true) || in_array($uri, $freeUris, true)) {
            return;
        }

        $auth = $context->getHeader('Authorization') ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)) {
            $context->setResult(Result::fail('Token Bearer requerido.', 401));

            return;
        }

        $key = getenv('JWT_TOKEN_KEY') ?: '';
        if ($key === '') {
            $context->setResult(Result::fail('JWT_TOKEN_KEY no configurada.', 500));

            return;
        }

        try {
            $decoded = JWT::decode($matches[1], new Key($key, 'HS256'));
        } catch (Throwable $exception) {
            $context->setResult(Result::fail('Token inválido o expirado.', 401));

            return;
        }

        $jti = $decoded->jti ?? null;
        if (!is_object($jti) || ($jti->scope ?? null) !== 'enterprise') {
            $context->setResult(Result::fail('Alcance de token no autorizado.', 401));

            return;
        }

        $sessionUid = (string) ($jti->session_uid ?? '');
        if ($sessionUid === '') {
            $context->setResult(Result::fail('Sesión JWT incompleta.', 401));

            return;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $session = ActiveSessionsModel::query()
            ->where('session_uid', $sessionUid)
            ->where('revocada', false)
            ->where('expira_en', '>', $nowUtc)
            ->first();

        if (!$session) {
            $context->setResult(Result::fail('Sesión revocada o expirada.', 401));

            return;
        }

        /** @var array<string, array{ver: bool, crear: bool, editar: bool, eliminar: bool}> $permisos */
        $permisos = [];
        if (isset($jti->permisos) && is_object($jti->permisos)) {
            foreach ((array) $jti->permisos as $clave => $flags) {
                $flagsArr = is_object($flags) ? (array) $flags : (array) $flags;
                $permisos[(string) $clave] = [
                    'ver' => (bool) ($flagsArr['ver'] ?? false),
                    'crear' => (bool) ($flagsArr['crear'] ?? false),
                    'editar' => (bool) ($flagsArr['editar'] ?? false),
                    'eliminar' => (bool) ($flagsArr['eliminar'] ?? false),
                ];
            }
        } elseif (isset($jti->permisos) && is_array($jti->permisos)) {
            foreach ($jti->permisos as $clave => $flags) {
                $flagsArr = is_object($flags) ? (array) $flags : (array) $flags;
                $permisos[(string) $clave] = [
                    'ver' => (bool) ($flagsArr['ver'] ?? false),
                    'crear' => (bool) ($flagsArr['crear'] ?? false),
                    'editar' => (bool) ($flagsArr['editar'] ?? false),
                    'eliminar' => (bool) ($flagsArr['eliminar'] ?? false),
                ];
            }
        }

        $segmentoId = isset($jti->segmento_id) && $jti->segmento_id !== null
            ? (int) $jti->segmento_id
            : null;

        $context->setData('auth', [
            'session_uid' => $sessionUid,
            'user_id' => (int) ($jti->user_id ?? 0),
            'email' => (string) ($jti->email ?? ''),
            'nombre' => (string) ($jti->nombre ?? ''),
            'rol' => (string) ($jti->rol ?? ''),
            'rol_id' => isset($jti->rol_id) && $jti->rol_id !== null ? (int) $jti->rol_id : null,
            'rol_nombre' => isset($jti->rol_nombre) ? (string) $jti->rol_nombre : null,
            'rol_codigo' => isset($jti->rol_codigo) ? (string) $jti->rol_codigo : null,
            'segmento_id' => $segmentoId,
            'segmento_nombre' => (string) ($jti->segmento_nombre ?? 'Todos'),
            'permisos' => $permisos,
            'scope' => 'enterprise',
        ]);
    }
}
