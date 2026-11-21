<?php

declare(strict_types=1);

/**
 * Limpieza segura de datos operativos/comerciales (solo desarrollo local).
 *
 * Conserva: users, roles, system_modules, role_permissions, active_sessions,
 * auth_attempts, segments, system_settings, phinxlog.
 *
 * Uso: php scripts/reset_operational_data.php
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

/**
 * Orden hijo → padre para minimizar fricción de FKs.
 * TRUNCATE reinicia AUTO_INCREMENT a 1 y NO dispara triggers DELETE
 * (los triggers RNF-3 de inmutabilidad permanecen en la definición de la tabla).
 *
 * Conservadas intactas (no aparecen aquí): users, roles, system_modules,
 * role_permissions, active_sessions, auth_attempts, segments, system_settings, phinxlog.
 */
$truncate = [
    'contract_lines',
    'movements',
    'monthly_mrr',
    'collection_statuses',
    'contracts',
    'clients',
    'price_list_items',
    'price_lists',
    'promotions',
    'services',
    'instances',
    'issuers',
    'audit_logs',
];

$requiredTriggers = [
    'trg_movements_prevent_update',
    'trg_movements_prevent_delete',
    'trg_audit_logs_prevent_update',
    'trg_audit_logs_controlled_delete',
];

fwrite(STDOUT, 'Operam — reset de datos operativos/comerciales' . PHP_EOL);
fwrite(STDOUT, sprintf('Base: %s@%s:%s/%s' . PHP_EOL, $user, $host, $port, $name));
fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($truncate as $table) {
        assertTableExists($pdo, $table);
        $pdo->exec(sprintf('TRUNCATE TABLE `%s`', $table));
        fwrite(STDOUT, sprintf("  TRUNCATE `%s` … OK\n", $table));
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
} catch (Throwable $e) {
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable) {
        // ignore
    }
    fwrite(STDERR, 'Error durante TRUNCATE: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);
fwrite(STDOUT, 'Verificando triggers de inmutabilidad…' . PHP_EOL);

$missing = assertTriggersExist($pdo, $name, $requiredTriggers);
if ($missing !== []) {
    fwrite(STDERR, 'Faltan triggers: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

try {
    verifyTriggersStillWork($pdo);
    fwrite(STDOUT, "  Triggers activos y funcionando (UPDATE/DELETE bloqueados).\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Falló la verificación funcional de triggers: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);
fwrite(STDOUT, "Resumen de conteos:\n");

$allOk = true;
foreach ($truncate as $table) {
    $count = tableCount($pdo, $table);
    $mark = $count === 0 ? 'OK' : 'FAIL';
    if ($count !== 0) {
        $allOk = false;
    }
    fwrite(STDOUT, sprintf("  %-22s %6d  [%s]\n", $table, $count, $mark));
}

fwrite(STDOUT, "  --- conservadas ---\n");
foreach (['users', 'roles', 'segments', 'system_modules', 'role_permissions', 'system_settings', 'phinxlog'] as $table) {
    if (!tableExists($pdo, $table)) {
        fwrite(STDOUT, sprintf("  %-22s   n/a  [SKIP]\n", $table));
        continue;
    }
    $count = tableCount($pdo, $table);
    fwrite(STDOUT, sprintf("  %-22s %6d  [KEEP]\n", $table, $count));
}

fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);

if (!$allOk) {
    fwrite(STDERR, "Reset incompleto: alguna tabla operativa aún tiene filas.\n");
    exit(1);
}

fwrite(STDOUT, "Listo. BD limpia para pruebas E2E (auth/RBAC/segmentos intactos).\n");
exit(0);

/**
 * @param list<string> $expected
 * @return list<string> nombres faltantes
 */
function assertTriggersExist(PDO $pdo, string $schema, array $expected): array
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
    foreach ($expected as $name) {
        if (!isset($foundMap[$name])) {
            $missing[] = $name;
        }
    }

    return $missing;
}

/**
 * Prueba funcional: UPDATE/DELETE deben fallar; triggers no se eliminaron con TRUNCATE.
 * Limpia filas de prueba con TRUNCATE (no dispara DELETE) o purge controlado.
 */
function verifyTriggersStillWork(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    // --- movements (RNF-3) ---
    $pdo->exec(
        "INSERT INTO `movements` (
            `contract_id`, `movement_type`, `mrr_category`, `effective_date`,
            `quantity_before`, `quantity_after`, `mrr_before`, `mrr_after`, `mrr_delta_mxn`,
            `exchange_rate`, `reason`
         ) VALUES (
            1, 'probe_reset', 'new', CURDATE(),
            0.00, 0.00, 0.00, 0.00, 0.00,
            1.000000, 'reset_operational_data probe'
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

    // Limpia sonda sin disparar trigger DELETE (TRUNCATE no invoca BEFORE DELETE).
    $pdo->exec('TRUNCATE TABLE `movements`');

    // --- audit_logs ---
    $pdo->exec(
        "INSERT INTO `audit_logs` (
            `module_key`, `entity_type`, `entity_id`, `action`, `description`, `ip_address`
         ) VALUES (
            'config_system', 'probe', '0', 'purge_logs', 'reset_operational_data probe', '127.0.0.1'
         )"
    );
    $auditId = (int) $pdo->lastInsertId();
    if ($auditId < 1) {
        throw new RuntimeException('No se pudo insertar fila sonda en audit_logs.');
    }

    $blockedAuditUpdate = false;
    try {
        $pdo->exec(sprintf(
            'UPDATE `audit_logs` SET `description` = \'tamper\' WHERE `id` = %d',
            $auditId
        ));
    } catch (PDOException) {
        $blockedAuditUpdate = true;
    }
    if (!$blockedAuditUpdate) {
        throw new RuntimeException('trg_audit_logs_prevent_update no bloqueó UPDATE.');
    }

    $pdo->exec('SET @allow_audit_purge = 0');
    $blockedAuditDelete = false;
    try {
        $pdo->exec(sprintf('DELETE FROM `audit_logs` WHERE `id` = %d', $auditId));
    } catch (PDOException) {
        $blockedAuditDelete = true;
    }
    if (!$blockedAuditDelete) {
        throw new RuntimeException('trg_audit_logs_controlled_delete no bloqueó DELETE sin purge.');
    }

    // Depuración controlada para dejar la tabla en 0 otra vez.
    $pdo->exec('SET @allow_audit_purge = 1');
    $pdo->exec('DELETE FROM `audit_logs`');
    $pdo->exec('SET @allow_audit_purge = 0');
    // Reinicia AUTO_INCREMENT tras el DELETE de sonda.
    $pdo->exec('TRUNCATE TABLE `audit_logs`');

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function assertTableExists(PDO $pdo, string $table): void
{
    if (!tableExists($pdo, $table)) {
        throw new RuntimeException("La tabla `{$table}` no existe en la base.");
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

function tableCount(PDO $pdo, string $table): int
{
    $stmt = $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table));

    return (int) $stmt->fetchColumn();
}
