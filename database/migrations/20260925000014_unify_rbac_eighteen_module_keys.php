<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Unifica el catálogo RBAC a 18 module_key en inglés.
 * Renombra `segment_utility` → `segment_profitability` y limpia llaves legacy
 * (español o monolíticas) sin perder permisos de roles cuando hay mapeo 1:1.
 */
final class UnifyRbacEighteenModuleKeys extends AbstractMigration
{
    /** @var array<string, string> Llave antigua → llave canónica. */
    private const KEY_ALIASES = [
        'segment_utility' => 'segment_profitability',
        'utilidad_segmento' => 'segment_profitability',
        'segment_profit' => 'segment_profitability',
        'contratos' => 'contracts',
        'clientes' => 'clients',
        'solicitudes' => 'requests',
        'cobranza' => 'collections',
        'promociones_ventas' => 'sales_promotions',
        'config_catalogo' => 'config_catalog',
        'config_precios' => 'config_prices',
        'config_promociones' => 'config_promotions',
        'config_emisores' => 'config_issuers',
        'config_instancias' => 'config_instances',
        'config_usuarios' => 'config_users',
        'config_roles_permisos' => 'config_roles',
        'config_empresa' => 'config_company',
        'config_sistema' => 'config_system',
        'config_auditoria' => 'config_audit_logs',
        'config_preferencias' => 'config_preferences',
        'configuracion' => 'config_preferences',
    ];

    /** @var list<array{module_key: string, name: string, group_key: string, sort_order: int}> */
    private const CANONICAL_MODULES = [
        ['module_key' => 'dashboard', 'name' => 'Dashboard y Puente de MRR', 'group_key' => 'main', 'sort_order' => 10],
        ['module_key' => 'contracts', 'name' => 'Contratos y ciclo de vida', 'group_key' => 'main', 'sort_order' => 20],
        ['module_key' => 'clients', 'name' => 'Directorio de clientes', 'group_key' => 'main', 'sort_order' => 30],
        ['module_key' => 'requests', 'name' => 'Solicitudes', 'group_key' => 'main', 'sort_order' => 40],
        ['module_key' => 'collections', 'name' => 'Cobranza y cartera', 'group_key' => 'main', 'sort_order' => 50],
        ['module_key' => 'segment_profitability', 'name' => 'Utilidad por segmento', 'group_key' => 'main', 'sort_order' => 60],
        ['module_key' => 'sales_promotions', 'name' => 'Promociones vigentes', 'group_key' => 'main', 'sort_order' => 70],
        ['module_key' => 'config_catalog', 'name' => 'Catálogo de servicios', 'group_key' => 'configuration', 'sort_order' => 110],
        ['module_key' => 'config_prices', 'name' => 'Listas de precios', 'group_key' => 'configuration', 'sort_order' => 120],
        ['module_key' => 'config_promotions', 'name' => 'Promociones', 'group_key' => 'configuration', 'sort_order' => 130],
        ['module_key' => 'config_issuers', 'name' => 'Emisores', 'group_key' => 'configuration', 'sort_order' => 140],
        ['module_key' => 'config_instances', 'name' => 'Instancias', 'group_key' => 'configuration', 'sort_order' => 145],
        ['module_key' => 'config_users', 'name' => 'Usuarios del sistema', 'group_key' => 'configuration', 'sort_order' => 150],
        ['module_key' => 'config_roles', 'name' => 'Roles y permisos', 'group_key' => 'configuration', 'sort_order' => 160],
        ['module_key' => 'config_company', 'name' => 'Empresa', 'group_key' => 'configuration', 'sort_order' => 165],
        ['module_key' => 'config_system', 'name' => 'Sistema', 'group_key' => 'configuration', 'sort_order' => 168],
        ['module_key' => 'config_audit_logs', 'name' => 'Bitácora de auditoría', 'group_key' => 'configuration', 'sort_order' => 169],
        ['module_key' => 'config_preferences', 'name' => 'Preferencias de pantalla', 'group_key' => 'configuration', 'sort_order' => 170],
    ];

