<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente comercial. Tabla física: `clients`.
 */
final class Client extends BaseModel
{
    protected $table = 'clients';

    /** @var list<string> */
    protected $fillable = [
        'issuer_id',
        'erp_client_id',
        'name',
        'rfc',
        'legal_name',
        'credit_days',
        'stamp_on_issue',
        'exclude_suspension',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'issuer_id' => 'integer',
        'credit_days' => 'integer',
        'stamp_on_issue' => 'boolean',
        'exclude_suspension' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class, 'issuer_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'client_id');
    }
}
