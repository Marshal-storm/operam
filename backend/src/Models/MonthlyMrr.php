<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agregado mensual de MRR. Tabla física: `monthly_mrr`.
 */
final class MonthlyMrr extends BaseModel
{
    protected $table = 'monthly_mrr';

    /** @var list<string> */
    protected $fillable = [
        'month',
        'segment_id',
        'issuer_id',
        'service_id',
        'category',
        'amount',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segment_id' => 'integer',
        'issuer_id' => 'integer',
        'service_id' => 'integer',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segment_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class, 'issuer_id');
    }
}
