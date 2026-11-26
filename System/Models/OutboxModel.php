<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Cola transaccional saliente (`outbox`) con idempotencia por `idempotency_key` (RNF-2). */
final class OutboxModel extends Model
{
    protected $table = 'outbox';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'obx';

    /** @var list<string> */
    protected $fillable = [
        'destination',
        'operation',
        'idempotency_key',
        'issuer_id',
        'segment_id',
        'entity_type',
        'entity_id',
        'payload_json',
        'status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'sent_at',
        'response_json',
        'last_error',
        'slack_alerted_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'issuer_id' => 'integer',
        'segment_id' => 'integer',
        'payload_json' => 'array',
        'response_json' => 'array',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'slack_alerted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
