<?php

declare(strict_types=1);

namespace System\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\Result;
use System\Models\ActiveSessionsModel;
use System\Support\PortalSession;
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
        $method = $context->getMethod();
        $base = explode('/', $uri)[0] ?? '';

        // Rutas libres: por base (`login`), por URI exacta o por `MÉTODO uri` (portal/auth/*).
        if (
            in_array($base, $freeUris, true)
            || in_array($uri, $freeUris, true)
            || in_array($method . ' ' . $uri, $freeUris, true)
        ) {
            return;
        }

        $isPortalRoute = $uri === 'portal' || str_starts_with($uri, 'portal/');

        // Muro de transporte: `/api/portal/*` acepta Bearer del portal o cookie `operam_portal_session`;
        // las rutas internas `/api/*` ignoran por completo `operam_portal_session`.
        $token = PortalSession::bearerToken($context);
        if ($token === null && $isPortalRoute) {
            $token = PortalSession::cookieToken($context);
        }
        if ($token === null) {
            $context->setResult(Result::fail('Token Bearer requerido.', 401));

            return;
        }

        $key = getenv('JWT_TOKEN_KEY') ?: '';
        if ($key === '') {
            $context->setResult(Result::fail('JWT_TOKEN_KEY no configurada.', 500));

            return;
        }

        try {
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
        } catch (Throwable $exception) {
            $context->setResult(Result::fail('Token inválido o expirado.', 401));

            return;
        }

        $scope = PortalSession::scopeOf($decoded);

        // ── Muro bidireccional de `scope` (RNF-7): aislamiento total Portal ↔ Panel Interno ───
        if ($isPortalRoute) {
            if ($scope !== PortalSession::SCOPE) {
                $context->setResult(Result::fail(
                    'Las rutas del portal requieren una sesión de cliente.',
                    403,
                ));

                return;
            }

            self::authorizePortalToken($context, $decoded, $token, true);

            return;
        }

        if ($scope === PortalSession::SCOPE) {
            self::authorizePortalToken($context, $decoded, $token, false);

            return;
        }

        $jti = $decoded->jti ?? null;
        if (!is_object($jti) || $scope !== 'enterprise') {
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

    /**
     * Autoriza un JWT `client_portal`: bloquea cualquier ruta fuera de `/api/portal/*` con 403,
     * valida la sesión deslizante en `portal_sessions`, revalida contacto/cliente y renueva
     * `last_activity_at` + `expires_at` + cookie en cada petición autenticada.
     */
    private static function authorizePortalToken(
        Context $context,
        object $decoded,
        string $token,
        bool $isPortalRoute,
    ): void {
        if (!$isPortalRoute) {
            $context->setResult(Result::fail(
                'Tu sesión del portal no tiene acceso a este recurso.',
                403,
            ));

            return;
        }

        $claims = PortalSession::claimsFrom($decoded);
        if ($claims['client_id'] < 1) {
            $context->setResult(Result::fail('Sesión del portal incompleta.', 401));

            return;
        }

        $uri = strtolower(trim($context->getUri(), '/'));
        // El cierre de sesión siempre puede revocar su `sid` y limpiar la cookie.
        if ($uri === 'portal/auth/logout' && $context->getMethod() === 'POST') {
            $context->setData('portal_auth', $claims);
            $context->setData('portal_token', $token);

            return;
        }

        $sid = $claims['sid'];
        $nowTs = time();
        $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);
        $sessionHash = $sid !== '' ? PortalSession::hashSessionId($sid) : '';

        $session = $sessionHash !== ''
            ? Capsule::table('portal_sessions')
                ->where('session_token_hash', $sessionHash)
                ->first()
            : null;

        if (
            $session === null
            || $session->revoked_at !== null
            || (string) $session->expires_at <= $nowUtc
        ) {
            PortalSession::clearCookie();
            $context->setResult(
                Result::fail(
                    'Tu sesión expiró por inactividad. Solicita o abre una nueva liga de acceso.',
                    401,
                )->withCode('PORTAL_SESSION_EXPIRED'),
            );

            return;
        }

        // Revocación inmediata: si el contacto se desactiva o pierde el permiso, cae la sesión.
        $clientActive = Capsule::table('clients')
            ->where('id', $claims['client_id'])
            ->where('active', 1)
            ->exists();
        $contactActive = $claims['contact_id'] === null || Capsule::table('client_contacts')
            ->where('id', $claims['contact_id'])
            ->where('client_id', $claims['client_id'])
            ->where('active', 1)
            ->where('receives_portal', 1)
            ->exists();
        if (!$clientActive || !$contactActive) {
            PortalSession::clearCookie();
            $context->setResult(Result::fail('Tu acceso al portal ya no está autorizado.', 401));

            return;
        }

        // Ventana deslizante por inactividad: extiende `last_activity_at` y `expires_at`.
        $idleMinutes = SystemSettingsService::getPortalTimeouts()['portal_idle_timeout_minutes'];
        $newExpiresTs = $nowTs + ($idleMinutes * 60);
        $newExpiresUtc = gmdate('Y-m-d H:i:s', $newExpiresTs);
        $newExpiresIso = gmdate('Y-m-d\TH:i:s\Z', $newExpiresTs);

        Capsule::table('portal_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'last_activity_at' => $nowUtc,
                'expires_at' => $newExpiresUtc,
                'updated_at' => $nowUtc,
            ]);

        PortalSession::setCookie($token, $idleMinutes * 60);

        $context->setData('portal_auth', $claims);
        $context->setData('portal_token', $token);
        $context->setData('portal_session', [
            'id' => (int) $session->id,
            'idle_timeout_minutes' => $idleMinutes,
            'expires_at' => $newExpiresUtc,
            'expires_at_iso' => $newExpiresIso,
        ]);
    }
}
