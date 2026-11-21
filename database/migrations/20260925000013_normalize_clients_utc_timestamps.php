<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Corrige timestamps de `clients` escritos vía Phinx con sesión MySQL SYSTEM
 * (America/Mexico_City): literales de gmdate() quedaron por delante de UTC real,
 * por lo que altas/ediciones API (UTC) no subían a la primera fila (§3.4).
 */
final class NormalizeClientsUtcTimestamps extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("SET time_zone = '+00:00'");

        // Recorta a UTC_TIMESTAMP cualquier marca “futura” respecto al reloj UTC.
        $this->execute(
            'UPDATE clients
             SET
               created_at = LEAST(created_at, UTC_TIMESTAMP()),
               updated_at = LEAST(updated_at, UTC_TIMESTAMP())
             WHERE created_at > UTC_TIMESTAMP()
                OR updated_at > UTC_TIMESTAMP()'
        );
    }

    public function down(): void
    {
        // Irreversible de forma segura: no re-inflamos timestamps.
    }
}
