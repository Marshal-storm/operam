<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Contrato / suscripción. Tabla física: `contracts`.
 */
final class Contract extends BaseModel
{
    protected $table = 'contracts';

    /** @var list<string> */
    protected $fillable = [
        'folio',
        'client_id',
        'segment_id',
        'frequency',
        'anniversary_date',
        'currency',
        'credit_days',
        'stamp_on_issue',
        'status',
        'next_cutoff_date',
        'origin_contract_id',
        'termination_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'client_id' => 'integer',
        'segment_id' => 'integer',
        'anniversary_date' => 'date',
        'next_cutoff_date' => 'date',
        'credit_days' => 'integer',
        'stamp_on_issue' => 'boolean',
        'origin_contract_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segment_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'contract_id');
    }

    public function collectionStatus(): HasOne
    {
        return $this->hasOne(CollectionStatus::class, 'contract_id');
    }
}
