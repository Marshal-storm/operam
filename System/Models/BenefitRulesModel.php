<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Reglas de beneficio RF-2.6 (`benefit_rules`). */
final class BenefitRulesModel extends Model
{
    protected $table = 'benefit_rules';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'brl';

    /** @var list<string> */
    protected $fillable = [
        'source_service_id',
        'benefit_service_id',
        'units_per_unit',
        'reset_period',
        'is_cumulative',
        'active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'source_service_id' => 'integer',
        'benefit_service_id' => 'integer',
        'units_per_unit' => 'integer',
        'is_cumulative' => 'boolean',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
