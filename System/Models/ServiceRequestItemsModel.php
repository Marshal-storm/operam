<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de solicitud (`service_request_items`) — Módulo 4 Paso 1.
 */
final class ServiceRequestItemsModel extends Model
{
    protected $table = 'service_request_items';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'sri';

    /** @var list<string> */
    protected $fillable = [
        'service_request_id',
        'service_id',
        'service_type',
        'quantity',
        'unit_price',
        'subtotal',
        'mrr_impact_mxn',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'service_request_id' => 'integer',
        'service_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'mrr_impact_mxn' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequestsModel::class, 'service_request_id');
    }
}