    public function up(): void
    {
        $this->migrateAliasedKeys();
        $this->upsertCanonicalModules();
        $this->purgeOrphanKeys();
        $this->normalizeGroupKeys();
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE role_permissions SET module_key = 'segment_utility' WHERE module_key = 'segment_profitability'"
        );
        $this->execute(
            "UPDATE system_modules
             SET module_key = 'segment_utility', name = 'Utilidad por segmento'
             WHERE module_key = 'segment_profitability'"
        );
    }

    private function migrateAliasedKeys(): void
    {
        $adapter = $this->getAdapter();
        if ($adapter === null) {
            return;
        }

        foreach (self::KEY_ALIASES as $legacy => $canonical) {
            if ($legacy === $canonical) {
                continue;
            }

            $legacyPerms = $this->fetchAll(sprintf(
                "SELECT id, role_id, can_view, can_create, can_edit, can_delete
                 FROM role_permissions WHERE module_key = '%s'",
                $this->quote($legacy)
            ));

            foreach ($legacyPerms as $row) {
                $roleId = (int) $row['role_id'];
                $canon = $this->fetchRow(sprintf(
                    "SELECT id, can_view, can_create, can_edit, can_delete
                     FROM role_permissions WHERE role_id = %d AND module_key = '%s'",
                    $roleId,
                    $this->quote($canonical)
                ));

                if ($canon) {
                    $this->execute(sprintf(
                        "UPDATE role_permissions SET
                            can_view = %d, can_create = %d, can_edit = %d, can_delete = %d,
                            updated_at = UTC_TIMESTAMP()
                         WHERE id = %d",
                        max((int) $canon['can_view'], (int) $row['can_view']),
                        max((int) $canon['can_create'], (int) $row['can_create']),
                        max((int) $canon['can_edit'], (int) $row['can_edit']),
                        max((int) $canon['can_delete'], (int) $row['can_delete']),
                        (int) $canon['id']
                    ));
                    $this->execute(sprintf(
                        'DELETE FROM role_permissions WHERE id = %d',
                        (int) $row['id']
                    ));
                } else {
                    $this->execute(sprintf(
                        "UPDATE role_permissions SET module_key = '%s', updated_at = UTC_TIMESTAMP() WHERE id = %d",
                        $this->quote($canonical),
                        (int) $row['id']
                    ));
                }
            }

            $legacyModule = $this->fetchRow(sprintf(
                "SELECT id FROM system_modules WHERE module_key = '%s'",
                $this->quote($legacy)
            ));
            if (!$legacyModule) {
                continue;
            }

            $canonModule = $this->fetchRow(sprintf(
                "SELECT id FROM system_modules WHERE module_key = '%s'",
                $this->quote($canonical)
            ));

            if ($canonModule) {
                $this->execute(sprintf(
                    'DELETE FROM system_modules WHERE id = %d',
                    (int) $legacyModule['id']
                ));
            } else {
                $this->execute(sprintf(
                    "UPDATE system_modules SET module_key = '%s' WHERE id = %d",
                    $this->quote($canonical),
                    (int) $legacyModule['id']
                ));
            }
        }
    }

    private function upsertCanonicalModules(): void
    {
        foreach (self::CANONICAL_MODULES as $module) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM system_modules WHERE module_key = '%s'",
                $this->quote($module['module_key'])
            ));

            if ($existing) {
                $this->execute(sprintf(
                    "UPDATE system_modules SET name = '%s', group_key = '%s', sort_order = %d WHERE id = %d",
                    $this->quote($module['name']),
                    $this->quote($module['group_key']),
                    (int) $module['sort_order'],
                    (int) $existing['id']
                ));
                continue;
            }

            $this->table('system_modules')->insert($module)->saveData();
        }
    }

    private function purgeOrphanKeys(): void
    {
        $allowed = array_column(self::CANONICAL_MODULES, 'module_key');
        $inList = implode("','", array_map([$this, 'quote'], $allowed));

        $this->execute(sprintf(
            "DELETE FROM role_permissions WHERE module_key NOT IN ('%s')",
            $inList
        ));
        $this->execute(sprintf(
            "DELETE FROM system_modules WHERE module_key NOT IN ('%s')",
            $inList
        ));
    }

    private function normalizeGroupKeys(): void
    {
        $this->execute(
            "UPDATE system_modules SET group_key = 'main' WHERE group_key IN ('principal', 'operativos', 'main')"
        );
        $this->execute(
            "UPDATE system_modules SET group_key = 'configuration' WHERE group_key IN ('configuracion', 'config', 'configuration')"
        );
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
