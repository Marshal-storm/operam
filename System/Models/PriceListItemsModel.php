<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Ítems / escalas de lista de precios (`lista_precio_item`). */
final class PriceListItemsModel extends Model
{
    protected $table = 'lista_precio_item';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'pri';

    /** @var list<string> */
    protected $fillable = [
        'lista_precio_id',
        'servicio_id',
        'frecuencia',
        'cantidad_min',
        'cantidad_max',
        'precio_unitario',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'lista_precio_id' => 'integer',
        'servicio_id' => 'integer',
        'cantidad_min' => 'integer',
        'cantidad_max' => 'integer',
        'precio_unitario' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
