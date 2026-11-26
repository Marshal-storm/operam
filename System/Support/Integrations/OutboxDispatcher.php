<?php

declare(strict_types=1);

namespace System\Support\Integrations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Motor de la cola transaccional `outbox` (Pág. 37 del PDF).
 *
 * - `enqueue()` es idempotente por `idempotency_key` (RNF-2): se puede invocar dentro de la
 *   misma transacción de negocio; si la llave ya existe devuelve la fila original sin duplicar.
 * - `processPending()` es el worker: toma mensajes vencidos, los despacha en modo sandbox
 *   (simulación) o producción (adaptadores reales) y reprograma fallos con backoff.
 * - Al alcanzar el umbral de fallos alerta una sola vez a Slack (RNF-12).
 */
final class OutboxDispatcher
{
    public const DESTINATIONS = ['erp_issuer', 'erp_master', 'payment_gateway', 'smtp', 'slack'];

    public const STATUSES = ['pending', 'processing', 'sent', 'failed'];

    /** Mensajes `processing` más viejos que esto se consideran huérfanos (worker caído). */
    private const STALE_PROCESSING_MINUTES = 15;

    private const DEFAULT_MAX_ATTEMPTS = 3;

    private const DEFAULT_BACKOFF_MINUTES = 5;

    private const DEFAULT_FAILURE_THRESHOLD = 3;

    private const DEFAULT_HTTP_TIMEOUT = 15;

    private IntegrationTransport $transport;

    public function __construct(?IntegrationTransport $transport = null)
    {
        $this->transport = $transport ?? new DefaultIntegrationTransport();
    }

    /**
     * Encola un mensaje saliente. Duplicados por `idempotency_key` se ignoran (RNF-2).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> Fila del outbox (nueva o existente) + `is_duplicate`.
     */
    public function enqueue(
        string $destination,
        string $operation,
        string $idempotencyKey,
        array $payload,
        ?int $issuerId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?int $segmentId = null,
    ): array {
        if (!in_array($destination, self::DESTINATIONS, true)) {
            throw new InvalidArgumentException('Destino de outbox no soportado: ' . $destination);
        }
        $operation = trim($operation);
        $idempotencyKey = trim($idempotencyKey);
        if ($operation === '' || mb_strlen($operation) > 80) {
            throw new InvalidArgumentException('La operación del outbox es obligatoria (máx. 80 caracteres).');
        }
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 140) {
            throw new InvalidArgumentException('La llave de idempotencia es obligatoria (máx. 140 caracteres).');
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $maxAttempts = $this->intSetting('jobs_outbox', 'max_attempts', self::DEFAULT_MAX_ATTEMPTS, 1, 10);

        $inserted = Capsule::table('outbox')->insertOrIgnore([
            'destination' => $destination,
            'operation' => $operation,
            'idempotency_key' => $idempotencyKey,
            'issuer_id' => $issuerId !== null && $issuerId > 0 ? $issuerId : null,
            'segment_id' => $segmentId !== null && $segmentId > 0 ? $segmentId : null,
            'entity_type' => $entityType !== null ? mb_substr($entityType, 0, 60) : null,
            'entity_id' => $entityId !== null ? mb_substr($entityId, 0, 50) : null,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'next_attempt_at' => $nowUtc,
            'sent_at' => null,
            'response_json' => null,
            'last_error' => null,
            'slack_alerted_at' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $row = Capsule::table('outbox')->where('idempotency_key', $idempotencyKey)->first();
        if ($row === null) {
            throw new RuntimeException('No se pudo encolar el mensaje en outbox.');
        }

        return self::mapRow($row) + ['is_duplicate' => $inserted === 0];
    }

    /**
     * Worker: procesa mensajes vencidos (`pending` / `failed` con intentos disponibles).
     *
     * @return array{processed: int, sent: int, failed: int, slack_alerts: int, items: list<array<string, mixed>>}
     */
    public function processPending(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $nowUtc = gmdate('Y-m-d H:i:s');
        // El worker CLI puede vivir varios ciclos: siempre relee la configuración vigente.
        ExternalServiceRegistry::clearCache();
        $this->recoverStaleProcessing();

        /** @var list<int> $ids */
        $ids = Capsule::connection()->transaction(static function () use ($limit, $nowUtc): array {
            $ids = Capsule::table('outbox')
                ->whereIn('status', ['pending', 'failed'])
                ->whereColumn('attempts', '<', 'max_attempts')
                ->where('next_attempt_at', '<=', $nowUtc)
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids !== []) {
                Capsule::table('outbox')->whereIn('id', $ids)->update([
                    'status' => 'processing',
                    'updated_at' => $nowUtc,
                ]);
            }

            return $ids;
        });

        $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'slack_alerts' => 0, 'items' => []];
        foreach ($ids as $id) {
            $outcome = $this->processClaimed($id);
            $summary['processed']++;
            $summary[$outcome['status'] === 'sent' ? 'sent' : 'failed']++;
            if ($outcome['slack_alerted']) {
                $summary['slack_alerts']++;
            }
            $summary['items'][] = $outcome['message'];
        }

        return $summary;
    }

