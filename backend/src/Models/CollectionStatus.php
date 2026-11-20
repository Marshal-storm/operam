<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de cobranza por contrato. Tabla física: `collection_statuses`.
 */
final class CollectionStatus extends BaseModel
{
    protected $table = 'collection_statuses';

    protected $primaryKey = 'contract_id';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'contract_id',
        'overdue_balance',
        'overdue_days',
        'notice_level',
        'is_suspended',
        'updated_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contract_id' => 'integer',
        'overdue_balance' => 'decimal:2',
        'overdue_days' => 'integer',
        'notice_level' => 'integer',
        'is_suspended' => 'boolean',
        'updated_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
