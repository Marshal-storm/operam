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
 * AppService: protege rutas en before_get con JWT HS256 + active_sessions.
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
            ->where('is_revoked', false)
            ->where('expires_at', '>', $nowUtc)
            ->first();

        if (!$session) {
            $context->setResult(Result::fail('Sesión revocada o expirada.', 401));

            return;
        }

        /** @var array<string, array{view: bool, create: bool, edit: bool, delete: bool}> $permissions */
        $permissions = [];
        $rawPermissions = $jti->permissions ?? null;
        if (is_object($rawPermissions) || is_array($rawPermissions)) {
            foreach ((array) $rawPermissions as $moduleKey => $flags) {
                $flagsArr = is_object($flags) ? (array) $flags : (array) $flags;
                $permissions[(string) $moduleKey] = [
                    'view' => (bool) ($flagsArr['view'] ?? false),
                    'create' => (bool) ($flagsArr['create'] ?? false),
                    'edit' => (bool) ($flagsArr['edit'] ?? false),
                    'delete' => (bool) ($flagsArr['delete'] ?? false),
                ];
            }
        }

        $segmentId = isset($jti->segment_id) && $jti->segment_id !== null
            ? (int) $jti->segment_id
            : null;

        $context->setData('auth', [
            'session_uid' => $sessionUid,
            'user_id' => (int) ($jti->user_id ?? 0),
            'email' => (string) ($jti->email ?? ''),
            'name' => (string) ($jti->name ?? ''),
            'role_id' => isset($jti->role_id) && $jti->role_id !== null ? (int) $jti->role_id : null,
            'role_name' => isset($jti->role_name) ? (string) $jti->role_name : null,
            'role_code' => isset($jti->role_code) ? (string) $jti->role_code : null,
            'segment_id' => $segmentId,
            'segment_name' => (string) ($jti->segment_name ?? 'Todos'),
            'permissions' => $permissions,
            'can_authorize_exceptions' => (bool) ($jti->can_authorize_exceptions ?? false),
            'scope' => 'enterprise',
        ]);
    }
}
