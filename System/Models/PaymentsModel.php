<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago aplicado a factura (`payments`) — Módulo 4 Paso 2.
 */
final class PaymentsModel extends Model
{
    protected $table = 'payments';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'pay';

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'contract_id',
        'client_id',
        'amount',
        'payment_method',
        'reference',
        'payment_date',
        'notes',
        'recorded_by_user_id',
        'is_reversed',
        'reversed_at',
        'reversal_reason',
        'reversed_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'invoice_id' => 'integer',
        'contract_id' => 'integer',
        'client_id' => 'integer',
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'recorded_by_user_id' => 'integer',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'reversed_by_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(InvoicesModel::class, 'invoice_id');
    }
}
