<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contrato / suscripción (`contracts`) — Módulo 3 Paso 2.
 */
final class ContractsModel extends Model
{
    protected $table = 'contracts';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'ctr';

    /** @var list<string> */
    protected $fillable = [
        'folio',
        'client_id',
        'segment_id',
        'issuer_id',
        'instance_id',
        'price_list_id',
        'promotion_id',
        'frequency',
        'anniversary_date',
        'currency',
        'exchange_rate',
        'credit_days',
        'stamp_on_issue',
        'status',
        'mrr_mxn',
        'next_cutoff_date',
        'origin_contract_id',
        'termination_reason',
        'notes',
        'created_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'client_id' => 'integer',
        'segment_id' => 'integer',
        'issuer_id' => 'integer',
        'instance_id' => 'integer',
        'price_list_id' => 'integer',
        'promotion_id' => 'integer',
        'credit_days' => 'integer',
        'stamp_on_issue' => 'boolean',
        'exchange_rate' => 'decimal:4',
        'mrr_mxn' => 'decimal:2',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ContractLinesModel::class, 'contract_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientsModel::class, 'client_id');
    }
}
