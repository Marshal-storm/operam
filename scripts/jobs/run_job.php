<?php

declare(strict_types=1);

/**
 * Entrada CLI de los 4 Jobs oficiales para Cloud Run Jobs (Bloque C Paso 2 · Pág. 24 del PDF).
 *
 * Uso:  php scripts/jobs/run_job.php <daily_billing|reset_included_balances|daily_collections|process_outbox|all>
 *
 * Cada corrida queda en `job_runs` con trigger_source = 'cli'. Códigos de salida:
 *   0 = todos los jobs completados · 1 = algún job falló o argumento inválido.
 * Zona horaria de negocio y parámetros operativos: Configuración → Servicios externos (jobs_outbox).
 */

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';
require_once $root . '/backend/bootstrap/env.php';
require_once $root . '/backend/bootstrap/eloquent.php';

operam_load_env();
operam_boot_eloquent();

use System\Support\Jobs\JobsRunner;

$jobName = strtolower(trim((string) ($argv[1] ?? '')));
$allowed = [...JobsRunner::JOB_NAMES, 'all'];
if (!in_array($jobName, $allowed, true)) {
    fwrite(STDERR, 'Uso: php scripts/jobs/run_job.php <' . implode('|', $allowed) . '>' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Operam — Jobs de Google Cloud (CLI)' . PHP_EOL);
fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);

$identity = ['name' => 'Cloud Run Job (CLI)'];
$runner = new JobsRunner();

try {
    $runs = $jobName === 'all'
        ? $runner->runAll('cli', $identity)
        : [$runner->run($jobName, 'cli', $identity)];
} catch (Throwable $e) {
    fwrite(STDERR, 'Error al ejecutar el job: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$hasFailures = false;
foreach ($runs as $run) {
    $status = (string) ($run['status'] ?? 'failed');
    $hasFailures = $hasFailures || $status !== 'completed';
    fwrite(STDOUT, sprintf(
        '[%s] %-24s procesados: %d · duración: %ss%s%s',
        strtoupper($status),
        (string) $run['job_name'],
        (int) ($run['processed_count'] ?? 0),
        (string) ($run['duration_seconds'] ?? '—'),
        isset($run['error_message']) && $run['error_message'] !== null ? ' · error: ' . $run['error_message'] : '',
        PHP_EOL,
    ));
    if (isset($run['summary']) && is_array($run['summary'])) {
        fwrite(STDOUT, json_encode($run['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}

fwrite(STDOUT, 'Listo.' . PHP_EOL);
exit($hasFailures ? 1 : 0);
