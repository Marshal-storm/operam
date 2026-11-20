<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Listas de precios (`price_lists`). */
final class PriceListsModel extends Model
{
    protected $table = 'price_lists';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'prl';

    /** @var list<string> */
    protected $fillable = [
        'segment_id',
        'name',
        'currency',
        'is_default',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segment_id' => 'integer',
        'is_default' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItemsModel::class, 'price_list_id');
    }
}
