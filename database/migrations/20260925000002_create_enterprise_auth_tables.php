<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Módulo Auth empresarial — usuario, sesion_activa, auth_intento.
 * Vincula movimiento.usuario_id → usuario.id.
 */
final class CreateEnterpriseAuthTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('usuario', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('email', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('rol', 'enum', [
                'values' => ['admin', 'operador', 'lectura'],
                'null' => false,
            ])
            ->addColumn('segmento_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('activo', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('ultimo_acceso', 'timestamp', ['null' => true, 'comment' => 'UTC (RNF-11)'])
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
            ->addIndex(['email'], ['unique' => true, 'name' => 'uk_usuario_email'])
            ->addIndex(['segmento_id'], ['name' => 'idx_usuario_segmento'])
            ->addForeignKey('segmento_id', 'segmento', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_usuario_segmento',
            ])
            ->create();

        $this->table('sesion_activa', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Lista blanca JWT (jti) para revocación instantánea',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('session_uid', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('usuario_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('expira_en', 'timestamp', ['null' => false, 'comment' => 'UTC (RNF-11)'])
            ->addColumn('revocada', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['session_uid'], ['unique' => true, 'name' => 'uk_sesion_session_uid'])
            ->addIndex(['usuario_id'], ['name' => 'idx_sesion_usuario'])
            ->addIndex(['expira_en', 'revocada'], ['name' => 'idx_sesion_expira_revocada'])
            ->addForeignKey('usuario_id', 'usuario', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_sesion_usuario',
            ])
            ->create();

        $this->table('auth_intento', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Rate limiting contra fuerza bruta',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('email', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('exitoso', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['email'], ['name' => 'idx_auth_intento_email'])
            ->addIndex(['ip_address'], ['name' => 'idx_auth_intento_ip'])
            ->addIndex(['created_at'], ['name' => 'idx_auth_intento_created'])
            ->create();

        // Vincula bitácora MRR con el usuario autenticado.
        $this->table('movimiento')
            ->addForeignKey('usuario_id', 'usuario', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_movimiento_usuario',
            ])
            ->update();
    }
}
