<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente comercial. Tabla física: `cliente`.
 */
final class Client extends BaseModel
{
    protected $table = 'cliente';

    /** @var list<string> */
    protected $fillable = [
        'emisor_id',
        'erp_cliente_id',
        'nombre',
        'rfc',
        'razon_social',
        'dias_credito',
        'timbrar_al_emitir',
        'excluir_suspension',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'emisor_id' => 'integer',
        'dias_credito' => 'integer',
        'timbrar_al_emitir' => 'boolean',
        'excluir_suspension' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class, 'emisor_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'cliente_id');
    }
}
