<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Ítems / escalas de lista de precios (`price_list_items`). */
final class PriceListItemsModel extends Model
{
    protected $table = 'price_list_items';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'pri';

    /** @var list<string> */
    protected $fillable = [
        'price_list_id',
        'service_id',
        'frequency',
        'min_quantity',
        'max_quantity',
        'unit_price',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'price_list_id' => 'integer',
        'service_id' => 'integer',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
