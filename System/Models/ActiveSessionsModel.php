<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class ActiveSessionsModel extends Model
{
    protected $table = 'sesion_activa';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'ses';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'session_uid',
        'usuario_id',
        'ip_address',
        'user_agent',
        'expira_en',
        'revocada',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'usuario_id' => 'integer',
        'revocada' => 'boolean',
        'expira_en' => 'datetime',
        'created_at' => 'datetime',
    ];
}
