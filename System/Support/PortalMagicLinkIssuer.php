<?php

declare(strict_types=1);

namespace System\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use System\Support\Integrations\ExternalServiceRegistry;
use System\Support\Integrations\OutboxDispatcher;

/**
 * Emisor de Magic Links del Portal del Cliente (RF-7.1 / RNF-7).
 *
 * Responsabilidades (única fuente de verdad, compartida por el autoservicio del portal y por la
 * acción interna "Generar liga mágica" de Clientes):
 *  - Genera un token criptográfico `bin2hex(random_bytes(32))`.
 *  - Persiste ÚNICAMENTE `sha256(token)` en `portal_access_tokens` (nunca el token en claro).
 *  - Encola el correo en `outbox` (`smtp` / `send_magic_link`) con la liga
 *    `{FRONTEND_URL}/portal/acceso?t={TOKEN}`.
 */
final class PortalMagicLinkIssuer
{
    /** Vigencia de la liga: 15 minutos y de un solo uso. */
    public const TTL_MINUTES = 15;

    /** Tope de ligas por contacto en la ventana de vigencia (anti-spam de correos). */
    public const MAX_REQUESTS_PER_WINDOW = 5;

    /**
     * Genera la liga, guarda el hash y encola el correo.
     *
     * @param object $contact Fila de `client_contacts` (id, client_id, name, email).
     * @return array{url: string, token_id: int, expires_at: string, outbox_id: int|null}
     */
    public static function issueForContact(object $contact, ?int $contractId = null): array
    {
        $plainToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plainToken);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $now->modify(sprintf('+%d minutes', self::TTL_MINUTES));
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

        $segmentId = Capsule::table('clients')->where('id', (int) $contact->client_id)->value('segment_id');
        $message = (new OutboxDispatcher())->enqueue(
            'smtp',
            'send_magic_link',
            // La llave deriva del hash (no del token): encolar dos veces el mismo hash no duplica.
            'send_magic_link:' . (int) $contact->id . ':' . substr($hash, 0, 24),
            [
                'to' => [(string) $contact->email],
                'subject' => 'Tu acceso seguro al portal de Operam',
                'text' => self::plainTextBody((string) $contact->name, $url),
                'html' => self::htmlBody((string) $contact->name, $url),
                'contact_id' => (int) $contact->id,
                'client_id' => (int) $contact->client_id,
                'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
            ],
            null,
            'portal_access_token',
            (string) $tokenId,
            $segmentId !== null ? (int) $segmentId : null,
        );

        return [
            'url' => $url,
            'token_id' => $tokenId,
            'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
            'outbox_id' => isset($message['id']) ? (int) $message['id'] : null,
        ];
    }

    /**
     * ¿El contacto ya agotó su cupo de ligas solicitadas en la ventana de vigencia?
     */
    public static function isRateLimited(int $contactId): bool
    {
        $windowStart = gmdate('Y-m-d H:i:s', time() - (self::TTL_MINUTES * 60));
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

    private static function plainTextBody(string $name, string $url): string
    {
        return sprintf(
            "Hola %s,\n\nUsa esta liga para entrar a tu portal de Operam. Es de un solo uso y expira en %d minutos:\n\n%s\n\n"
            . "Si no la solicitaste, ignora este mensaje: nadie podrá entrar sin ella.",
            $name,
            self::TTL_MINUTES,
            $url,
        );
    }

    private static function htmlBody(string $name, string $url): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div style="font-family:Ubuntu,Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#090D16">'
            . '<h2 style="margin:0 0 12px">Hola ' . $safeName . '</h2>'
            . '<p style="margin:0 0 20px;color:#475569">Usa el botón para entrar a tu portal de Operam. '
            . 'La liga es de un solo uso y expira en ' . self::TTL_MINUTES . ' minutos.</p>'
            . '<a href="' . $safeUrl . '" style="display:inline-block;background:#0066FF;color:#fff;'
            . 'padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:600">Entrar al portal</a>'
            . '<p style="margin:20px 0 0;font-size:12px;color:#94A3B8">Si no la solicitaste, ignora este mensaje.</p>'
            . '</div>';
    }
}
