<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Separa el submódulo combinado en:
 * - config_emisores (Emisores)
 * - config_instancias (Instancias)
 * Hereda flags CRUD desde config_instancias hacia config_emisores.
 */
final class SplitConfigEmisoresInstancias extends AbstractMigration
{
    public function change(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        // Renombra el módulo existente de Instancias.
        $this->execute(
            "UPDATE modulo_sistema SET nombre = 'Instancias', orden = 145 WHERE clave = 'config_instancias'"
        );

        $exists = $this->fetchRow(
            "SELECT id FROM modulo_sistema WHERE clave = 'config_emisores'"
        );
        if (!$exists) {
            $this->table('modulo_sistema')->insert([
                'clave' => 'config_emisores',
                'nombre' => 'Emisores',
                'grupo' => 'configuracion',
                'orden' => 140,
            ])->saveData();
        } else {
            $this->execute(
                "UPDATE modulo_sistema SET nombre = 'Emisores', grupo = 'configuracion', orden = 140 WHERE clave = 'config_emisores'"
            );
        }

        $roles = $this->fetchAll('SELECT id, codigo FROM rol');
        foreach ($roles as $role) {
            $rolId = (int) $role['id'];
            $codigo = (string) $role['codigo'];

            $fromInstancias = $this->fetchRow(sprintf(
                "SELECT puede_ver, puede_crear, puede_editar, puede_eliminar FROM rol_permiso WHERE rol_id = %d AND modulo_clave = 'config_instancias'",
                $rolId
            ));

            // Administrador General: CRUD completo siempre.
            if ($codigo === 'admin_general') {
                $ver = 1;
                $crear = 1;
                $editar = 1;
                $eliminar = 1;
            } elseif ($fromInstancias) {
                $ver = (int) $fromInstancias['puede_ver'];
                $crear = (int) $fromInstancias['puede_crear'];
                $editar = (int) $fromInstancias['puede_editar'];
                $eliminar = (int) $fromInstancias['puede_eliminar'];
            } else {
                $ver = 0;
                $crear = 0;
                $editar = 0;
                $eliminar = 0;
            }

            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM rol_permiso WHERE rol_id = %d AND modulo_clave = 'config_emisores'",
                $rolId
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
            } else {
                $this->table('rol_permiso')->insert([
                    'rol_id' => $rolId,
                    'modulo_clave' => 'config_emisores',
                    'puede_ver' => $ver,
                    'puede_crear' => $crear,
                    'puede_editar' => $editar,
                    'puede_eliminar' => $eliminar,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ])->saveData();
            }

            // Garantiza CRUD total de admin también en instancias.
            if ($codigo === 'admin_general') {
                $instExisting = $this->fetchRow(sprintf(
                    "SELECT id FROM rol_permiso WHERE rol_id = %d AND modulo_clave = 'config_instancias'",
                    $rolId
                ));
                if ($instExisting) {
                    $this->execute(sprintf(
                        "UPDATE rol_permiso SET puede_ver = 1, puede_crear = 1, puede_editar = 1, puede_eliminar = 1, updated_at = '%s' WHERE id = %d",
                        $nowUtc,
                        (int) $instExisting['id']
                    ));
                } else {
                    $this->table('rol_permiso')->insert([
                        'rol_id' => $rolId,
                        'modulo_clave' => 'config_instancias',
                        'puede_ver' => 1,
                        'puede_crear' => 1,
                        'puede_editar' => 1,
                        'puede_eliminar' => 1,
                        'created_at' => $nowUtc,
                        'updated_at' => $nowUtc,
                    ])->saveData();
                }
            }
        }
    }
}
