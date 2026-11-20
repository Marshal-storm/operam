<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Segmento de producto. Tabla física: `segments`.
 */
final class Segment extends BaseModel
{
    protected $table = 'segments';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'default_credit_days',
        'usd_exchange_rate',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'default_credit_days' => 'integer',
        'usd_exchange_rate' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'segment_id');
    }
}
