<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class UsersModel extends Model
{
    protected $table = 'users';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'usr';

    /** @var list<string> */
    protected $fillable = [
        'email',
        'name',
        'password_hash',
        'role_id',
        'segment_id',
        'is_active',
        'last_login_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'role_id' => 'integer',
        'segment_id' => 'integer',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
