<?php

declare(strict_types=1);

/**
 * Carga `.env` de la raíz del proyecto en getenv / $_ENV (idempotente).
 * Usado por Phinx, Capsule Eloquent y Api/index.php.
 */
function operam_load_env(?string $rootPath = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $root = $rootPath ?? dirname(__DIR__, 2);
    $dotenvPath = $root . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($dotenvPath)) {
        $loaded = true;

        return;
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

    $loaded = true;
}
