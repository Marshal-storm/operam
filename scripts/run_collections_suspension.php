<?php

declare(strict_types=1);

/**
 * CLI nocturno — regla de suspensión automática a +90 días (Módulo 4 Paso 2).
 *
 * Uso: php scripts/run_collections_suspension.php
 * Cron sugerido (America/Mexico_City): 0 2 * * * php /ruta/operam/scripts/run_collections_suspension.php
 */

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';
require_once $root . '/backend/bootstrap/env.php';
require_once $root . '/backend/bootstrap/eloquent.php';

operam_load_env();
operam_boot_eloquent();

use System\Services\CollectionsService;

fwrite(STDOUT, 'Operam — evaluación de suspensiones por morosidad (+90 días)' . PHP_EOL);
fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);

$service = new CollectionsService();
// Contexto sistema (sin JWT): alcance Todos.
$result = $service->runSuspensionCheck([]);

if (!$result->isSuccess()) {
    fwrite(STDERR, 'Error: ' . ($result->getMessage() ?? 'falló la evaluación') . PHP_EOL);
    exit(1);
}

/** @var array<string, mixed> $data */
$data = $result->getData() ?? [];
fwrite(STDOUT, sprintf(
    "Evaluados: %d | Suspendidos: %d | Excluidos (protegidos): %d | Corte: %s%s",
    (int) ($data['evaluated_count'] ?? 0),
    (int) ($data['suspended_count'] ?? 0),
    (int) ($data['excluded_count'] ?? 0),
    (string) ($data['as_of'] ?? ''),
    PHP_EOL,
));

$folios = $data['suspended_folios'] ?? [];
if (is_array($folios) && $folios !== []) {
    fwrite(STDOUT, 'Folios suspendidos: ' . implode(', ', $folios) . PHP_EOL);
}

fwrite(STDOUT, 'Listo.' . PHP_EOL);
exit(0);
