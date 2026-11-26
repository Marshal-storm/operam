<?php

declare(strict_types=1);

namespace System\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\Result;
use System\Support\PortalMagicLinkIssuer;
use System\Support\PortalSession;

/**
 * Autenticación sin contraseñas del Portal del Cliente — `/api/portal/auth/*` (RF-7.1 / RNF-7).
 *
 *  - `GET  config`        (libre): devuelve TTL de liga mágica e inactividad desde `system_settings`.
 *  - `POST request-link`  (libre): correo → liga mágica de vigencia dinámica por correo (outbox `smtp`).
 *  - `POST exchange`      (libre): token de un solo uso → JWT `client_portal` + sesión deslizante.
 *  - `GET  me`            (JWT portal o cookie HttpOnly): restaura la sesión tras un F5.
 *  - `POST keepalive`     (JWT portal o cookie HttpOnly): renueva la ventana deslizante.
 *  - `POST logout`        (JWT portal o cookie HttpOnly): revoca sesión y expira la cookie.
 *
 * El token en claro jamás se persiste: solo `sha256(token)` en `portal_access_tokens`.
 */
final class PortalAuthService extends CustomService
{
    public const INVALID_LINK_MESSAGE = 'La liga de acceso es inválida, ya fue usada o expiró. Solicita una nueva.';

    /** Máximo de registros de cliente (mismo correo) a los que se envía liga en una solicitud. */
    private const MAX_CONTACTS_PER_REQUEST = 5;

    /**
     * Mensaje genérico con vigencia dinámica (nunca revela si el correo existe o está autorizado).
     */
    public static function buildRequestLinkMessage(int $ttlMinutes): string
    {
        $unit = $ttlMinutes === 1 ? '1 minuto' : sprintf('%d minutos', $ttlMinutes);

        return sprintf(
            'Si tu correo está registrado y autorizado, recibirás una liga de acceso válida por %s.',
            $unit,
        );
    }

    public function handle(Context $context): Result
    {
        $uri = strtolower(trim($context->getUri(), '/'));
        $method = $context->getMethod();
        $action = explode('/', $uri)[2] ?? '';
        $request = $context->getRequest();

        return match (true) {
            $method === 'GET' && $action === 'config' => $this->publicConfig(),
            $method === 'POST' && $action === 'request-link' => $this->requestLink(
                (string) ($request['email'] ?? ''),
            ),
            $method === 'POST' && $action === 'exchange' => $this->exchangeAndSetCookie(
                (string) ($request['token'] ?? ''),
            ),
            $method === 'GET' && $action === 'me' => $this->me($context),
            $method === 'POST' && $action === 'keepalive' => $this->keepalive($context),
            $method === 'POST' && $action === 'logout' => $this->logout($context),
            default => Result::fail('Recurso de autenticación del portal no encontrado.', 404),
        };
    }

    /**
     * Configuración pública de solo lectura (`GET /api/portal/auth/config`): expone únicamente
     * los tiempos dinámicos del portal configurados en `system_settings` (sin datos sensibles).
     */
    public function publicConfig(): Result
    {
        $timeouts = SystemSettingsService::getPortalTimeouts();

        return Result::ok([
            'magic_link_ttl_minutes' => $timeouts['portal_magic_link_ttl_minutes'],
            'idle_timeout_minutes' => $timeouts['portal_idle_timeout_minutes'],
        ], 'Configuración pública del portal');
    }

