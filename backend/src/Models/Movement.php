<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora inmutable de MRR (RNF-3).
 * La aplicación solo debe INSERTAR; no actualizar ni borrar.
 * Tabla física: `movimiento`.
 */
final class Movement extends BaseModel
{
    protected $table = 'movimiento';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'contrato_id',
        'contrato_linea_id',
        'servicio_id',
        'tipo',
        'categoria_mrr',
        'fecha_efectiva',
        'cantidad_antes',
        'cantidad_despues',
        'mrr_antes',
        'mrr_despues',
        'mrr_delta_mxn',
        'tipo_cambio',
        'usuario_id',
        'solicitud_id',
        'motivo',
        'con_excepcion',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contrato_id' => 'integer',
        'contrato_linea_id' => 'integer',
        'servicio_id' => 'integer',
        'fecha_efectiva' => 'date',
        'cantidad_antes' => 'decimal:2',
        'cantidad_despues' => 'decimal:2',
        'mrr_antes' => 'decimal:2',
        'mrr_despues' => 'decimal:2',
        'mrr_delta_mxn' => 'decimal:2',
        'tipo_cambio' => 'decimal:6',
        'usuario_id' => 'integer',
        'solicitud_id' => 'integer',
        'con_excepcion' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contrato_id');
    }
}
