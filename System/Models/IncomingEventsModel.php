<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Webhooks / eventos entrantes deduplicados por (origin, external_id) — RNF-2. */
final class IncomingEventsModel extends Model
{
    protected $table = 'incoming_events';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'inev';

    /** Tabla sin `updated_at`: solo se marca `processed` / `processed_at`. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'origin',
        'external_id',
        'event_type',
        'payload_json',
        'processed',
        'processed_at',
        'error',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload_json' => 'array',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
