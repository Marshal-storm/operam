<?php

declare(strict_types=1);

namespace Operam\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo base Eloquent (Illuminate 11 / PHP 8.3).
 * Usa $casts — nunca el antiguo $dates de Illuminate 8.
 */
abstract class BaseModel extends Model
{
    /** @var string */
    protected $connection = 'default';

    /**
     * Timestamps en UTC (RNF-11). Las fechas de corte de negocio
     * se interpretan en America/Mexico_City en la capa de dominio.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
