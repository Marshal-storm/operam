<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Documento / factura por cobrar (`invoices`) — Módulo 4 Paso 2.
 */
final class InvoicesModel extends Model
{
    protected $table = 'invoices';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'inv';

    /** @var list<string> */
    protected $fillable = [
        'folio',
        'contract_id',
        'client_id',
        'segment_id',
        'issuer_id',
        'request_id',
        'invoice_type',
        'concept',
        'currency',
        'subtotal',
        'tax_amount',
        'total',
        'paid_amount',
        'balance',
        'issue_date',
        'due_date',
        'status',
        'stamped_uuid',
        'notes',
        'created_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contract_id' => 'integer',
        'client_id' => 'integer',
        'segment_id' => 'integer',
        'issuer_id' => 'integer',
        'request_id' => 'integer',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
        'created_by_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentsModel::class, 'invoice_id');
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
