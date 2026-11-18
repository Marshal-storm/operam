<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Promociones y códigos de descuento (`promocion`). */
final class PromotionsModel extends Model
{
    protected $table = 'promocion';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'prm';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'segmento_id',
        'tipo_descuento',
        'valor_descuento',
        'aplica_a',
        'vigente_desde',
        'vigente_hasta',
        'usos_maximos',
        'usos_actuales',
        'activa',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segmento_id' => 'integer',
        'valor_descuento' => 'decimal:2',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
        'usos_maximos' => 'integer',
        'usos_actuales' => 'integer',
        'activa' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
