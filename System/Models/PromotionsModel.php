<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Promociones y códigos de descuento (`promotions`). */
final class PromotionsModel extends Model
{
    protected $table = 'promotions';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'prm';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'segment_id',
        'discount_type',
        'discount_value',
        'applies_to',
        'valid_from',
        'valid_until',
        'max_uses',
        'current_uses',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segment_id' => 'integer',
        'discount_value' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'max_uses' => 'integer',
        'current_uses' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
