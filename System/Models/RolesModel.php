<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RolesModel extends Model
{
    protected $table = 'roles';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'role';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_system',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return HasMany<RolePermissionsModel, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermissionsModel::class, 'role_id');
    }
}
