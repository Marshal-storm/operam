<?php

declare(strict_types=1);

/**
 * Limpieza SOLO de tablas de contratos (desarrollo / pruebas limpias del Módulo 3).
 *
 * Vacía: contract_lines, movements, monthly_mrr, collection_statuses, contracts.
 * Restaura: promotions.current_uses = 0.
 *
 * NO TOCA: clients, services, price_lists, price_list_items, promotions (filas),
 * instances, issuers, segments, users, roles, system_settings, audit_logs.
 *
 * Uso: php scripts/reset_contracts_only.php
 */

$root = dirname(__DIR__);
$dotenvPath = $root . DIRECTORY_SEPARATOR . '.env';

if (!is_readable($dotenvPath)) {
    fwrite(STDERR, "No se encontró .env en {$root}" . PHP_EOL);
    exit(1);
}

$lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
        continue;
    }
    [$name, $value] = array_map(trim(...), explode('=', $trimmed, 2));
    $value = trim($value, " \t\"'");
    if ($name !== '' && getenv($name) === false) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
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
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $exception) {
    fwrite(STDERR, 'No se pudo conectar a MySQL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** Orden hijo → padre. TRUNCATE no dispara triggers DELETE (RNF-3 intacto). */
$truncate = [
    'contract_lines',
    'movements',
    'monthly_mrr',
    'collection_statuses',
    'contracts',
];

$requiredTriggers = [
    'trg_movements_prevent_update',
    'trg_movements_prevent_delete',
];

$untouched = [
    'clients',
    'services',
    'price_lists',
    'price_list_items',
    'promotions',
    'instances',
    'issuers',
    'segments',
    'users',
    'roles',
    'system_settings',
    'audit_logs',
];

fwrite(STDOUT, 'Operam — reset SOLO tablas de contratos' . PHP_EOL);
fwrite(STDOUT, sprintf('Base: %s@%s:%s/%s' . PHP_EOL, $user, $host, $port, $name));
fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);

$beforeUntouched = [];
foreach ($untouched as $table) {
    if (contractsResetTableExists($pdo, $table)) {
        $beforeUntouched[$table] = contractsResetTableCount($pdo, $table);
    }
}

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($truncate as $table) {
        contractsResetAssertTableExists($pdo, $table);
        $pdo->exec(sprintf('TRUNCATE TABLE `%s`', $table));
        fwrite(STDOUT, sprintf("  TRUNCATE `%s` … OK\n", $table));
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Restaura contadores de cupones sin borrar el catálogo promotions.
    if (contractsResetTableExists($pdo, 'promotions')) {
        $updated = $pdo->exec('UPDATE `promotions` SET `current_uses` = 0');
        fwrite(STDOUT, sprintf("  UPDATE promotions.current_uses = 0 … OK (%d filas)\n", (int) $updated));
    }
} catch (Throwable $e) {
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable) {
        // ignore
    }
    fwrite(STDERR, 'Error durante la limpieza: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);
fwrite(STDOUT, "Verificando triggers RNF-3 de movements…\n");

$missing = contractsResetAssertTriggersExist($pdo, $name, $requiredTriggers);
if ($missing !== []) {
    fwrite(STDERR, 'Faltan triggers: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

try {
    contractsResetVerifyMovementsTriggers($pdo);
    fwrite(STDOUT, "  Triggers activos y funcionando (UPDATE/DELETE bloqueados).\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Falló la verificación funcional de triggers: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);
fwrite(STDOUT, "Conteos vaciados:\n");

$allOk = true;
foreach ($truncate as $table) {
    $count = contractsResetTableCount($pdo, $table);
    $mark = $count === 0 ? 'OK' : 'FAIL';
    if ($count !== 0) {
        $allOk = false;
    }
    fwrite(STDOUT, sprintf("  %-22s %6d  [%s]\n", $table, $count, $mark));
}

fwrite(STDOUT, "  --- intactas (no tocadas) ---\n");
foreach ($untouched as $table) {
    if (!isset($beforeUntouched[$table])) {
        fwrite(STDOUT, sprintf("  %-22s   n/a  [SKIP]\n", $table));
        continue;
    }
    $after = contractsResetTableCount($pdo, $table);
    $before = $beforeUntouched[$table];
    $mark = $after === $before ? 'KEEP' : 'CHANGED!';
    if ($after !== $before) {
        $allOk = false;
    }
    fwrite(STDOUT, sprintf("  %-22s %6d  [%s] (antes %d)\n", $table, $after, $mark, $before));
}

fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);

if (!$allOk) {
    fwrite(STDERR, "Reset incompleto o tablas protegidas fueron alteradas.\n");
    exit(1);
}

fwrite(STDOUT, "Listo. Contratos limpios; catálogos/clientes/auth intactos; cupones con current_uses=0.\n");
exit(0);

/**
 * @param list<string> $expected
 * @return list<string>
 */
function contractsResetAssertTriggersExist(PDO $pdo, string $schema, array $expected): array
{
    $placeholders = implode(',', array_fill(0, count($expected), '?'));
    $stmt = $pdo->prepare(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = ?
           AND TRIGGER_NAME IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$schema], $expected));
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $foundMap = array_fill_keys(array_map('strval', $found), true);

    $missing = [];
    foreach ($expected as $triggerName) {
        if (!isset($foundMap[$triggerName])) {
            $missing[] = $triggerName;
        }
    }

    return $missing;
}

function contractsResetVerifyMovementsTriggers(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $pdo->exec(
        "INSERT INTO `movements` (
            `contract_id`, `movement_type`, `mrr_category`, `effective_date`,
            `quantity_before`, `quantity_after`, `mrr_before`, `mrr_after`, `mrr_delta_mxn`,
            `exchange_rate`, `reason`
         ) VALUES (
            1, 'probe_reset', 'new', CURDATE(),
            0.00, 0.00, 0.00, 0.00, 0.00,
            1.000000, 'reset_contracts_only probe'
         )"
    );
    $movId = (int) $pdo->lastInsertId();
    if ($movId < 1) {
        throw new RuntimeException('No se pudo insertar fila sonda en movements.');
    }

    $blockedUpdate = false;
    try {
        $pdo->exec(sprintf('UPDATE `movements` SET `reason` = \'tamper\' WHERE `id` = %d', $movId));
    } catch (PDOException) {
        $blockedUpdate = true;
    }
    if (!$blockedUpdate) {
        throw new RuntimeException('trg_movements_prevent_update no bloqueó UPDATE.');
    }

    $blockedDelete = false;
    try {
        $pdo->exec(sprintf('DELETE FROM `movements` WHERE `id` = %d', $movId));
    } catch (PDOException) {
        $blockedDelete = true;
    }
    if (!$blockedDelete) {
        throw new RuntimeException('trg_movements_prevent_delete no bloqueó DELETE.');
    }

    // Limpia sonda sin disparar BEFORE DELETE.
    $pdo->exec('TRUNCATE TABLE `movements`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function contractsResetAssertTableExists(PDO $pdo, string $table): void
{
    if (!contractsResetTableExists($pdo, $table)) {
        throw new RuntimeException("La tabla `{$table}` no existe en la base.");
    }
}

function contractsResetTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

function contractsResetTableCount(PDO $pdo, string $table): int
{
    $stmt = $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table));

    return (int) $stmt->fetchColumn();
}
