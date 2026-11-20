<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Consolidado: secret_ref / IntegroPAY refs viven en create_dashboard (issuers).
 * Migración conservada como no-op para no romper la secuencia Phinx.
 */
final class AddEmisorSecurityRefs extends AbstractMigration
{
    public function change(): void
    {
        // Intencionalmente vacío.
    }
}
