<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Emisor fiscal / legal (`issuers`) — RF-1.
 * Las refs IntegroPAY/ERP son nombres del cofre de secretos, no secretos en claro.
 */
final class IssuersModel extends Model
{
    protected $table = 'issuers';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'emi';

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
}
