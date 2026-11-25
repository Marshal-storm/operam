<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Costos operativos mensuales por segmento (`segment_costs`) — RF-10.
 */
final class SegmentCostsModel extends Model
{
    protected $table = 'segment_costs';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'scost';

    /** @var list<string> */
    protected $fillable = [
        'segment_id',
        'month',
        'category',
        'concept',
        'amount',
        'notes',
        'created_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segment_id' => 'integer',
        'amount' => 'decimal:2',
        'created_by_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
