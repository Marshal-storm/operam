<?php

declare(strict_types=1);

namespace System\Support\Integrations;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use RuntimeException;

/**
 * Registro deduplicado de webhooks / eventos entrantes (RNF-2).
 * El UNIQUE (origin, external_id) garantiza que un reenvío del proveedor no se reprocese.
 */
final class IncomingEventRecorder
{
    public const ORIGINS = ['erp_issuer', 'integropay', 'erp_master'];

    /**
     * @param array<string, mixed> $payload
     * @return array{is_duplicate: bool, event: array<string, mixed>}
     */
    public static function record(string $origin, string $externalId, string $eventType, array $payload): array
    {
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new InvalidArgumentException('Origen de evento no soportado: ' . $origin);
        }
        $externalId = trim($externalId);
        $eventType = trim($eventType);
        if ($externalId === '' || mb_strlen($externalId) > 140) {
            throw new InvalidArgumentException('El identificador externo es obligatorio (máx. 140 caracteres).');
        }
        if ($eventType === '' || mb_strlen($eventType) > 80) {
            throw new InvalidArgumentException('El tipo de evento es obligatorio (máx. 80 caracteres).');
        }

        $inserted = Capsule::table('incoming_events')->insertOrIgnore([
            'origin' => $origin,
            'external_id' => $externalId,
            'event_type' => $eventType,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processed' => 0,
            'processed_at' => null,
            'error' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $row = Capsule::table('incoming_events')
            ->where('origin', $origin)
            ->where('external_id', $externalId)
            ->first();
        if ($row === null) {
            throw new RuntimeException('No se pudo registrar el evento entrante.');
        }

        return ['is_duplicate' => $inserted === 0, 'event' => self::mapRow($row)];
    }

    /** Marca el evento como procesado (o con error) tras ejecutar su efecto de negocio. */
    public static function markProcessed(int $eventId, ?string $error = null): void
    {
        Capsule::table('incoming_events')->where('id', $eventId)->update([
            'processed' => $error === null ? 1 : 0,
            'processed_at' => gmdate('Y-m-d H:i:s'),
            'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function mapRow(object $row): array
    {
        $payload = is_array($row->payload_json)
            ? $row->payload_json
            : json_decode((string) $row->payload_json, true);

        return [
            'id' => (int) $row->id,
            'origin' => (string) $row->origin,
            'external_id' => (string) $row->external_id,
            'event_type' => (string) $row->event_type,
            'payload' => is_array($payload) ? $payload : [],
            'processed' => (bool) $row->processed,
            'processed_at' => $row->processed_at !== null ? (string) $row->processed_at : null,
            'error' => $row->error !== null ? (string) $row->error : null,
            'created_at' => $row->created_at !== null ? (string) $row->created_at : null,
        ];
    }
}
