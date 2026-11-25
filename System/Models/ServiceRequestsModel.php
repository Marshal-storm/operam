<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud de servicio (`service_requests`) — Módulo 4 Paso 1.
 */
final class ServiceRequestsModel extends Model
{
    protected $table = 'service_requests';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'srq';

    /** @var list<string> */
    protected $fillable = [
        'folio',
        'contract_id',
        'client_id',
        'segment_id',
        'issuer_id',
        'origin',
        'request_type',
        'status',
        'currency',
        'subtotal',
        'mrr_impact_mxn',
        'notes',
        'resolution_notes',
        'with_exception',
        'exception_policy',
        'overdue_balance_at_exception',
        'requested_by_user_id',
        'requested_by_name',
        'resolved_by_user_id',
        'resolved_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contract_id' => 'integer',
        'client_id' => 'integer',
        'segment_id' => 'integer',
        'issuer_id' => 'integer',
        'subtotal' => 'decimal:2',
        'mrr_impact_mxn' => 'decimal:2',
        'with_exception' => 'boolean',
        'overdue_balance_at_exception' => 'decimal:2',
        'requested_by_user_id' => 'integer',
        'resolved_by_user_id' => 'integer',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ServiceRequestItemsModel::class, 'service_request_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ContractsModel::class, 'contract_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientsModel::class, 'client_id');
    }
}
