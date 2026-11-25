<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Historial de otorgamientos/compras/ajustes de bolsa (`balance_movements`). */
final class BalanceMovementsModel extends Model
{
    protected $table = 'balance_movements';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'balmov';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'balance_id',
        'movement_type',
        'units',
        'request_id',
        'promotion_id',
        'notes',
        'created_by',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'balance_id' => 'integer',
        'units' => 'integer',
        'request_id' => 'integer',
        'promotion_id' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
    ];
}
