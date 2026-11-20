<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Consolidado: config_issuers / config_instances se siembran en RBAC seeder.
 * Migración conservada como no-op para no romper la secuencia Phinx.
 */
final class SplitConfigEmisoresInstancias extends AbstractMigration
{
    public function change(): void
    {
        // Intencionalmente vacío.
    }
}