    /**
     * Reintento manual: reinicia contadores y procesa inmediatamente.
     *
     * @return array<string, mixed> Fila resultante.
     */
    public function retry(int $id): array
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $row = Capsule::table('outbox')->where('id', $id)->first();
        if ($row === null) {
            throw new InvalidArgumentException('Mensaje de outbox no encontrado.');
        }
        if ((string) $row->status === 'processing') {
            throw new InvalidArgumentException('El mensaje se está procesando en este momento.');
        }

        Capsule::table('outbox')->where('id', $id)->update([
            'status' => 'processing',
            'attempts' => 0,
            'next_attempt_at' => $nowUtc,
            'last_error' => null,
            'slack_alerted_at' => null,
            'updated_at' => $nowUtc,
        ]);

        return $this->processClaimed($id)['message'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'destination' => (string) $row->destination,
            'operation' => (string) $row->operation,
            'idempotency_key' => (string) $row->idempotency_key,
            'issuer_id' => $row->issuer_id !== null ? (int) $row->issuer_id : null,
            'segment_id' => isset($row->segment_id) && $row->segment_id !== null ? (int) $row->segment_id : null,
            'entity_type' => $row->entity_type !== null ? (string) $row->entity_type : null,
            'entity_id' => $row->entity_id !== null ? (string) $row->entity_id : null,
            'payload' => self::decodeJson($row->payload_json),
            'status' => (string) $row->status,
            'attempts' => (int) $row->attempts,
            'max_attempts' => (int) $row->max_attempts,
            'next_attempt_at' => $row->next_attempt_at !== null ? (string) $row->next_attempt_at : null,
            'sent_at' => $row->sent_at !== null ? (string) $row->sent_at : null,
            'response' => $row->response_json !== null ? self::decodeJson($row->response_json) : null,
            'last_error' => $row->last_error !== null ? (string) $row->last_error : null,
            'slack_alerted_at' => $row->slack_alerted_at !== null ? (string) $row->slack_alerted_at : null,
            'created_at' => $row->created_at !== null ? (string) $row->created_at : null,
            'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
        ];
    }

    /**
     * Despacha un mensaje ya reclamado (`processing`) y persiste el resultado.
     *
     * @return array{status: string, slack_alerted: bool, message: array<string, mixed>}
     */
    private function processClaimed(int $id): array
    {
        $row = Capsule::table('outbox')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException('Mensaje de outbox no encontrado.');
        }

        $attempts = (int) $row->attempts + 1;
        $slackAlerted = false;

        try {
            $response = $this->dispatch($row);
            $nowUtc = gmdate('Y-m-d H:i:s');
            Capsule::table('outbox')->where('id', $id)->update([
                'status' => 'sent',
                'attempts' => $attempts,
                'sent_at' => $nowUtc,
                'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_error' => null,
                'updated_at' => $nowUtc,
            ]);
            $status = 'sent';
            // RNF-7: una vez entregado, el cuerpo con la liga mágica no debe quedar en claro.
            if ((string) $row->operation === 'send_magic_link') {
                $this->redactMagicLinkPayload($id, self::decodeJson($row->payload_json));
            }
        } catch (Throwable $e) {
            $nowUtc = gmdate('Y-m-d H:i:s');
            $backoff = $this->intSetting('jobs_outbox', 'retry_backoff_minutes', self::DEFAULT_BACKOFF_MINUTES, 1, 1440);
            $nextAttempt = (new DateTimeImmutable($nowUtc, new DateTimeZone('UTC')))
                ->modify(sprintf('+%d minutes', $backoff * $attempts))
                ->format('Y-m-d H:i:s');
            $error = mb_substr($e->getMessage() !== '' ? $e->getMessage() : get_class($e), 0, 2000);

            Capsule::table('outbox')->where('id', $id)->update([
                'status' => 'failed',
                'attempts' => $attempts,
                'last_error' => $error,
                'next_attempt_at' => $nextAttempt,
                'updated_at' => $nowUtc,
            ]);
            $status = 'failed';
            $slackAlerted = $this->maybeAlertSlack($row, $attempts, $error);
        }

        $fresh = Capsule::table('outbox')->where('id', $id)->first();

        return [
            'status' => $status,
            'slack_alerted' => $slackAlerted,
            'message' => $fresh !== null ? self::mapRow($fresh) : ['id' => $id, 'status' => $status],
        ];
    }

    /**
     * @return array<string, mixed> Respuesta persistida en `response_json`.
     */
    private function dispatch(object $row): array
    {
        $payload = self::decodeJson($row->payload_json);
        $destination = (string) $row->destination;
        $operation = (string) $row->operation;

        return match ($destination) {
            'erp_master' => $this->dispatchErpMaster($row, $payload),
            'erp_issuer' => $this->dispatchErpIssuer($row, $payload),
            'payment_gateway' => $this->dispatchPaymentGateway($row),
            'smtp' => $this->dispatchSmtp($row, $payload),
            'slack' => $this->dispatchSlack($row, $payload),
            default => throw new RuntimeException(sprintf('Destino %s sin adaptador (%s).', $destination, $operation)),
        };
    }

    /** @param array<string, mixed> $payload */
    private function dispatchErpMaster(object $row, array $payload): array
    {
        $baseUrl = (string) ExternalServiceRegistry::configValue('erp_master', 'base_url', '');
        if (!ExternalServiceRegistry::isProduction('erp_master') || $baseUrl === '') {
            return $this->sandboxResponse($row, $baseUrl === '' ? 'ERP Master sin URL base productiva' : 'ERP Master en modo sandbox');
        }

        $apiKey = ExternalServiceRegistry::resolveSecret('erp_master', 'api_key_ref');
        if ($apiKey === null) {
            throw new RuntimeException('La referencia de API key del ERP Master no está definida en el entorno.');
        }

        $result = $this->transport->postJson(
            $baseUrl,
            ['Authorization' => 'Bearer ' . $apiKey, 'Idempotency-Key' => (string) $row->idempotency_key],
            $this->envelope($row, $payload),
            $this->intSetting('erp_master', 'timeout_seconds', self::DEFAULT_HTTP_TIMEOUT, 1, 120),
        );

        return ['sandbox' => false, 'http_status' => $result['status'], 'body' => $result['body']];
    }

    /**
     * ERP Emisor: URL y secreto por empresa facturadora (`issuers.api_url` / `issuers.secret_ref`).
     * El interruptor global de producción es el servicio `jobs_outbox`.
     *
     * @param array<string, mixed> $payload
     */
    private function dispatchErpIssuer(object $row, array $payload): array
    {
        $issuer = $row->issuer_id !== null
            ? Capsule::table('issuers')->where('id', (int) $row->issuer_id)->first()
            : null;
        $apiUrl = $issuer !== null ? trim((string) ($issuer->api_url ?? '')) : '';

        if (!ExternalServiceRegistry::isProduction('jobs_outbox') || $apiUrl === '' || !(bool) ($issuer->is_active ?? false)) {
            return $this->sandboxResponse($row, $apiUrl === '' ? 'Emisor sin URL productiva de ERP' : 'Worker de outbox en modo sandbox');
        }

        $secretRef = trim((string) ($issuer->secret_ref ?? ''));
        $secret = $secretRef !== '' ? SecretResolver::resolve($secretRef) : null;
        if ($secret === null) {
            throw new RuntimeException('La referencia de secreto del ERP Emisor no está definida en el entorno.');
        }

        $result = $this->transport->postJson(
            $apiUrl,
            ['Authorization' => 'Bearer ' . $secret, 'Idempotency-Key' => (string) $row->idempotency_key],
            $this->envelope($row, $payload),
            $this->intSetting('erp_master', 'timeout_seconds', self::DEFAULT_HTTP_TIMEOUT, 1, 120),
        );

        return ['sandbox' => false, 'http_status' => $result['status'], 'body' => $result['body']];
    }

    private function dispatchPaymentGateway(object $row): array
    {
        if (!ExternalServiceRegistry::isProduction('jobs_outbox')) {
            return $this->sandboxResponse($row, 'Worker de outbox en modo sandbox');
        }

        throw new RuntimeException('IntegroPAY opera en línea (ligas y webhook verificado); no admite operaciones encoladas.');
    }

    /** @param array<string, mixed> $payload */
    private function dispatchSmtp(object $row, array $payload): array
    {
        $host = (string) ExternalServiceRegistry::configValue('smtp', 'host', '');
        if (!ExternalServiceRegistry::isProduction('smtp') || $host === '') {
            return $this->sandboxResponse($row, 'SMTP en modo sandbox');
        }

        $to = array_values(array_filter(
            array_map('strval', (array) ($payload['to'] ?? [])),
            static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));
        $subject = trim((string) ($payload['subject'] ?? ''));
        if ($to === [] || $subject === '') {
            throw new RuntimeException('El correo requiere destinatarios válidos (to) y asunto (subject).');
        }

        $result = $this->transport->sendEmail($this->smtpSettings(), [
            'to' => $to,
            'subject' => $subject,
            'text' => isset($payload['text']) ? (string) $payload['text'] : null,
            'html' => isset($payload['html']) ? (string) $payload['html'] : null,
        ]);

        return ['sandbox' => false, 'message_id' => $result['message_id'] ?? null, 'recipients' => count($to)];
    }

    /** @param array<string, mixed> $payload */
    private function dispatchSlack(object $row, array $payload): array
    {
        $webhook = ExternalServiceRegistry::resolveSecret('slack', 'webhook_url_ref');
        if (!ExternalServiceRegistry::isProduction('slack') || $webhook === null) {
            return $this->sandboxResponse($row, $webhook === null ? 'Webhook de Slack sin definir en entorno' : 'Slack en modo sandbox');
        }

        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('El mensaje de Slack requiere texto (text).');
        }
        $result = $this->transport->postJson($webhook, [], ['text' => $text], self::DEFAULT_HTTP_TIMEOUT);

        return ['sandbox' => false, 'http_status' => $result['status']];
    }

    /**
     * Alerta única a Slack al llegar al umbral de fallos (RNF-12).
     */
    private function maybeAlertSlack(object $row, int $attempts, string $error): bool
    {
        if ($row->slack_alerted_at !== null) {
            return false;
        }
        $threshold = $this->intSetting('slack', 'failure_threshold', self::DEFAULT_FAILURE_THRESHOLD, 1, 10);
        if ($attempts < $threshold) {
            return false;
        }

        $text = sprintf(
            ':rotating_light: Outbox #%d (%s → %s) acumula %d fallo(s). Último error: %s',
            (int) $row->id,
            (string) $row->operation,
            (string) $row->destination,
            $attempts,
            mb_substr($error, 0, 300),
        );

        try {
            $webhook = ExternalServiceRegistry::resolveSecret('slack', 'webhook_url_ref');
            if (ExternalServiceRegistry::isProduction('slack') && $webhook !== null) {
                $this->transport->postJson($webhook, [], ['text' => $text], self::DEFAULT_HTTP_TIMEOUT);
            }
            // En sandbox la alerta se considera "entregada" de forma simulada.
            Capsule::table('outbox')->where('id', (int) $row->id)->update([
                'slack_alerted_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (Throwable $e) {
            error_log(sprintf('[outbox] Alerta Slack fallida para #%d: %s', (int) $row->id, $e->getMessage()));

            return false;
        }
    }

    /**
     * Sustituye el cuerpo (que contiene el token de acceso) por una marca de redacción.
     *
     * @param array<string, mixed> $payload
     */
    private function redactMagicLinkPayload(int $id, array $payload): void
    {
        foreach (['text', 'html'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '[REDACTED]';
            }
        }
        Capsule::table('outbox')->where('id', $id)->update([
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** Devuelve a `pending` mensajes atascados en `processing` por un worker caído. */
    private function recoverStaleProcessing(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::STALE_PROCESSING_MINUTES * 60);
        Capsule::table('outbox')
            ->where('status', 'processing')
            ->where('updated_at', '<', $cutoff)
            ->update(['status' => 'pending', 'next_attempt_at' => $nowUtc, 'updated_at' => $nowUtc]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function envelope(object $row, array $payload): array
    {
        return [
            'operation' => (string) $row->operation,
            'idempotency_key' => (string) $row->idempotency_key,
            'entity_type' => $row->entity_type,
            'entity_id' => $row->entity_id,
            'payload' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    private function sandboxResponse(object $row, string $reason): array
    {
        return [
            'sandbox' => true,
            'simulated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'destination' => (string) $row->destination,
            'operation' => (string) $row->operation,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, password: string|null, from_email: string, from_name: string}
     */
    public static function smtpSettings(): array
    {
        return [
            'host' => (string) ExternalServiceRegistry::configValue('smtp', 'host', ''),
            'port' => (int) ExternalServiceRegistry::configValue('smtp', 'port', 587),
            'encryption' => (string) ExternalServiceRegistry::configValue('smtp', 'encryption', 'tls'),
            'username' => (string) ExternalServiceRegistry::configValue('smtp', 'username', ''),
            'password' => ExternalServiceRegistry::resolveSecret('smtp', 'password_ref'),
            'from_email' => (string) ExternalServiceRegistry::configValue('smtp', 'from_email', ''),
            'from_name' => (string) ExternalServiceRegistry::configValue('smtp', 'from_name', ''),
        ];
    }

    private function intSetting(string $serviceKey, string $field, int $default, int $min, int $max): int
    {
        $value = ExternalServiceRegistry::configValue($serviceKey, $field, $default);
        $int = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $int));
    }

    /** @return array<string, mixed> */
    private static function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
