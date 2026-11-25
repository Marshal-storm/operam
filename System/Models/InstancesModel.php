<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Instancias técnicas / VPS (`instances`). */
final class InstancesModel extends Model
{
    protected $table = 'instances';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'ins';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'issuer_id',
        'segment_id',
        'access_url',
        'server_ip',
        'status',
        'api_token_hash',
        'api_token_last4',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'issuer_id' => 'integer',
        'segment_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
