<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Saldos por contrato + servicio + bolsa (`balances`) — RF-4. */
final class BalancesModel extends Model
{
    protected $table = 'balances';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'bal';

    /** @var list<string> */
    protected $fillable = [
        'contract_id',
        'service_id',
        'bucket',
        'period_start',
        'period_end',
        'granted_units',
        'consumed_units',
        'created_at',
        'updated_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contract_id' => 'integer',
        'service_id' => 'integer',
        'granted_units' => 'integer',
        'consumed_units' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
