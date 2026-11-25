<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Bitácora inmutable de consumos (`consumptions`) — RNF-3.
 * Solo INSERT. UPDATE/DELETE bloqueados en Eloquent y por triggers MySQL.
 */
final class ConsumptionsModel extends Model
{
    protected $table = 'consumptions';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'cns';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'balance_id',
        'contract_id',
        'service_id',
        'units',
        'external_reference',
        'instance_id',
        'user_id',
        'notes',
        'consumed_at',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'balance_id' => 'integer',
        'contract_id' => 'integer',
        'service_id' => 'integer',
        'units' => 'integer',
        'instance_id' => 'integer',
        'user_id' => 'integer',
        'consumed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException(
                'RNF-3: la bitácora de consumos es inmutable. No se permite UPDATE.',
            );
        });

        static::deleting(static function (): void {
            throw new RuntimeException(
                'RNF-3: la bitácora de consumos es inmutable. No se permite DELETE.',
            );
        });
    }
}
