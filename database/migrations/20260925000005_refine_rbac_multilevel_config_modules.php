<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Sustituye el permiso monolítico `configuracion` por submódulos config_*.
 * Migra permisos existentes: quien tenía acceso a `configuracion` hereda
 * el mismo CRUD en todos los submódulos nuevos.
 */
final class RefineRbacMultilevelConfigModules extends AbstractMigration
{
    public function change(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        $configModules = [
            ['clave' => 'config_catalogo', 'nombre' => 'Catálogo de servicios', 'grupo' => 'configuracion', 'orden' => 110],
            ['clave' => 'config_precios', 'nombre' => 'Listas de precios', 'grupo' => 'configuracion', 'orden' => 120],
            ['clave' => 'config_promociones', 'nombre' => 'Promociones (admin)', 'grupo' => 'configuracion', 'orden' => 130],
            ['clave' => 'config_instancias', 'nombre' => 'Instancias y emisores', 'grupo' => 'configuracion', 'orden' => 140],
            ['clave' => 'config_usuarios', 'nombre' => 'Usuarios del sistema', 'grupo' => 'configuracion', 'orden' => 150],
            ['clave' => 'config_roles', 'nombre' => 'Roles y permisos', 'grupo' => 'configuracion', 'orden' => 160],
            ['clave' => 'config_preferencias', 'nombre' => 'Preferencias de pantalla', 'grupo' => 'configuracion', 'orden' => 170],
        ];

        foreach ($configModules as $module) {
            $exists = $this->fetchRow(sprintf(
                "SELECT id FROM modulo_sistema WHERE clave = '%s'",
                $this->quote($module['clave'])
            ));
            if ($exists) {
                continue;
            }
            $this->table('modulo_sistema')->insert($module)->saveData();
        }

        // Roles que tenían la clave legacy `configuracion`.
        $legacyRows = $this->fetchAll(
            "SELECT rol_id, puede_ver, puede_crear, puede_editar, puede_eliminar
             FROM rol_permiso WHERE modulo_clave = 'configuracion'"
        );

        $roles = $this->fetchAll('SELECT id FROM rol');
        foreach ($roles as $role) {
            $rolId = (int) $role['id'];
            $legacy = null;
            foreach ($legacyRows as $row) {
                if ((int) $row['rol_id'] === $rolId) {
                    $legacy = $row;
                    break;
                }
            }

            foreach ($configModules as $module) {
                $clave = $module['clave'];
                $existing = $this->fetchRow(sprintf(
                    "SELECT id FROM rol_permiso WHERE rol_id = %d AND modulo_clave = '%s'",
                    $rolId,
                    $this->quote($clave)
                ));
                $ver = $legacy ? (int) $legacy['puede_ver'] : 0;
                $crear = $legacy ? (int) $legacy['puede_crear'] : 0;
                $editar = $legacy ? (int) $legacy['puede_editar'] : 0;
                $eliminar = $legacy ? (int) $legacy['puede_eliminar'] : 0;

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
                } else {
                    $this->table('rol_permiso')->insert([
                        'rol_id' => $rolId,
                        'modulo_clave' => $clave,
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

        // Elimina la clave monolítica obsoleta.
        $this->execute("DELETE FROM rol_permiso WHERE modulo_clave = 'configuracion'");
        $this->execute("DELETE FROM modulo_sistema WHERE clave = 'configuracion'");
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
