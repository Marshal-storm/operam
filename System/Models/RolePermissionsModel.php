<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class RolePermissionsModel extends Model
{
    protected $table = 'role_permissions';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'rperm';

    /** @var list<string> */
    protected $fillable = [
        'role_id',
        'module_key',
        'can_view',
        'can_create',
        'can_edit',
        'can_delete',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'role_id' => 'integer',
        'can_view' => 'boolean',
        'can_create' => 'boolean',
        'can_edit' => 'boolean',
        'can_delete' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
