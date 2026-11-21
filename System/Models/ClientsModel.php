<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cliente del directorio comercial (`clients`) — Módulo 3 Paso 1.
 */
final class ClientsModel extends Model
{
    protected $table = 'clients';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'cli';

    /** Formato explícito UTC (RNF-11) — evita drift de Carbon/locale. */
    protected $dateFormat = 'Y-m-d H:i:s';

    /** @var list<string> */
    protected $fillable = [
        'segment_id',
        'issuer_id',
        'erp_client_id',
        'name',
        'legal_name',
        'rfc',
        'contact_name',
        'contact_email',
        'contact_phone',
        'credit_days',
        'stamp_on_issue',
        'exclude_suspension',
        'active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'segment_id' => 'integer',
        'issuer_id' => 'integer',
        'credit_days' => 'integer',
        'stamp_on_issue' => 'boolean',
        'exclude_suspension' => 'boolean',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // Fuerza marcas UTC en create/update (también toggles) para el orden §3.4.
        static::creating(static function (self $model): void {
            $now = gmdate('Y-m-d H:i:s');
            $model->setAttribute('created_at', $now);
            $model->setAttribute('updated_at', $now);
        });

        static::updating(static function (self $model): void {
            $model->setAttribute('updated_at', gmdate('Y-m-d H:i:s'));
        });
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(SegmentsModel::class, 'segment_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(IssuersModel::class, 'issuer_id');
    }
}
