<?php

declare(strict_types=1);

/**
 * Configuración Phinx 0.16+ (PHP 8.3).
 * Credenciales vía entorno — nunca hardcodear secretos.
 */

$dotenvPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_readable($dotenvPath)) {
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
}

/**
 * @return array{adapter:string,host:string,name:string,user:string,pass:string,port:string,charset:string,collation:string}
 */
/**
 * @return array{
 *   adapter:string,host:string,name:string,user:string,pass:string,port:string,
 *   charset:string,collation:string,timezone:string
 * }
 */
function operam_phinx_mysql_env(): array
{
    return [
        'adapter' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'operam',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
        'port' => getenv('DB_PORT') ?: '3306',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        // Obliga UTC en migraciones/seeders (RNF-11) — evita desfase vs Capsule +00:00.
        'timezone' => '+00:00',
    ];
}

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => operam_phinx_mysql_env(),
        'production' => operam_phinx_mysql_env(),
    ],
    'version_order' => 'creation',
];
