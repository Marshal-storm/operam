<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Emisor fiscal / legal. Tabla física: `emisor`.
 */
final class Issuer extends BaseModel
{
    protected $table = 'emisor';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'rfc',
        'api_url',
        'activo',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'emisor_id');
    }
}
