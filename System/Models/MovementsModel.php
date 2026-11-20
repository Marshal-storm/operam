<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Bitácora inmutable de MRR (`movements`) — RNF-3.
 * Solo INSERT (o INSERT de reversión). UPDATE/DELETE bloqueados en Eloquent y por triggers MySQL.
 */
final class MovementsModel extends Model
{
    protected $table = 'movements';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'mov';

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
            throw new RuntimeException(
                'RNF-3: la bitácora de movimientos es inmutable. No se permite UPDATE. Emita una reversión contable.',
            );
        });

        static::deleting(static function (): void {
            throw new RuntimeException(
                'RNF-3: la bitácora de movimientos es inmutable. No se permite DELETE.',
            );
        });
    }
}
