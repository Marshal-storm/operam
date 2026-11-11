<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class UsersModel extends Model
{
    protected $table = 'usuario';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'usr';

    /** @var list<string> */
    protected $fillable = [
        'email',
        'nombre',
        'password_hash',
        'rol',
        'segmento_id',
        'activo',
        'ultimo_acceso',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segmento_id' => 'integer',
        'activo' => 'boolean',
        'ultimo_acceso' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
