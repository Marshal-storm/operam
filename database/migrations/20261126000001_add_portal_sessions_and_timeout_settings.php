<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Sesiones deslizantes por inactividad del Portal del Cliente (`portal_sessions`)
 * y parámetros dinámicos de timeout/vigencia en `system_settings`.
 *
 * Esquema 100% en inglés `snake_case`, timestamps en UTC (RNF-11).
 */
final class AddPortalSessionsAndTimeoutSettings extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('portal_sessions')) {
            $this->table('portal_sessions', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Sesiones activas del Portal del Cliente con expiración deslizante por inactividad',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('session_token_hash', 'char', [
                    'limit' => 64,
                    'null' => false,
                    'comment' => 'SHA-256 del claim sid del JWT del portal (nunca en claro)',
                ])
                ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('contact_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                ])
                ->addColumn('last_activity_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'Última interacción autenticada en UTC (RNF-11)',
                ])
                ->addColumn('expires_at', 'timestamp', [
                    'null' => false,
                    'comment' => 'Expiración deslizante calculada en UTC (RNF-11)',
                ])
                ->addColumn('revoked_at', 'timestamp', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'Fecha UTC de cierre de sesión o revocación inmediata',
                ])
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
                ->addIndex(['session_token_hash'], [
                    'unique' => true,
                    'name' => 'uk_portal_sessions_token_hash',
                ])
                ->addIndex(['client_id', 'revoked_at'], [
                    'name' => 'idx_portal_sessions_client_revoked',
                ])
                ->addIndex(['expires_at'], [
                    'name' => 'idx_portal_sessions_expires',
                ])
                ->addForeignKey('client_id', 'clients', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_portal_sessions_client',
                ])
                ->addForeignKey('contact_id', 'client_contacts', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_portal_sessions_contact',
                ])
                ->create();
        }

        // Parámetros dinámicos en `system_settings` (esquema llave-valor) al migrar hacia arriba.
        if ($this->isMigratingUp() && $this->hasTable('system_settings')) {
            $this->execute(
                "INSERT IGNORE INTO system_settings (setting_key, setting_value, value_type, description)
                 VALUES
                   ('portal_idle_timeout_minutes', '5', 'integer', 'Minutos de inactividad antes de expirar la sesión del Portal Cliente'),
                   ('portal_magic_link_ttl_minutes', '15', 'integer', 'Minutos de vigencia de una liga mágica del Portal Cliente antes de usarse')"
            );
        }
    }
}
