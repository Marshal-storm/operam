<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Segmento de producto (`segments`) — RF-1.
 * `default_credit_days` se hereda al alta de clientes y contratos.
 */
final class SegmentsModel extends Model
{
    protected $table = 'segments';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'seg';

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
}
