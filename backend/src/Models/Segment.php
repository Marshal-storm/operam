<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Segmento de producto. Tabla física: `segmento`.
 */
final class Segment extends BaseModel
{
    protected $table = 'segmento';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'dias_credito_default',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'dias_credito_default' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'segmento_id');
    }
}
