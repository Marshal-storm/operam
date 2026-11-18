<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Instancias técnicas / VPS (`instancia`). */
final class InstancesModel extends Model
{
    protected $table = 'instancia';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'ins';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'emisor_id',
        'segmento_id',
        'url_acceso',
        'ip_servidor',
        'estado',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'emisor_id' => 'integer',
        'segmento_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
