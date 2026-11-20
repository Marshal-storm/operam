<?php
/**
 * Resetea la BD local: DROP de tablas legacy (español) y actuales (inglés),
 * luego deja listo para `vendor/bin/phinx migrate` + `seed:run`.
 *
 * Uso: php database/scripts/reset_english_schema.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dotenvPath = $root . DIRECTORY_SEPARATOR . '.env';

if (is_readable($dotenvPath)) {
    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        $parts = explode('=', $trimmed, 2);
        $name = trim($parts[0]);
        $value = trim($parts[1] ?? '', " \t\"'");
        if ($name !== '' && getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: 'operam';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $exception) {
    fwrite(STDERR, 'No se pudo conectar a MySQL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$tables = [
    // Legacy español
    'rol_permiso',
    'modulo_sistema',
    'rol',
    'lista_precio_item',
    'lista_precio',
    'promocion',
    'instancia',
    'servicio',
    'auth_intento',
    'sesion_activa',
    'usuario',
    'mrr_mensual',
    'cobranza_estado',
    'movimiento',
    'contrato',
    'cliente',
    'emisor',
    'segmento',
    // Inglés actual
    'audit_logs',
    'system_settings',
    'role_permissions',
    'system_modules',
    'roles',
    'price_list_items',
    'price_lists',
    'promotions',
    'instances',
    'services',
    'auth_attempts',
    'active_sessions',
    'users',
    'monthly_mrr',
    'collection_statuses',
    'movements',
    'contracts',
    'clients',
    'issuers',
    'segments',
    'phinxlog',
];

$triggers = [
    'trg_movimiento_prevent_update',
    'trg_movimiento_prevent_delete',
    'trg_movements_prevent_update',
    'trg_movements_prevent_delete',
    'trg_audit_logs_prevent_update',
    'trg_audit_logs_controlled_delete',
];

echo "Reseteando esquema de `{$name}`..." . PHP_EOL;

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

foreach ($triggers as $trigger) {
    $pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
}

foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    echo "  DROP TABLE IF EXISTS {$table}" . PHP_EOL;
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo 'Listo. Ejecuta: vendor/bin/phinx migrate && vendor/bin/phinx seed:run' . PHP_EOL;
