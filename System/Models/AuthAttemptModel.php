<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class AuthAttemptModel extends Model
{
    protected $table = 'auth_attempts';

    public $alias = 'att';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'ip_address',
        'is_successful',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_successful' => 'boolean',
        'created_at' => 'datetime',
    ];
}
