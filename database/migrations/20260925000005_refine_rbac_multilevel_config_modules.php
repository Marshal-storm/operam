<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Consolidado: los submódulos config_* en inglés viven en el seeder RBAC.
 * Migración conservada como no-op para no romper la secuencia Phinx.
 */
final class RefineRbacMultilevelConfigModules extends AbstractMigration
{
    public function change(): void
    {
        // Intencionalmente vacío: esquema y catálogo de módulos se definen
        // en create_rbac + RbacRolesAndPermissionsSeeder (claves 100% inglés).
    }
}
