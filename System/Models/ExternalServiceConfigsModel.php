<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración global de servicios externos (`external_service_configs`) — RNF-8.
 * `secret_refs_json` solo contiene nombres de variables del cofre, nunca secretos.
 */
final class ExternalServiceConfigsModel extends Model
{
    protected $table = 'external_service_configs';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'extsvc';

    /** @var list<string> */
    protected $fillable = [
        'service_key',
        'display_name',
        'enabled',
        'mode',
        'config_json',
        'secret_refs_json',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'updated_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
        'config_json' => 'array',
        'secret_refs_json' => 'array',
        'updated_by_user_id' => 'integer',
        'last_tested_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
