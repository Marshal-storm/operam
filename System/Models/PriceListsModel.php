<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Listas de precios (`lista_precio`). */
final class PriceListsModel extends Model
{
    protected $table = 'lista_precio';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'prl';

    /** @var list<string> */
    protected $fillable = [
        'segmento_id',
        'nombre',
        'moneda',
        'es_default',
        'vigente_desde',
        'vigente_hasta',
        'activa',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segmento_id' => 'integer',
        'es_default' => 'boolean',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
        'activa' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItemsModel::class, 'lista_precio_id');
    }
}
