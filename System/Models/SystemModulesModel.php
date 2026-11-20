<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

final class SystemModulesModel extends Model
{
    protected $table = 'system_modules';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'modsys';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'module_key',
        'name',
        'group_key',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'sort_order' => 'integer',
    ];
}