    /**
     * Solicitud de liga por correo. Siempre responde el mismo mensaje genérico (200) para no
     * filtrar qué correos están registrados; solo en sandbox local añade `sandbox_preview_url`.
     */
    public function requestLink(string $email): Result
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || mb_strlen($normalized) > 160 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return Result::fail('Ingresa un correo electrónico válido.', 422);
        }

        $ttlMinutes = PortalMagicLinkIssuer::ttlMinutes();

        $contacts = Capsule::table('client_contacts as cc')
            ->join('clients as cl', 'cl.id', '=', 'cc.client_id')
            ->whereRaw('LOWER(cc.email) = ?', [$normalized])
            ->where('cc.active', 1)
            ->where('cc.receives_portal', 1)
            ->where('cl.active', 1)
            ->orderByRaw('COALESCE(cc.updated_at, cc.created_at) DESC')
            ->orderByDesc('cc.id')
            ->limit(self::MAX_CONTACTS_PER_REQUEST)
            ->get(['cc.id', 'cc.client_id', 'cc.name', 'cc.email']);

        $previewUrl = null;
        foreach ($contacts as $contact) {
            if (PortalMagicLinkIssuer::isRateLimited((int) $contact->id)) {
                continue;
            }
            $issued = PortalMagicLinkIssuer::issueForContact($contact);
            $previewUrl ??= $issued['url'];
        }

        $data = [
            'ttl_minutes' => $ttlMinutes,
        ];
        if ($previewUrl !== null && PortalMagicLinkIssuer::isSandboxPreviewAllowed()) {
            $data['sandbox_preview_url'] = $previewUrl;
        }

        return Result::ok($data, self::buildRequestLinkMessage($ttlMinutes));
    }

    /**
     * Canjea el token de un solo uso por el JWT del portal con sesión deslizante.
     * Un token usado, expirado, inexistente o de un contacto ya no autorizado responde 401.
     */
    public function exchange(string $token): Result
    {
        $plain = trim($token);
        if (preg_match('/^[a-f0-9]{64}$/', $plain) !== 1) {
            return Result::fail(self::INVALID_LINK_MESSAGE, 401);
        }

        $hash = hash('sha256', $plain);
        $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $row = Capsule::table('portal_access_tokens')
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', $nowUtc)
            ->first();
        if ($row === null) {
            return Result::fail(self::INVALID_LINK_MESSAGE, 401);
        }

        // Quemado atómico: si dos canjes compiten, solo uno afecta la fila (RNF-7).
        $burned = Capsule::table('portal_access_tokens')
            ->where('id', (int) $row->id)
            ->whereNull('used_at')
            ->update(['used_at' => $nowUtc]);
        if ($burned !== 1) {
            return Result::fail(self::INVALID_LINK_MESSAGE, 401);
        }

        $profile = $this->resolveProfile((int) $row->client_id, $row->contact_id !== null ? (int) $row->contact_id : null);
        if ($profile === null) {
            return Result::fail(self::INVALID_LINK_MESSAGE, 401);
        }
        $profile['contract_id'] = $row->contract_id !== null ? (int) $row->contract_id : null;

        $timeouts = SystemSettingsService::getPortalTimeouts();
        $idleMinutes = $timeouts['portal_idle_timeout_minutes'];
        $nowTs = time();
        $expiresTs = $nowTs + ($idleMinutes * 60);
        $nowDb = gmdate('Y-m-d H:i:s', $nowTs);
        $expiresDb = gmdate('Y-m-d H:i:s', $expiresTs);
        $expiresIso = gmdate('Y-m-d\TH:i:s\Z', $expiresTs);

        $sessionId = PortalSession::generateSessionId();
        Capsule::table('portal_sessions')->insert([
            'session_token_hash' => PortalSession::hashSessionId($sessionId),
            'client_id' => (int) $profile['client_id'],
            'contact_id' => $profile['contact_id'],
            'last_activity_at' => $nowDb,
            'expires_at' => $expiresDb,
            'revoked_at' => null,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
        ]);

        $jwt = PortalSession::issueToken($profile, $nowTs, $sessionId);

        return Result::ok(
            $this->sessionPayload($jwt, $profile, $idleMinutes, $expiresIso),
            'Sesión del portal iniciada',
        );
    }

    /** Variante HTTP: tras un canje exitoso escribe `operam_portal_session` enjaulada en `Path=/api/portal`. */
    private function exchangeAndSetCookie(string $token): Result
    {
        $result = $this->exchange($token);
        if ($result->isSuccess()) {
            $data = $result->getData();
            if (is_array($data) && isset($data['token']) && is_string($data['token'])) {
                $ttlSeconds = isset($data['expires_in']) ? (int) $data['expires_in'] : null;
                PortalSession::setCookie($data['token'], $ttlSeconds);
            }
        }

        return $result;
    }

    /**
     * Valida la sesión vigente y devuelve el perfil fresco (rehidrata React tras recargar).
     * La guardia de `AppService` ya validó firma, scope, sesión deslizante y vigencia del contacto.
     */
    private function me(Context $context): Result
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $context->getData('portal_auth');
        $jwt = (string) $context->getData('portal_token', '');
        if (!is_array($claims) || $jwt === '') {
            return Result::fail('Sesión del portal requerida.', 401);
        }

        $profile = $this->resolveProfile(
            (int) $claims['client_id'],
            isset($claims['contact_id']) ? (int) $claims['contact_id'] : null,
        );
        if ($profile === null) {
            return Result::fail('Tu acceso al portal ya no está autorizado.', 401);
        }
        $profile['contract_id'] = isset($claims['contract_id']) ? (int) $claims['contract_id'] : null;

        /** @var array{idle_timeout_minutes:int, expires_at_iso:string}|null $sessionMeta */
        $sessionMeta = $context->getData('portal_session');
        $idleMinutes = $sessionMeta['idle_timeout_minutes']
            ?? SystemSettingsService::getPortalTimeouts()['portal_idle_timeout_minutes'];
        $expiresIso = $sessionMeta['expires_at_iso']
            ?? gmdate('Y-m-d\TH:i:s\Z', time() + ($idleMinutes * 60));

        return Result::ok(
            $this->sessionPayload($jwt, $profile, $idleMinutes, $expiresIso),
            'Sesión del portal activa',
        );
    }

    /**
     * Latido ligero (`POST /api/portal/auth/keepalive`): la guardia de `AppService` ya renovó
     * `last_activity_at`, `expires_at` y la cookie HttpOnly; devuelve la nueva ventana al cliente.
     */
    private function keepalive(Context $context): Result
    {
        /** @var array{idle_timeout_minutes:int, expires_at_iso:string}|null $sessionMeta */
        $sessionMeta = $context->getData('portal_session');
        if (!is_array($sessionMeta)) {
            return Result::fail(
                'Tu sesión expiró por inactividad. Solicita o abre una nueva liga de acceso.',
                401,
            )->withCode('PORTAL_SESSION_EXPIRED');
        }

        return Result::ok([
            'expires_at' => $sessionMeta['expires_at_iso'],
            'idle_timeout_minutes' => $sessionMeta['idle_timeout_minutes'],
        ], 'Sesión renovada');
    }

    private function logout(Context $context): Result
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $context->getData('portal_auth');
        $sid = is_array($claims) ? trim((string) ($claims['sid'] ?? '')) : '';
        if ($sid !== '') {
            $nowUtc = gmdate('Y-m-d H:i:s');
            Capsule::table('portal_sessions')
                ->where('session_token_hash', PortalSession::hashSessionId($sid))
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
        }

        // Expira únicamente `operam_portal_session` en `Path=/api/portal` sin tocar sesiones internas.
        PortalSession::clearCookie();

        return Result::ok(null, 'Sesión del portal cerrada');
    }

    /**
     * Verifica que cliente y contacto sigan activos/autorizados y arma los claims.
     * Si el token proviene de una instancia (sin contacto) usa los datos de contacto del cliente.
     *
     * @return array{client_id:int, client_name:string, segment_id:int|null, contact_id:int|null, contact_name:string, contact_email:string, contract_id:int|null}|null
     */
    private function resolveProfile(int $clientId, ?int $contactId): ?array
    {
        $client = Capsule::table('clients')->where('id', $clientId)->where('active', 1)->first();
        if ($client === null) {
            return null;
        }

        if ($contactId !== null) {
            $contact = Capsule::table('client_contacts')
                ->where('id', $contactId)
                ->where('client_id', $clientId)
                ->where('active', 1)
                ->where('receives_portal', 1)
                ->first();
            if ($contact === null) {
                return null;
            }
            $name = (string) $contact->name;
            $email = (string) $contact->email;
        } else {
            $name = trim((string) ($client->contact_name ?? '')) !== '' ? (string) $client->contact_name : (string) $client->name;
            $email = (string) ($client->contact_email ?? '');
        }

        return [
            'client_id' => $clientId,
            'client_name' => (string) $client->name,
            'segment_id' => isset($client->segment_id) ? (int) $client->segment_id : null,
            'contact_id' => $contactId,
            'contact_name' => $name,
            'contact_email' => $email,
            'contract_id' => null,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function sessionPayload(
        string $jwt,
        array $profile,
        int $idleTimeoutMinutes,
        string $expiresAtIso,
    ): array {
        return [
            'token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => $idleTimeoutMinutes * 60,
            'idle_timeout_minutes' => $idleTimeoutMinutes,
            'expires_at' => $expiresAtIso,
            'profile' => [
                'client_id' => (int) $profile['client_id'],
                'client_name' => (string) $profile['client_name'],
                'contact_id' => $profile['contact_id'],
                'contact_name' => (string) $profile['contact_name'],
                'contact_email' => (string) $profile['contact_email'],
                'contract_id' => $profile['contract_id'],
                'scope' => PortalSession::SCOPE,
            ],
        ];
    }
}
