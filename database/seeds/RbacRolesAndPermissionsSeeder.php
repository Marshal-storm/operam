<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Catálogo canónico de 18 module_key + roles iniciales con matriz CRUD (idempotente).
 * group_key en inglés: main | configuration. Nombres visibles en español.
 */
final class RbacRolesAndPermissionsSeeder extends AbstractSeed
{
    /** Solo lectura (Ver). */
    private const READ_ONLY = [
        'dashboard',
        'sales_promotions',
        'segment_profitability',
        'config_audit_logs',
    ];

    /** Ver + Editar. */
    private const VIEW_EDIT = [
        'config_company',
        'config_preferences',
    ];

    /** Ver + Editar + Eliminar (sin Crear). */
    private const VIEW_EDIT_DELETE = [
        'config_system',
    ];

    public function getDependencies(): array
    {
        return [
            EnterpriseUsersSeeder::class,
        ];
    }

    public function run(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $this->seedModules();
        $this->purgeLegacyKeys();
        $roleIds = $this->seedRoles($nowUtc);
        $this->seedPermissions($roleIds, $nowUtc);
        $this->linkExistingUsers($roleIds, $nowUtc);
    }

    private function seedModules(): void
    {
        $modules = [
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

        $table = $this->table('system_modules');
        foreach ($modules as $module) {
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
            $table->insert($module)->saveData();
        }
    }

    /** Elimina llaves legacy / monolíticas que ya no forman parte del catálogo. */
    private function purgeLegacyKeys(): void
    {
        $legacy = [
            'segment_utility',
            'configuracion',
            'contratos',
            'clientes',
            'config_catalogo',
            'config_precios',
        ];
        foreach ($legacy as $key) {
            $this->execute(sprintf(
                "DELETE FROM role_permissions WHERE module_key = '%s'",
                $this->quote($key)
            ));
            $this->execute(sprintf(
                "DELETE FROM system_modules WHERE module_key = '%s'",
                $this->quote($key)
            ));
        }
    }

    /**
     * @return array<string, int>
     */
    private function seedRoles(string $nowUtc): array
    {
        $roles = [
            [
                'code' => 'admin_general',
                'name' => 'Administrador General',
                'description' => 'Acceso total en los 18 módulos (según acciones aplicables). Alcance: Todos.',
                'is_system' => 1,
            ],
            [
                'code' => 'operador_comercial',
                'name' => 'Operador Operam',
                'description' => 'Opera dashboard, contratos, clientes, solicitudes y promociones. Sin configuración. Segmento: Operam.',
                'is_system' => 1,
            ],
            [
                'code' => 'supervisor_segmento',
                'name' => 'Supervisor Construsync',
                'description' => 'Ver y editar en su segmento. Sin configuración. Segmento: Construsync.',
                'is_system' => 1,
            ],
            [
                'code' => 'ejecutivo_ventas',
                'name' => 'Ejecutivo de Ventas',
                'description' => 'Ver dashboard, crear contratos/clientes, ver promociones de ventas. Sin configuración.',
                'is_system' => 1,
            ],
            [
                'code' => 'gestor_promociones',
                'name' => 'Gestor de Promociones',
                'description' => 'Administra Promociones (config) y consulta promociones vigentes. Sin precios ni usuarios.',
                'is_system' => 0,
            ],
        ];

        $ids = [];
        $table = $this->table('roles');
        foreach ($roles as $role) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM roles WHERE code = '%s'",
                $this->quote($role['code'])
            ));
            if ($existing) {
                $this->execute(sprintf(
                    "UPDATE roles SET name = '%s', description = '%s', is_system = %d, is_active = 1, updated_at = '%s' WHERE id = %d",
                    $this->quote($role['name']),
                    $this->quote($role['description']),
                    (int) $role['is_system'],
                    $nowUtc,
                    (int) $existing['id']
                ));
                $ids[$role['code']] = (int) $existing['id'];
                continue;
            }
            $table->insert([
                'code' => $role['code'],
                'name' => $role['name'],
                'description' => $role['description'],
                'is_system' => $role['is_system'],
                'is_active' => 1,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
            $row = $this->fetchRow(sprintf(
                "SELECT id FROM roles WHERE code = '%s'",
                $this->quote($role['code'])
            ));
            $ids[$role['code']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, int> $roleIds
     */
    private function seedPermissions(array $roleIds, string $nowUtc): void
    {
        $main = [
            'dashboard',
            'contracts',
            'clients',
            'requests',
            'collections',
            'segment_profitability',
            'sales_promotions',
        ];
        $config = [
            'config_catalog',
            'config_prices',
            'config_promotions',
            'config_issuers',
            'config_instances',
            'config_users',
            'config_roles',
            'config_company',
            'config_system',
            'config_audit_logs',
            'config_preferences',
        ];
        $allModules = array_merge($main, $config);

        $zeroConfig = [];
        foreach ($config as $moduleKey) {
            $zeroConfig[$moduleKey] = [0, 0, 0, 0];
        }

        $matrices = [
            'admin_general' => $this->adminMatrix($allModules),
            'operador_comercial' => array_merge([
                'dashboard' => [1, 0, 0, 0],
                'contracts' => [1, 1, 1, 0],
                'clients' => [1, 1, 1, 0],
                'requests' => [1, 1, 1, 0],
                'collections' => [0, 0, 0, 0],
                'segment_profitability' => [0, 0, 0, 0],
                'sales_promotions' => [1, 0, 0, 0],
            ], $zeroConfig),
            'supervisor_segmento' => array_merge([
                'dashboard' => [1, 0, 0, 0],
                'contracts' => [1, 1, 1, 0],
                'clients' => [1, 1, 1, 0],
                'requests' => [1, 1, 1, 0],
                'collections' => [1, 0, 1, 0],
                'segment_profitability' => [1, 0, 0, 0],
                'sales_promotions' => [1, 0, 0, 0],
            ], $zeroConfig),
            'ejecutivo_ventas' => array_merge([
                'dashboard' => [1, 0, 0, 0],
                'contracts' => [1, 1, 0, 0],
                'clients' => [1, 1, 0, 0],
                'requests' => [0, 0, 0, 0],
                'collections' => [0, 0, 0, 0],
                'segment_profitability' => [0, 0, 0, 0],
                'sales_promotions' => [1, 0, 0, 0],
            ], $zeroConfig),
            'gestor_promociones' => array_merge([
                'dashboard' => [1, 0, 0, 0],
                'contracts' => [0, 0, 0, 0],
                'clients' => [0, 0, 0, 0],
                'requests' => [0, 0, 0, 0],
                'collections' => [0, 0, 0, 0],
                'segment_profitability' => [0, 0, 0, 0],
                'sales_promotions' => [1, 0, 0, 0],
            ], array_merge($zeroConfig, [
                'config_promotions' => [1, 1, 1, 0],
            ])),
        ];

        $table = $this->table('role_permissions');
        foreach ($matrices as $code => $matrix) {
            $roleId = $roleIds[$code] ?? null;
            if ($roleId === null) {
                continue;
            }
            foreach ($matrix as $moduleKey => $flags) {
                [$canView, $canCreate, $canEdit, $canDelete] = $flags;
                $existing = $this->fetchRow(sprintf(
                    "SELECT id FROM role_permissions WHERE role_id = %d AND module_key = '%s'",
                    $roleId,
                    $this->quote($moduleKey)
                ));
                if ($existing) {
                    $this->execute(sprintf(
                        "UPDATE role_permissions SET can_view = %d, can_create = %d, can_edit = %d, can_delete = %d, updated_at = '%s' WHERE id = %d",
                        $canView,
                        $canCreate,
                        $canEdit,
                        $canDelete,
                        $nowUtc,
                        (int) $existing['id']
                    ));
                    continue;
                }
                $table->insert([
                    'role_id' => $roleId,
                    'module_key' => $moduleKey,
                    'can_view' => $canView,
                    'can_create' => $canCreate,
                    'can_edit' => $canEdit,
                    'can_delete' => $canDelete,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ])->saveData();
            }
        }
    }

    /**
     * Acceso total del Administrador General respetando acciones aplicables por módulo.
     *
     * @param list<string> $modules
     * @return array<string, list<int>>
     */
    private function adminMatrix(array $modules): array
    {
        $matrix = [];
        foreach ($modules as $moduleKey) {
            if (in_array($moduleKey, self::READ_ONLY, true)) {
                $matrix[$moduleKey] = [1, 0, 0, 0];
                continue;
            }
            if (in_array($moduleKey, self::VIEW_EDIT, true)) {
                $matrix[$moduleKey] = [1, 0, 1, 0];
                continue;
            }
            if (in_array($moduleKey, self::VIEW_EDIT_DELETE, true)) {
                $matrix[$moduleKey] = [1, 0, 1, 1];
                continue;
            }
            $matrix[$moduleKey] = [1, 1, 1, 1];
        }

        return $matrix;
    }

    /**
     * @param array<string, int> $roleIds
     */
    private function linkExistingUsers(array $roleIds, string $nowUtc): void
    {
        $operamId = $this->segmentIdByName('Operam');
        $construId = $this->segmentIdByName('Construsync');

        $links = [
            'admin@operam.mx' => [
                'role_id' => $roleIds['admin_general'] ?? null,
                'segment_id' => null,
            ],
            'operador@operam.mx' => [
                'role_id' => $roleIds['operador_comercial'] ?? null,
                'segment_id' => $operamId,
            ],
            'supervisor@construsync.mx' => [
                'role_id' => $roleIds['supervisor_segmento'] ?? null,
                'segment_id' => $construId,
            ],
        ];

        foreach ($links as $email => $data) {
            if ($data['role_id'] === null) {
                continue;
            }
            $segmentSql = $data['segment_id'] === null
                ? 'NULL'
                : (string) (int) $data['segment_id'];
            $this->execute(sprintf(
                "UPDATE users SET role_id = %d, segment_id = %s, updated_at = '%s' WHERE email = '%s'",
                (int) $data['role_id'],
                $segmentSql,
                $nowUtc,
                $this->quote($email)
            ));
        }
    }

    private function segmentIdByName(string $name): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM segments WHERE name = '%s'",
            $this->quote($name)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
