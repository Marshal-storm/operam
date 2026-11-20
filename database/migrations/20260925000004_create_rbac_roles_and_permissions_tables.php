<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RBAC dinámico: roles, system_modules, role_permissions + FK users.role_id.
 * Alcance de segmento = users.segment_id (NULL = Todos).
 */
final class CreateRbacRolesAndPermissionsTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('roles', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Roles del sistema (matriz CRUD editable)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('code', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_system', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('is_active', 'boolean', ['default' => true, 'null' => false])
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
            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_roles_code'])
            ->create();

        $this->table('system_modules', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Catálogo de módulos con clave de permiso (inglés)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('module_key', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('group_key', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['module_key'], ['unique' => true, 'name' => 'uk_system_modules_key'])
            ->create();

        $this->table('role_permissions', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Matriz CRUD por rol y módulo',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('module_key', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('can_view', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('can_create', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('can_edit', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('can_delete', 'boolean', ['default' => false, 'null' => false])
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
                ['role_id', 'module_key'],
                ['unique' => true, 'name' => 'uk_role_permissions_role_module']
            )
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_role_permissions_role',
            ])
            ->create();

        // FK diferida: roles se crea aquí; users.role_id ya existía desde auth.
        $this->table('users')
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_users_role',
            ])
            ->update();
    }
}
