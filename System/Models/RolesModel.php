<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RolesModel extends Model
{
    protected $table = 'rol';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'rol';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'es_sistema',
        'activo',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'es_sistema' => 'boolean',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return HasMany<RolePermissionsModel, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermissionsModel::class, 'rol_id');
    }
}
