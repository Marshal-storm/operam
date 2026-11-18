<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora inmutable de MRR (RNF-3).
 * Solo INSERT (o INSERT de reversión). UPDATE/DELETE bloqueados aquí y por triggers MySQL.
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
        'movimiento_revertido_id',
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
        'movimiento_revertido_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \RuntimeException(
                'RNF-3: movimiento es inmutable. No se permite UPDATE. Emita una reversión.',
            );
        });

        static::deleting(static function (): void {
            throw new \RuntimeException(
                'RNF-3: movimiento es inmutable. No se permite DELETE.',
            );
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contrato_id');
    }
}
