<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora inmutable de MRR (RNF-3).
 * Solo INSERT (o INSERT de reversión). UPDATE/DELETE bloqueados aquí y por triggers MySQL.
 * Tabla física: `movements`.
 */
final class Movement extends BaseModel
{
    protected $table = 'movements';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'contract_id',
        'contract_line_id',
        'service_id',
        'movement_type',
        'mrr_category',
        'effective_date',
        'quantity_before',
        'quantity_after',
        'mrr_before',
        'mrr_after',
        'mrr_delta_mxn',
        'exchange_rate',
        'user_id',
        'request_id',
        'reason',
        'has_exception',
        'excepted_policy',
        'reversed_movement_id',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contract_id' => 'integer',
        'contract_line_id' => 'integer',
        'service_id' => 'integer',
        'effective_date' => 'date',
        'quantity_before' => 'decimal:2',
        'quantity_after' => 'decimal:2',
        'mrr_before' => 'decimal:2',
        'mrr_after' => 'decimal:2',
        'mrr_delta_mxn' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'user_id' => 'integer',
        'request_id' => 'integer',
        'has_exception' => 'boolean',
        'reversed_movement_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \RuntimeException(
                'RNF-3: la bitácora de movimientos es inmutable. No se permite UPDATE. Emita una reversión.',
            );
        });

        static::deleting(static function (): void {
            throw new \RuntimeException(
                'RNF-3: la bitácora de movimientos es inmutable. No se permite DELETE.',
            );
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
