<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipo de cambio diario por moneda (`exchange_rates`, fuente Banxico SIE). */
final class ExchangeRatesModel extends Model
{
    protected $table = 'exchange_rates';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'fxr';

    /** Registro inmutable por (rate_date, currency): sin `updated_at`. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'rate_date',
        'currency',
        'rate',
        'source',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rate_date' => 'date:Y-m-d',
        'rate' => 'decimal:4',
        'created_at' => 'datetime',
    ];
}
