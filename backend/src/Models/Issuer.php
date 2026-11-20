<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Emisor fiscal / legal. Tabla física: `issuers`.
 */
final class Issuer extends BaseModel
{
    protected $table = 'issuers';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'rfc',
        'api_url',
        'secret_ref',
        'integropay_merchant_token_ref',
        'integropay_device_token_ref',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'issuer_id');
    }
}
