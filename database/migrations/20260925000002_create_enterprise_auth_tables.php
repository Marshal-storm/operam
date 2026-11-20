<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Auth empresarial — users, active_sessions, auth_attempts (esquema 100% inglés).
 * Vincula movements.user_id → users.id.
 */
final class CreateEnterpriseAuthTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Usuarios internos del sistema',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('email', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('last_login_at', 'timestamp', ['null' => true, 'comment' => 'UTC (RNF-11)'])
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
            ->addIndex(['email'], ['unique' => true, 'name' => 'uk_users_email'])
            ->addIndex(['segment_id'], ['name' => 'idx_users_segment'])
            ->addIndex(['role_id'], ['name' => 'idx_users_role'])
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_users_segment',
            ])
            ->create();

        $this->table('active_sessions', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Lista blanca JWT (jti) para revocación instantánea',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('session_uid', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('expires_at', 'timestamp', ['null' => false, 'comment' => 'UTC (RNF-11)'])
            ->addColumn('is_revoked', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['session_uid'], ['unique' => true, 'name' => 'uk_active_sessions_uid'])
            ->addIndex(['user_id'], ['name' => 'idx_active_sessions_user'])
            ->addIndex(['expires_at', 'is_revoked'], ['name' => 'idx_active_sessions_expires_revoked'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_active_sessions_user',
            ])
            ->create();

        $this->table('auth_attempts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Rate limiting contra fuerza bruta',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('email', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('is_successful', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['email'], ['name' => 'idx_auth_attempts_email'])
            ->addIndex(['ip_address'], ['name' => 'idx_auth_attempts_ip'])
            ->addIndex(['created_at'], ['name' => 'idx_auth_attempts_created'])
            ->create();

        // Vincula bitácora MRR con el usuario autenticado.
        $this->table('movements')
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_movements_user',
            ])
            ->update();
    }
}
