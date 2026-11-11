<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agregado mensual de MRR. Tabla física: `mrr_mensual`.
 */
final class MonthlyMrr extends BaseModel
{
    protected $table = 'mrr_mensual';

    /** @var list<string> */
    protected $fillable = [
        'mes',
        'segmento_id',
        'emisor_id',
        'servicio_id',
        'categoria',
        'monto',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segmento_id' => 'integer',
        'emisor_id' => 'integer',
        'servicio_id' => 'integer',
        'monto' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segmento_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class, 'emisor_id');
    }
}
