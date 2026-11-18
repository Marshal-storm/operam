<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Módulos multinivel (15) + roles iniciales con matriz CRUD (idempotente).
 * Incluye rol de ejemplo "Gestor de Promociones".
 * Emisores e Instancias son submódulos independientes.
 */
final class RbacRolesAndPermissionsSeeder extends AbstractSeed
{
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
        $this->purgeLegacyConfigModule($nowUtc);
        $roleIds = $this->seedRoles($nowUtc);
        $this->seedPermissions($roleIds, $nowUtc);
        $this->linkExistingUsers($roleIds, $nowUtc);
    }

    private function seedModules(): void
    {
        $modules = [
            ['clave' => 'dashboard', 'nombre' => 'Dashboard y Puente de MRR', 'grupo' => 'principal', 'orden' => 10],
            ['clave' => 'contratos', 'nombre' => 'Contratos y bitácora', 'grupo' => 'principal', 'orden' => 20],
            ['clave' => 'clientes', 'nombre' => 'Directorio de clientes', 'grupo' => 'principal', 'orden' => 30],
            ['clave' => 'solicitudes', 'nombre' => 'Solicitudes de compra/horas', 'grupo' => 'principal', 'orden' => 40],
            ['clave' => 'cobranza', 'nombre' => 'Cobranza y cartera', 'grupo' => 'principal', 'orden' => 50],
            ['clave' => 'utilidad_segmento', 'nombre' => 'Utilidad por segmento', 'grupo' => 'principal', 'orden' => 60],
            ['clave' => 'promociones_ventas', 'nombre' => 'Promociones vigentes (ventas)', 'grupo' => 'principal', 'orden' => 70],
            ['clave' => 'config_catalogo', 'nombre' => 'Catálogo de servicios', 'grupo' => 'configuracion', 'orden' => 110],
            ['clave' => 'config_precios', 'nombre' => 'Listas de precios', 'grupo' => 'configuracion', 'orden' => 120],
            ['clave' => 'config_promociones', 'nombre' => 'Promociones (admin)', 'grupo' => 'configuracion', 'orden' => 130],
            ['clave' => 'config_emisores', 'nombre' => 'Emisores', 'grupo' => 'configuracion', 'orden' => 140],
            ['clave' => 'config_instancias', 'nombre' => 'Instancias', 'grupo' => 'configuracion', 'orden' => 145],
            ['clave' => 'config_usuarios', 'nombre' => 'Usuarios del sistema', 'grupo' => 'configuracion', 'orden' => 150],
            ['clave' => 'config_roles', 'nombre' => 'Roles y permisos', 'grupo' => 'configuracion', 'orden' => 160],
            ['clave' => 'config_preferencias', 'nombre' => 'Preferencias de pantalla', 'grupo' => 'configuracion', 'orden' => 170],
        ];

        $table = $this->table('modulo_sistema');
        foreach ($modules as $module) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM modulo_sistema WHERE clave = '%s'",
                $this->quote($module['clave'])
            ));
            if ($existing) {
                $this->execute(sprintf(
                    "UPDATE modulo_sistema SET nombre = '%s', grupo = '%s', orden = %d WHERE id = %d",
                    $this->quote($module['nombre']),
                    $this->quote($module['grupo']),
                    (int) $module['orden'],
                    (int) $existing['id']
                ));
                continue;
            }
            $table->insert($module)->saveData();
        }
    }

    private function purgeLegacyConfigModule(string $nowUtc): void
    {
        unset($nowUtc);
        $this->execute("DELETE FROM rol_permiso WHERE modulo_clave = 'configuracion'");
        $this->execute("DELETE FROM modulo_sistema WHERE clave = 'configuracion'");
    }

    /**
     * @return array<string, int>
     */
    private function seedRoles(string $nowUtc): array
    {
        $roles = [
            [
                'codigo' => 'admin_general',
                'nombre' => 'Administrador General',
                'descripcion' => 'CRUD total en módulos operativos y todos los submódulos de configuración. Alcance: Todos.',
                'es_sistema' => 1,
            ],
            [
                'codigo' => 'operador_comercial',
                'nombre' => 'Operador Operam',
                'descripcion' => 'Opera dashboard, contratos, clientes, solicitudes y promociones. Sin configuración. Segmento: Operam.',
                'es_sistema' => 1,
            ],
            [
                'codigo' => 'supervisor_segmento',
                'nombre' => 'Supervisor Construsync',
                'descripcion' => 'Ver y editar en su segmento. Sin configuración. Segmento: Construsync.',
                'es_sistema' => 1,
            ],
            [
                'codigo' => 'ejecutivo_ventas',
                'nombre' => 'Ejecutivo de Ventas',
                'descripcion' => 'Ver dashboard, crear contratos/clientes, ver promociones de ventas. Sin configuración.',
                'es_sistema' => 1,
            ],
            [
                'codigo' => 'gestor_promociones',
                'nombre' => 'Gestor de Promociones',
                'descripcion' => 'Ejemplo especializado: solo administra Promociones (config) y consulta promociones de ventas. Sin precios ni usuarios.',
                'es_sistema' => 0,
            ],
        ];

        $ids = [];
        $table = $this->table('rol');
        foreach ($roles as $role) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM rol WHERE codigo = '%s'",
                $this->quote($role['codigo'])
            ));
            if ($existing) {
                $this->execute(sprintf(
                    "UPDATE rol SET nombre = '%s', descripcion = '%s', es_sistema = %d, activo = 1, updated_at = '%s' WHERE id = %d",
                    $this->quote($role['nombre']),
                    $this->quote($role['descripcion']),
                    (int) $role['es_sistema'],
                    $nowUtc,
                    (int) $existing['id']
                ));
                $ids[$role['codigo']] = (int) $existing['id'];
                continue;
            }
            $table->insert([
                'codigo' => $role['codigo'],
                'nombre' => $role['nombre'],
                'descripcion' => $role['descripcion'],
                'es_sistema' => $role['es_sistema'],
                'activo' => 1,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
            $row = $this->fetchRow(sprintf(
                "SELECT id FROM rol WHERE codigo = '%s'",
                $this->quote($role['codigo'])
            ));
            $ids[$role['codigo']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, int> $roleIds
     */
    private function seedPermissions(array $roleIds, string $nowUtc): void
    {
        $principal = [
            'dashboard',
            'contratos',
            'clientes',
            'solicitudes',
            'cobranza',
            'utilidad_segmento',
            'promociones_ventas',
        ];
        $config = [
            'config_catalogo',
            'config_precios',
            'config_promociones',
            'config_emisores',
            'config_instancias',
            'config_usuarios',
            'config_roles',
            'config_preferencias',
        ];
        $allModules = array_merge($principal, $config);

        $zeroConfig = [];
        foreach ($config as $clave) {
            $zeroConfig[$clave] = [0, 0, 0, 0];
        }

        $matrices = [
            'admin_general' => $this->fullCrudMatrix($allModules),
            'operador_comercial' => array_merge([
                'dashboard' => [1, 0, 0, 0],
                'contratos' => [1, 1, 1, 0],
                'clientes' => [1, 1, 1, 0],
                'solicitudes' => [1, 1, 1, 0],
                'cobranza' => [0, 0, 0, 0],
                'utilidad_segmento' => [0, 0, 0, 0],
                'promociones_ventas' => [1, 0, 0, 0],
            ], $zeroConfig),
            'supervisor_segmento' => array_merge([
                'dashboard' => [1, 0, 0, 0],
                'contratos' => [1, 1, 1, 0],
                'clientes' => [1, 1, 1, 0],
                'solicitudes' => [1, 1, 1, 0],
                'cobranza' => [1, 0, 1, 0],
                'utilidad_segmento' => [1, 0, 0, 0],
                'promociones_ventas' => [1, 0, 0, 0],
            ], $zeroConfig),
            'ejecutivo_ventas' => array_merge([
                'dashboard' => [1, 0, 0, 0],
                'contratos' => [1, 1, 0, 0],
                'clientes' => [1, 1, 0, 0],
                'solicitudes' => [0, 0, 0, 0],
                'cobranza' => [0, 0, 0, 0],
                'utilidad_segmento' => [0, 0, 0, 0],
                'promociones_ventas' => [1, 0, 0, 0],
            ], $zeroConfig),
            'gestor_promociones' => array_merge([
                'dashboard' => [1, 0, 0, 0],
                'contratos' => [0, 0, 0, 0],
                'clientes' => [0, 0, 0, 0],
                'solicitudes' => [0, 0, 0, 0],
                'cobranza' => [0, 0, 0, 0],
                'utilidad_segmento' => [0, 0, 0, 0],
                'promociones_ventas' => [1, 0, 0, 0],
            ], array_merge($zeroConfig, [
                'config_promociones' => [1, 1, 1, 0],
            ])),
        ];

        $table = $this->table('rol_permiso');
        foreach ($matrices as $codigo => $matrix) {
            $rolId = $roleIds[$codigo] ?? null;
            if ($rolId === null) {
                continue;
            }
            foreach ($matrix as $moduloClave => $flags) {
                [$ver, $crear, $editar, $eliminar] = $flags;
                $existing = $this->fetchRow(sprintf(
                    "SELECT id FROM rol_permiso WHERE rol_id = %d AND modulo_clave = '%s'",
                    $rolId,
                    $this->quote($moduloClave)
                ));
                if ($existing) {
                    $this->execute(sprintf(
                        "UPDATE rol_permiso SET puede_ver = %d, puede_crear = %d, puede_editar = %d, puede_eliminar = %d, updated_at = '%s' WHERE id = %d",
                        $ver,
                        $crear,
                        $editar,
                        $eliminar,
                        $nowUtc,
                        (int) $existing['id']
                    ));
                    continue;
                }
                $table->insert([
                    'rol_id' => $rolId,
                    'modulo_clave' => $moduloClave,
                    'puede_ver' => $ver,
                    'puede_crear' => $crear,
                    'puede_editar' => $editar,
                    'puede_eliminar' => $eliminar,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ])->saveData();
            }
        }
    }

    /**
     * @param list<string> $modules
     * @return array<string, list<int>>
     */
    private function fullCrudMatrix(array $modules): array
    {
        $matrix = [];
        foreach ($modules as $clave) {
            $matrix[$clave] = [1, 1, 1, 1];
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
                'rol_id' => $roleIds['admin_general'] ?? null,
                'segmento_id' => null,
                'rol_legacy' => 'admin',
            ],
            'operador@operam.mx' => [
                'rol_id' => $roleIds['operador_comercial'] ?? null,
                'segmento_id' => $operamId,
                'rol_legacy' => 'operador',
            ],
            'supervisor@construsync.mx' => [
                'rol_id' => $roleIds['supervisor_segmento'] ?? null,
                'segmento_id' => $construId,
                'rol_legacy' => 'operador',
            ],
        ];

        foreach ($links as $email => $data) {
            if ($data['rol_id'] === null) {
                continue;
            }
            $segmentSql = $data['segmento_id'] === null
                ? 'NULL'
                : (string) (int) $data['segmento_id'];
            $this->execute(sprintf(
                "UPDATE usuario SET rol_id = %d, segmento_id = %s, rol = '%s', updated_at = '%s' WHERE email = '%s'",
                (int) $data['rol_id'],
                $segmentSql,
                $this->quote($data['rol_legacy']),
                $nowUtc,
                $this->quote($email)
            ));
        }
    }

    private function segmentIdByName(string $nombre): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM segmento WHERE nombre = '%s'",
            $this->quote($nombre)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
