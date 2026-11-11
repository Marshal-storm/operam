<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

/**
 * Arranque de Eloquent (Illuminate 11) con Capsule.
 * Compatible con PHP 8.3 — sin APIs deprecadas de Illuminate 8.
 *
 * @return Capsule
 */
function operam_boot_eloquent(): Capsule
{
    static $capsule = null;

    if ($capsule instanceof Capsule) {
        return $capsule;
    }

    $capsule = new Capsule();

    $capsule->addConnection([
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: 'operam',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => 'InnoDB',
        'timezone' => '+00:00', // Timestamps UTC (RNF-11)
    ]);

    $capsule->setEventDispatcher(new Dispatcher(new Container()));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    return $capsule;
}
