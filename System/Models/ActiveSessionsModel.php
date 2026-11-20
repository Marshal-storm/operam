<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class ActiveSessionsModel extends Model
{
    protected $table = 'active_sessions';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'ses';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'session_uid',
        'user_id',
        'ip_address',
        'user_agent',
        'expires_at',
        'is_revoked',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'is_revoked' => 'boolean',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
