<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Catálogo de productos/servicios (`servicio`). */
final class ServicesCatalogModel extends Model
{
    protected $table = 'servicio';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'svc';

    /** @var list<string> */
    protected $fillable = [
        'segmento_id',
        'emisor_default_id',
        'codigo',
        'nombre',
        'descripcion',
        'tipo',
        'unidad_medida',
        'permite_conversion_paquete',
        'activo',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segmento_id' => 'integer',
        'emisor_default_id' => 'integer',
        'permite_conversion_paquete' => 'boolean',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
