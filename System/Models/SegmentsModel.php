<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Segmento de producto (`segmento`) — RF-1.
 * `dias_credito_default` se hereda al alta de clientes/contratos.
 */
final class SegmentsModel extends Model
{
    protected $table = 'segmento';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'seg';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'dias_credito_default',
        'tipo_cambio_usd',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'dias_credito_default' => 'integer',
        'tipo_cambio_usd' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
