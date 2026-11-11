<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Contrato / suscripción. Tabla física: `contrato`.
 */
final class Contract extends BaseModel
{
    protected $table = 'contrato';

    /** @var list<string> */
    protected $fillable = [
        'folio',
        'cliente_id',
        'segmento_id',
        'frecuencia',
        'fecha_aniversario',
        'moneda',
        'dias_credito',
        'timbrar_al_emitir',
        'estado',
        'proximo_corte',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'cliente_id' => 'integer',
        'segmento_id' => 'integer',
        'fecha_aniversario' => 'date',
        'proximo_corte' => 'date',
        'dias_credito' => 'integer',
        'timbrar_al_emitir' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segmento_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'contrato_id');
    }

    public function collectionStatus(): HasOne
    {
        return $this->hasOne(CollectionStatus::class, 'contrato_id');
    }
}
