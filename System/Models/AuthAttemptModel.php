<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class AuthAttemptModel extends Model
{
    protected $table = 'auth_intento';

    public $alias = 'att';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'ip_address',
        'exitoso',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'exitoso' => 'boolean',
        'created_at' => 'datetime',
    ];
}
