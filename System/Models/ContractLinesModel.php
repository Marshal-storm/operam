<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de contrato con precio congelado (`contract_lines`) — RNF-3.
 */
final class ContractLinesModel extends Model
{
    protected $table = 'contract_lines';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'ctl';

    /** @var list<string> */
    protected $fillable = [
        'contract_id',
        'service_id',
        'description',
        'service_type',
        'billing_frequency',
        'quantity',
        'unit_price',
        'discount_amount',
        'subtotal',
        'mrr_mxn',
        'is_package_converted',
        'active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contract_id' => 'integer',
        'service_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'mrr_mxn' => 'decimal:2',
        'is_package_converted' => 'boolean',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ContractsModel::class, 'contract_id');
    }
}
