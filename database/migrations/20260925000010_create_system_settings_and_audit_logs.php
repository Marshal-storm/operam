<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Configuración global (`system_settings`) + bitácora de auditoría (`audit_logs`).
 * Timestamp 000010: 000007 ya estaba ocupado por el no-op de split emisores/instancias.
 * Triggers usan up()/down() (no reversibles con change()).
 */
final class CreateSystemSettingsAndAuditLogs extends AbstractMigration
{
    public function up(): void
    {
        $this->table('system_settings', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Configuración global del sistema (flags y parámetros)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('setting_key', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('setting_value', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('value_type', 'enum', [
                'values' => ['boolean', 'string', 'integer', 'json'],
                'null' => false,
                'default' => 'string',
            ])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('updated_by', 'integer', ['signed' => false, 'null' => true])
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
            ->addIndex(['setting_key'], ['unique' => true, 'name' => 'uk_system_settings_key'])
            ->addForeignKey('updated_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_system_settings_updated_by',
            ])
            ->create();

        $this->table('audit_logs', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Bitácora de auditoría append-only (cientos de miles de filas)',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('user_name_snapshot', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('user_email_snapshot', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('module_key', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('entity_type', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('entity_id', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('action', 'enum', [
                'values' => [
                    'create',
                    'update',
                    'delete',
                    'status_change',
                    'login',
                    'logout',
                    'revoke_session',
                    'purge_logs',
                ],
                'null' => false,
            ])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('old_values', 'json', ['null' => true])
            ->addColumn('new_values', 'json', ['null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => false, 'default' => '0.0.0.0'])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['created_at', 'module_key'], ['name' => 'idx_audit_logs_created_module'])
            ->addIndex(['user_id', 'created_at'], ['name' => 'idx_audit_logs_user_created'])
            ->addIndex(['entity_type', 'entity_id'], ['name' => 'idx_audit_logs_entity'])
            ->addIndex(['action'], ['name' => 'idx_audit_logs_action'])
            ->addIndex(['ip_address'], ['name' => 'idx_audit_logs_ip'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_audit_logs_user',
            ])
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_audit_logs_segment',
            ])
            ->create();

        $this->execute('DROP TRIGGER IF EXISTS trg_audit_logs_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_audit_logs_controlled_delete');

        $this->execute(
            "CREATE TRIGGER trg_audit_logs_prevent_update "
            . "BEFORE UPDATE ON audit_logs FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            . "'audit_logs is immutable (UPDATE forbidden).'"
        );

        // DELETE solo si @allow_audit_purge = 1 (depuración controlada desde Sistema).
        $this->execute(
            "CREATE TRIGGER trg_audit_logs_controlled_delete "
            . "BEFORE DELETE ON audit_logs FOR EACH ROW "
            . "BEGIN "
            . "IF IFNULL(@allow_audit_purge, 0) <> 1 THEN "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            . "'audit_logs DELETE blocked. Use controlled purge from System module.'; "
            . "END IF; "
            . "END"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_audit_logs_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_audit_logs_controlled_delete');
        $this->table('audit_logs')->drop()->save();
        $this->table('system_settings')->drop()->save();
    }
}
