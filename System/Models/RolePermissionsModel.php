<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class RolePermissionsModel extends Model
{
    protected $table = 'rol_permiso';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'rperm';

    /** @var list<string> */
    protected $fillable = [
        'rol_id',
        'modulo_clave',
        'puede_ver',
        'puede_crear',
        'puede_editar',
        'puede_eliminar',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rol_id' => 'integer',
        'puede_ver' => 'boolean',
        'puede_crear' => 'boolean',
        'puede_editar' => 'boolean',
        'puede_eliminar' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
