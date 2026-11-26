<?php

declare(strict_types=1);

/**
 * Worker de Outbox (Bloque C Paso 1) — despacha mensajes pendientes / fallidos con reintentos.
 *
 * Uso:  php scripts/run_outbox_worker.php [--limit=25] [--drain]
 *   --limit  Tamaño de lote por ciclo (1–200, default 25).
 *   --drain  Repite ciclos hasta que no queden mensajes vencidos (máx. 20 ciclos).
 *
 * Cloud Scheduler / cron sugerido (America/Mexico_City): cada 5 minutos.
 *   *\/5 * * * * php /ruta/operam/scripts/run_outbox_worker.php --drain
 * El modo sandbox/producción y el backoff se leen de Configuración → Servicios externos.
 */

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';
require_once $root . '/backend/bootstrap/env.php';
require_once $root . '/backend/bootstrap/eloquent.php';

operam_load_env();
operam_boot_eloquent();

use System\Support\Integrations\OutboxDispatcher;

$options = getopt('', ['limit::', 'drain']);
$limit = isset($options['limit']) ? max(1, min(200, (int) $options['limit'])) : 25;
$drain = array_key_exists('drain', $options);

fwrite(STDOUT, 'Operam — worker de outbox' . PHP_EOL);
fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);

$dispatcher = new OutboxDispatcher();
$totals = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'slack_alerts' => 0];
$cycles = 0;

try {
    do {
        $summary = $dispatcher->processPending($limit);
        foreach (array_keys($totals) as $key) {
            $totals[$key] += (int) $summary[$key];
        }
        $cycles++;
    } while ($drain && $summary['processed'] > 0 && $cycles < 20);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error del worker: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf(
    'Ciclos: %d | Procesados: %d | Enviados: %d | Fallidos: %d | Alertas Slack: %d%s',
    $cycles,
    $totals['processed'],
    $totals['sent'],
    $totals['failed'],
    $totals['slack_alerts'],
    PHP_EOL,
));
fwrite(STDOUT, 'Listo.' . PHP_EOL);
exit($totals['failed'] > 0 ? 2 : 0);
