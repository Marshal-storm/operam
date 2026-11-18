<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class SystemModulesModel extends Model
{
    protected $table = 'modulo_sistema';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'modsys';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'clave',
        'nombre',
        'grupo',
        'orden',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'orden' => 'integer',
    ];
}
