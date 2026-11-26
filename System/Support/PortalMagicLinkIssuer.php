<?php

declare(strict_types=1);

namespace System\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use System\Services\SystemSettingsService;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\Integrations\OutboxDispatcher;

/**
 * Emisor de Magic Links del Portal del Cliente (RF-7.1 / RNF-7).
 *
 * Responsabilidades (única fuente de verdad, compartida por el autoservicio del portal y por la
 * acción interna "Generar liga mágica" de Clientes):
 *  - Genera un token criptográfico `bin2hex(random_bytes(32))`.
 *  - Persiste ÚNICAMENTE `sha256(token)` en `portal_access_tokens` (nunca el token en claro).
 *  - Lee dinámicamente `portal_magic_link_ttl_minutes` desde `system_settings`.
 *  - Si `$sendEmail === true`, encola el correo en `outbox` (`smtp` / `send_magic_link`).
 */
final class PortalMagicLinkIssuer
{
    /** Fallback por defecto de vigencia de la liga: 15 minutos y de un solo uso. */
    public const TTL_MINUTES = 15;

    /** Tope de ligas por contacto en la ventana de vigencia (anti-spam de correos). */
    public const MAX_REQUESTS_PER_WINDOW = 5;

    /** Vigencia dinámica de la liga mágica desde `system_settings` (acotada 1–1440 min). */
    public static function ttlMinutes(): int
    {
        return SystemSettingsService::getPortalTimeouts()['portal_magic_link_ttl_minutes'];
    }

    /**
     * Genera la liga, guarda el hash y opcionalmente encola el correo en `outbox`.
     *
     * @param object $contact Fila de `client_contacts` (id, client_id, name, email).
     * @return array{url: string, token_id: int, expires_at: string, ttl_minutes: int, outbox_id: int|null, email_queued: bool}
     */
    public static function issueForContact(
        object $contact,
        ?int $contractId = null,
        bool $sendEmail = true,
    ): array {
        $plainToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plainToken);
        $ttlMinutes = self::ttlMinutes();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $now->modify(sprintf('+%d minutes', $ttlMinutes));
        $expiresAt = $expires->format('Y-m-d H:i:s');

        $tokenId = (int) Capsule::table('portal_access_tokens')->insertGetId([
            'client_id' => (int) $contact->client_id,
            'contract_id' => $contractId,
            'contact_id' => (int) $contact->id,
            'token_hash' => $hash,
            'origin' => 'email',
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $url = self::frontendBaseUrl() . '/portal/acceso?t=' . $plainToken;
        $outboxId = null;

        if ($sendEmail) {
            $segmentId = Capsule::table('clients')->where('id', (int) $contact->client_id)->value('segment_id');
            $message = (new OutboxDispatcher())->enqueue(
                'smtp',
                'send_magic_link',
                // La llave deriva del hash (no del token): encolar dos veces el mismo hash no duplica.
                'send_magic_link:' . (int) $contact->id . ':' . substr($hash, 0, 24),
                [
                    'to' => [(string) $contact->email],
                    'subject' => 'Tu acceso seguro al portal de Operam',
                    'text' => self::plainTextBody((string) $contact->name, $url, $ttlMinutes),
                    'html' => self::htmlBody((string) $contact->name, $url, $ttlMinutes),
                    'contact_id' => (int) $contact->id,
                    'client_id' => (int) $contact->client_id,
                    'ttl_minutes' => $ttlMinutes,
                    'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
                ],
                null,
                'portal_access_token',
                (string) $tokenId,
                $segmentId !== null ? (int) $segmentId : null,
            );
            $outboxId = isset($message['id']) ? (int) $message['id'] : null;
        }

        return [
            'url' => $url,
            'token_id' => $tokenId,
            'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
            'ttl_minutes' => $ttlMinutes,
            'outbox_id' => $outboxId,
            'email_queued' => $sendEmail,
        ];
    }

    /**
     * ¿El contacto ya agotó su cupo de ligas solicitadas en la ventana de vigencia?
     */
    public static function isRateLimited(int $contactId): bool
    {
        $windowStart = gmdate('Y-m-d H:i:s', time() - (self::ttlMinutes() * 60));
        $count = Capsule::table('portal_access_tokens')
            ->where('contact_id', $contactId)
            ->where('origin', 'email')
            ->where('created_at', '>=', $windowStart)
            ->count();

        return $count >= self::MAX_REQUESTS_PER_WINDOW;
    }

    /**
     * Solo en sandbox local (SMTP sin tráfico real) se devuelve la liga en la respuesta para
     * poder probar el flujo sin correo. Jamás con `APP_ENV=production`.
     */
    public static function isSandboxPreviewAllowed(): bool
    {
        $env = strtolower((string) (getenv('APP_ENV') ?: ''));
        if ($env === 'production') {
            return false;
        }
        $smtp = ExternalServiceRegistry::find('smtp');

        return $smtp !== null && $smtp['mode'] === 'sandbox';
    }

    /** URL pública del frontend (ligas del portal) sin slash final. */
    public static function frontendBaseUrl(): string
    {
        $url = (string) (getenv('FRONTEND_URL') ?: getenv('APP_FRONTEND_URL') ?: 'http://localhost:5173');

        return rtrim($url, '/');
    }

    private static function formatDurationLabel(int $ttlMinutes): string
    {
        return $ttlMinutes === 1 ? '1 minuto' : sprintf('%d minutos', $ttlMinutes);
    }

    private static function plainTextBody(string $name, string $url, int $ttlMinutes): string
    {
        return sprintf(
            "Hola %s,\n\nUsa esta liga para entrar a tu portal de Operam. Es de un solo uso y expira en %s:\n\n%s\n\n"
            . "Si no la solicitaste, ignora este mensaje: nadie podrá entrar sin ella.",
            $name,
            self::formatDurationLabel($ttlMinutes),
            $url,
        );
    }

    private static function htmlBody(string $name, string $url, int $ttlMinutes): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $durationLabel = self::formatDurationLabel($ttlMinutes);

        return '<div style="font-family:Ubuntu,Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#090D16">'
            . '<h2 style="margin:0 0 12px">Hola ' . $safeName . '</h2>'
            . '<p style="margin:0 0 20px;color:#475569">Usa el botón para entrar a tu portal de Operam. '
            . 'La liga es de un solo uso y expira en ' . $durationLabel . '.</p>'
            . '<a href="' . $safeUrl . '" style="display:inline-block;background:#0066FF;color:#fff;'
            . 'padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:600">Entrar al portal</a>'
            . '<p style="margin:20px 0 0;font-size:12px;color:#94A3B8">Si no la solicitaste, ignora este mensaje.</p>'
            . '</div>';
    }
}
