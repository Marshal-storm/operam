<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bloque B Paso 1 — Saldos / bolsas, consumos inmutables, tokens de instancia y portal.
 * up/down (no change()) porque CREATE/DROP TRIGGER no es reversible con change().
 */
final class CreateBalancesConsumptionsAndInstanceTokens extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('balances')) {
            $this->table('balances', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Saldos por contrato+servicio+bolsa (included|prepaid) — RF-4',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('service_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('bucket', 'enum', [
                    'values' => ['included', 'prepaid'],
                    'null' => false,
                    'comment' => 'included = bolsa del periodo; prepaid = bolsa comprada sin caducidad',
                ])
                ->addColumn('period_start', 'date', [
                    'null' => true,
                    'comment' => 'Obligatorio para included; null en prepaid',
                ])
                ->addColumn('period_end', 'date', [
                    'null' => true,
                    'comment' => 'Reinicio de included; null en prepaid sin caducidad',
                ])
                ->addColumn('granted_units', 'integer', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                ])
                ->addColumn('consumed_units', 'integer', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
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
                ->addIndex(
                    ['contract_id', 'service_id', 'bucket'],
                    ['name' => 'idx_balances_contract_service_bucket'],
                )
                ->addIndex(['period_end'], ['name' => 'idx_balances_period_end'])
                ->addForeignKey('contract_id', 'contracts', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_balances_contract',
                ])
                ->addForeignKey('service_id', 'services', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_balances_service',
                ])
                ->create();
        }

        if (!$this->hasTable('consumptions')) {
            $this->table('consumptions', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Bitácora inmutable de consumos (RNF-3) — RF-4',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('balance_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('service_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('units', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('external_reference', 'string', [
                    'limit' => 120,
                    'null' => true,
                    'comment' => 'UUID CFDI / folio ticket — idempotencia RNF-2',
                ])
                ->addColumn('instance_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('user_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'comment' => 'Usuario interno que registró el consumo manual',
                ])
                ->addColumn('notes', 'text', ['null' => true])
                ->addColumn('consumed_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addIndex(
                    ['external_reference'],
                    ['unique' => true, 'name' => 'uk_consumptions_external_reference'],
                )
                ->addIndex(['contract_id', 'service_id'], ['name' => 'idx_consumptions_contract_service'])
                ->addIndex(['balance_id'], ['name' => 'idx_consumptions_balance'])
                ->addIndex(['consumed_at'], ['name' => 'idx_consumptions_consumed_at'])
                ->addForeignKey('balance_id', 'balances', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_consumptions_balance',
                ])
                ->addForeignKey('contract_id', 'contracts', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_consumptions_contract',
                ])
                ->addForeignKey('service_id', 'services', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_consumptions_service',
                ])
                ->addForeignKey('instance_id', 'instances', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_consumptions_instance',
                ])
                ->addForeignKey('user_id', 'users', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_consumptions_user',
                ])
                ->create();
        }

        if (!$this->hasTable('balance_movements')) {
            $this->table('balance_movements', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Historial de otorgamientos / compras / resets / ajustes de bolsa',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('balance_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('movement_type', 'enum', [
                    'values' => ['grant', 'purchase', 'promotion', 'reset', 'adjustment'],
                    'null' => false,
                ])
                ->addColumn('units', 'integer', [
                    'signed' => true,
                    'null' => false,
                    'comment' => 'Puede ser negativo en ajustes',
                ])
                ->addColumn('request_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('promotion_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('notes', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addIndex(['balance_id'], ['name' => 'idx_balance_movements_balance'])
                ->addIndex(['created_at'], ['name' => 'idx_balance_movements_created_at'])
                ->addForeignKey('balance_id', 'balances', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_balance_movements_balance',
                ])
                ->addForeignKey('request_id', 'service_requests', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_balance_movements_request',
                ])
                ->addForeignKey('promotion_id', 'promotions', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_balance_movements_promotion',
                ])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_balance_movements_user',
                ])
                ->create();
        }

        if (!$this->hasTable('portal_access_tokens')) {
            $this->table('portal_access_tokens', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Magic links de portal (origen email|instance) — RF-7.1',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('contact_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'comment' => 'Reservado para contactos del portal cliente',
                ])
                ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('origin', 'enum', [
                    'values' => ['email', 'instance'],
                    'null' => false,
                ])
                ->addColumn('expires_at', 'timestamp', ['null' => false, 'comment' => 'UTC'])
                ->addColumn('used_at', 'timestamp', ['null' => true, 'comment' => 'UTC'])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uk_portal_access_tokens_hash'])
                ->addIndex(['client_id'], ['name' => 'idx_portal_access_tokens_client'])
                ->addIndex(['expires_at'], ['name' => 'idx_portal_access_tokens_expires'])
                ->addForeignKey('client_id', 'clients', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_portal_access_tokens_client',
                ])
                ->addForeignKey('contract_id', 'contracts', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_portal_access_tokens_contract',
                ])
                ->create();
        }

        $instances = $this->table('instances');
        if (!$instances->hasColumn('api_token_hash')) {
            $instances
                ->addColumn('api_token_hash', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'after' => 'status',
                    'comment' => 'SHA-256 del Bearer de instancia (RNF-8 — nunca texto plano)',
                ])
                ->update();
        }
        if (!$instances->hasColumn('api_token_last4')) {
            $instances
                ->addColumn('api_token_last4', 'string', [
                    'limit' => 8,
                    'null' => true,
                    'after' => 'api_token_hash',
                    'comment' => 'Últimos 4 caracteres del token para UI',
                ])
                ->update();
        }
        if (!$instances->hasIndexByName('uk_instances_api_token_hash')) {
            $instances
                ->addIndex(['api_token_hash'], [
                    'unique' => true,
                    'name' => 'uk_instances_api_token_hash',
                ])
                ->update();
        }

        $this->execute('DROP TRIGGER IF EXISTS trg_consumptions_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_consumptions_prevent_delete');
        $this->execute(
            "CREATE TRIGGER trg_consumptions_prevent_update "
            . "BEFORE UPDATE ON consumptions FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            . "'RNF-3: consumptions immutable (UPDATE forbidden).'"
        );
        $this->execute(
            "CREATE TRIGGER trg_consumptions_prevent_delete "
            . "BEFORE DELETE ON consumptions FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            . "'RNF-3: consumptions immutable (DELETE forbidden).'"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_consumptions_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_consumptions_prevent_delete');

        if ($this->hasTable('portal_access_tokens')) {
            $this->table('portal_access_tokens')->drop()->save();
        }
        if ($this->hasTable('balance_movements')) {
            $this->table('balance_movements')->drop()->save();
        }
        if ($this->hasTable('consumptions')) {
            $this->table('consumptions')->drop()->save();
        }
        if ($this->hasTable('balances')) {
            $this->table('balances')->drop()->save();
        }

        $instances = $this->table('instances');
        if ($instances->hasIndexByName('uk_instances_api_token_hash')) {
            $instances->removeIndexByName('uk_instances_api_token_hash')->update();
        }
        if ($instances->hasColumn('api_token_last4')) {
            $instances->removeColumn('api_token_last4')->update();
        }
        if ($instances->hasColumn('api_token_hash')) {
            $instances->removeColumn('api_token_hash')->update();
        }
    }
}
