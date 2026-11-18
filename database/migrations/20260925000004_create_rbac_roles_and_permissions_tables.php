<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RBAC dinámico: rol, modulo_sistema, rol_permiso + usuario.rol_id.
 * alcance_segmento = usuario.segmento_id (NULL = Todos).
 */
final class CreateRbacRolesAndPermissionsTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('rol', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Roles del sistema (matriz CRUD editable)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('codigo', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('descripcion', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('es_sistema', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('activo', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['codigo'], ['unique' => true, 'name' => 'uk_rol_codigo'])
            ->create();

        $this->table('modulo_sistema', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Catálogo de módulos con clave de permiso',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('clave', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('grupo', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('orden', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['clave'], ['unique' => true, 'name' => 'uk_modulo_sistema_clave'])
            ->create();

        $this->table('rol_permiso', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Matriz CRUD por rol y módulo',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('rol_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('modulo_clave', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('puede_ver', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('puede_crear', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('puede_editar', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('puede_eliminar', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(
                ['rol_id', 'modulo_clave'],
                ['unique' => true, 'name' => 'uk_rol_permiso_rol_modulo']
            )
            ->addForeignKey('rol_id', 'rol', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_rol_permiso_rol',
            ])
            ->create();

        // Compatibilidad: se conserva usuario.rol (enum legacy) y se agrega rol_id.
        $this->table('usuario')
            ->addColumn('rol_id', 'integer', [
                'signed' => false,
                'null' => true,
                'after' => 'rol',
            ])
            ->addIndex(['rol_id'], ['name' => 'idx_usuario_rol_id'])
            ->addForeignKey('rol_id', 'rol', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_usuario_rol',
            ])
            ->update();
    }
}
