<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Catálogo de productos/servicios (`services`). */
final class ServicesCatalogModel extends Model
{
    protected $table = 'services';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'svc';

    /** @var list<string> */
    protected $fillable = [
        'segment_id',
        'default_issuer_id',
        'code',
        'name',
        'description',
        'service_type',
        'unit_of_measure',
        'allow_package_conversion',
        'package_service_id',
        'package_factor',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segment_id' => 'integer',
        'default_issuer_id' => 'integer',
        'allow_package_conversion' => 'boolean',
        'package_service_id' => 'integer',
        'package_factor' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
