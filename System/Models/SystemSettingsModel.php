<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Configuración global del sistema (`system_settings`). */
final class SystemSettingsModel extends Model
{
    protected $table = 'system_settings';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'sysset';

    /** @var list<string> */
    protected $fillable = [
        'setting_key',
        'setting_value',
        'value_type',
        'description',
        'updated_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
