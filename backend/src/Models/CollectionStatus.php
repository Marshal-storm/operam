<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de cobranza por contrato. Tabla física: `cobranza_estado`.
 */
final class CollectionStatus extends BaseModel
{
    protected $table = 'cobranza_estado';

    protected $primaryKey = 'contrato_id';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'contrato_id',
        'saldo_vencido',
        'dias_vencido',
        'nivel_aviso',
        'suspendido',
        'actualizado',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contrato_id' => 'integer',
        'saldo_vencido' => 'decimal:2',
        'dias_vencido' => 'integer',
        'nivel_aviso' => 'integer',
        'suspendido' => 'boolean',
        'actualizado' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contrato_id');
    }
}
