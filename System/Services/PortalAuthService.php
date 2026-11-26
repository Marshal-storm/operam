<?php

declare(strict_types=1);

namespace System\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\Result;
use System\Support\AuditLogger;
use System\Support\PortalMagicLinkIssuer;
use System\Support\PortalSession;
use Throwable;

/**
 * Autenticación sin contraseñas del Portal del Cliente — `/api/portal/auth/*` (RF-7.1 / RNF-7).
 *
 *  - `POST request-link`  (libre): correo → liga mágica de 15 min por correo (outbox `smtp`).
 *  - `POST exchange`      (libre): token de un solo uso → JWT `client_portal` de 8 horas.
 *  - `GET  me`            (JWT portal o cookie HttpOnly): restaura la sesión tras un F5.
 *  - `POST logout`        (JWT portal o cookie HttpOnly): expira la cookie y audita.
 *
 * El token en claro jamás se persiste: solo `sha256(token)` en `portal_access_tokens`.
 */
final class PortalAuthService extends CustomService
{
    /** Respuesta genérica: nunca revela si el correo existe o está autorizado. */
    public const GENERIC_REQUEST_MESSAGE = 'Si tu correo está registrado y autorizado, recibirás una liga de acceso válida por 15 minutos.';

    public const INVALID_LINK_MESSAGE = 'La liga de acceso es inválida, ya fue usada o expiró. Solicita una nueva.';

    /** Máximo de registros de cliente (mismo correo) a los que se envía liga en una solicitud. */
    private const MAX_CONTACTS_PER_REQUEST = 5;

    public function handle(Context $context): Result
    {
        $uri = strtolower(trim($context->getUri(), '/'));
        $method = $context->getMethod();
        $action = explode('/', $uri)[2] ?? '';
        $request = $context->getRequest();

        return match (true) {
            $method === 'POST' && $action === 'request-link' => $this->requestLink(
                (string) ($request['email'] ?? ''),
            ),
            $method === 'POST' && $action === 'exchange' => $this->exchangeAndSetCookie(
                (string) ($request['token'] ?? ''),
            ),
            $method === 'GET' && $action === 'me' => $this->me($context),
            $method === 'POST' && $action === 'logout' => $this->logout($context),
            default => Result::fail('Recurso de autenticación del portal no encontrado.', 404),
        };
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

            AuditLogger::log(
                $this->auditActor($contact),
                'portal',
                'portal_access_token',
                (string) $issued['token_id'],
                'create',
                sprintf('Se solicitó liga mágica de portal para %s <%s>', (string) $contact->name, (string) $contact->email),
                null,
                ['origin' => 'email', 'expires_at' => $issued['expires_at'], 'contact_id' => (int) $contact->id],
            );
        }

        $data = [];
        if ($previewUrl !== null && PortalMagicLinkIssuer::isSandboxPreviewAllowed()) {
            $data['sandbox_preview_url'] = $previewUrl;
        }

        return Result::ok($data === [] ? null : $data, self::GENERIC_REQUEST_MESSAGE);
    }

    /**
     * Canjea el token de un solo uso por el JWT del portal (8 horas).
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

        $jwt = PortalSession::issueToken($profile);

        AuditLogger::log(
            [
                'name' => $profile['contact_name'],
                'email' => $profile['contact_email'],
                'segment_id' => $profile['segment_id'],
            ],
            'portal',
            'portal_session',
            (string) ($profile['contact_id'] ?? $profile['client_id']),
            'login',
            sprintf('Inicio de sesión en el portal de %s <%s>', $profile['contact_name'], $profile['contact_email']),
            null,
            ['client_id' => $profile['client_id'], 'origin' => (string) $row->origin],
        );

        return Result::ok($this->sessionPayload($jwt, $profile), 'Sesión del portal iniciada');
    }

    /** Variante HTTP: tras un canje exitoso escribe `operam_portal_session` enjaulada en `Path=/api/portal`. */
    private function exchangeAndSetCookie(string $token): Result
    {
        $result = $this->exchange($token);
        if ($result->isSuccess()) {
            $data = $result->getData();
            if (is_array($data) && isset($data['token']) && is_string($data['token'])) {
                PortalSession::setCookie($data['token']);
            }
        }

        return $result;
    }

    /**
     * Valida la sesión vigente y devuelve el perfil fresco (rehidrata React tras recargar).
     * La guardia de `AppService` ya validó firma, scope y vigencia del contacto.
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

        return Result::ok($this->sessionPayload($jwt, $profile), 'Sesión del portal activa');
    }

    private function logout(Context $context): Result
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $context->getData('portal_auth');
        if (is_array($claims)) {
            AuditLogger::log(
                [
                    'name' => (string) ($claims['contact_name'] ?? ''),
                    'email' => (string) ($claims['contact_email'] ?? ''),
                ],
                'portal',
                'portal_session',
                isset($claims['contact_id']) ? (string) $claims['contact_id'] : (string) ($claims['client_id'] ?? ''),
                'logout',
                sprintf('Cierre de sesión del portal de %s', (string) ($claims['contact_email'] ?? 'contacto')),
                null,
                ['client_id' => (int) ($claims['client_id'] ?? 0)],
            );
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
    private function sessionPayload(string $jwt, array $profile): array
    {
        return [
            'token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => PortalSession::TTL_SECONDS,
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

    /**
     * Actor de bitácora para acciones del portal (no hay `user_id` de panel interno).
     *
     * @return array<string, mixed>
     */
    private function auditActor(object $contact): array
    {
        $segmentId = null;
        try {
            $segmentId = Capsule::table('clients')->where('id', (int) $contact->client_id)->value('segment_id');
        } catch (Throwable) {
            // La auditoría nunca debe romper el flujo.
        }

        return [
            'name' => (string) $contact->name,
            'email' => (string) $contact->email,
            'segment_id' => $segmentId !== null ? (int) $segmentId : null,
        ];
    }
}
