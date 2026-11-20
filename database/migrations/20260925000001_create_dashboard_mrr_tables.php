<?php
/**
 * Migración Módulo 1 — tablas del Dashboard / Puente de MRR (esquema 100% inglés).
 * Reversible vía change() (Phinx). Montos DECIMAL(14,2) — RNF-1.
 * Timestamps en UTC — RNF-11. Fechas de corte interpretadas en America/Mexico_City.
 */

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateDashboardMrrTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('segments', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Segmentos de producto (Operam, Construsync, etc.)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('default_credit_days', 'integer', [
                'limit' => MysqlAdapter::INT_SMALL,
                'signed' => false,
                'default' => 30,
                'null' => false,
            ])
            ->addColumn('usd_exchange_rate', 'decimal', [
                'precision' => 10,
                'scale' => 4,
                'default' => '19.5000',
                'null' => false,
                'comment' => 'RF-1: tipo de cambio USD→MXN de referencia del segmento',
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
            ->addIndex(['name'], ['unique' => true, 'name' => 'uk_segments_name'])
            ->create();

        $this->table('issuers', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Emisores fiscales / legales',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('rfc', 'string', ['limit' => 13, 'null' => false])
            ->addColumn('api_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('secret_ref', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Referencia al secreto ERP (cofre), no la llave',
            ])
            ->addColumn('integropay_merchant_token_ref', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Ref merchant token IntegroPAY',
            ])
            ->addColumn('integropay_device_token_ref', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Ref device token IntegroPAY',
            ])
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
            ->addIndex(['rfc'], ['unique' => true, 'name' => 'uk_issuers_rfc'])
            ->create();

        $this->table('clients', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Clientes / cuentas de facturación',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('issuer_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('erp_client_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('name', 'string', ['limit' => 160, 'null' => false])
            ->addColumn('rfc', 'string', ['limit' => 13, 'null' => true])
            ->addColumn('legal_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('credit_days', 'integer', [
                'limit' => MysqlAdapter::INT_SMALL,
                'signed' => false,
                'null' => true,
            ])
            ->addColumn('stamp_on_issue', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('exclude_suspension', 'boolean', ['default' => false, 'null' => false])
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
            ->addIndex(['issuer_id'], ['name' => 'idx_clients_issuer'])
            ->addIndex(['erp_client_id'], ['name' => 'idx_clients_erp'])
            ->addForeignKey('issuer_id', 'issuers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_clients_issuer',
            ])
            ->create();

        $this->table('contracts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Contratos / suscripciones',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('folio', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('frequency', 'enum', [
                'values' => ['monthly', 'annual', 'single'],
                'default' => 'monthly',
                'null' => false,
            ])
            ->addColumn('anniversary_date', 'date', [
                'null' => false,
                'comment' => 'Fecha de corte en calendario America/Mexico_City',
            ])
            ->addColumn('currency', 'char', ['limit' => 3, 'default' => 'MXN', 'null' => false])
            ->addColumn('credit_days', 'integer', [
                'limit' => MysqlAdapter::INT_SMALL,
                'signed' => false,
                'default' => 30,
                'null' => false,
            ])
            ->addColumn('stamp_on_issue', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('status', 'enum', [
                'values' => ['active', 'suspended', 'cancelled', 'draft'],
                'default' => 'active',
                'null' => false,
            ])
            ->addColumn('next_cutoff_date', 'date', [
                'null' => true,
                'comment' => 'Próximo corte America/Mexico_City',
            ])
            ->addColumn('origin_contract_id', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'Contrato origen en transferencias / renuevos',
            ])
            ->addColumn('termination_reason', 'string', ['limit' => 255, 'null' => true])
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
            ->addIndex(['folio'], ['unique' => true, 'name' => 'uk_contracts_folio'])
            ->addIndex(['client_id'], ['name' => 'idx_contracts_client'])
            ->addIndex(['segment_id'], ['name' => 'idx_contracts_segment'])
            ->addIndex(['status'], ['name' => 'idx_contracts_status'])
            ->addIndex(['segment_id', 'status'], ['name' => 'idx_contracts_segment_status'])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_contracts_client',
            ])
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_contracts_segment',
            ])
            ->create();

        // Bitácora inmutable (RNF-3): solo INSERT desde la aplicación.
        $this->table('movements', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Bitácora inmutable de MRR (RNF-3)',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('contract_line_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('service_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('movement_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('mrr_category', 'enum', [
                'values' => [
                    'new',
                    'expansion',
                    'price_adjustment',
                    'frequency_change',
                    'contraction',
                    'churn',
                    'reactivation',
                    'transfer',
                ],
                'null' => false,
            ])
            ->addColumn('effective_date', 'date', [
                'null' => false,
                'comment' => 'Efectividad en calendario America/Mexico_City',
            ])
            ->addColumn('quantity_before', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('quantity_after', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('mrr_before', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('mrr_after', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('mrr_delta_mxn', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('exchange_rate', 'decimal', [
                'precision' => 14,
                'scale' => 6,
                'default' => '1.000000',
                'null' => false,
            ])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('request_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('has_exception', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('excepted_policy', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('reversed_movement_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'comment' => 'FK lógica al movimiento original cuando este registro es una reversión',
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['contract_id'], ['name' => 'idx_movements_contract'])
            ->addIndex(['effective_date'], ['name' => 'idx_movements_effective_date'])
            ->addIndex(['mrr_category'], ['name' => 'idx_movements_mrr_category'])
            ->addIndex(['effective_date', 'mrr_category'], ['name' => 'idx_movements_date_category'])
            ->addIndex(['reversed_movement_id'], ['name' => 'idx_movements_reversed'])
            ->addForeignKey('contract_id', 'contracts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_movements_contract',
            ])
            ->create();

        $this->table('collection_statuses', [
            'id' => false,
            'primary_key' => ['contract_id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Estado de cobranza por contrato',
        ])
            ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('overdue_balance', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('overdue_days', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('notice_level', 'integer', [
                'limit' => MysqlAdapter::INT_TINY,
                'signed' => false,
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('is_suspended', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addForeignKey('contract_id', 'contracts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_collection_statuses_contract',
            ])
            ->create();

        $this->table('monthly_mrr', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Agregado mensual del Puente de MRR',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('month', 'char', ['limit' => 7, 'null' => false, 'comment' => 'YYYY-MM'])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('issuer_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('service_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('category', 'enum', [
                'values' => [
                    'start',
                    'new',
                    'expansion',
                    'price_adjustment',
                    'frequency_change',
                    'contraction',
                    'churn',
                    'reactivation',
                    'end',
                ],
                'null' => false,
            ])
            ->addColumn('amount', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
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
                ['month', 'category', 'segment_id', 'issuer_id', 'service_id'],
                ['unique' => true, 'name' => 'uk_monthly_mrr_dim']
            )
            ->addIndex(['month', 'segment_id'], ['name' => 'idx_monthly_mrr_month_segment'])
            ->addIndex(['segment_id'], ['name' => 'idx_monthly_mrr_segment'])
            ->addIndex(['issuer_id'], ['name' => 'idx_monthly_mrr_issuer'])
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_monthly_mrr_segment',
            ])
            ->addForeignKey('issuer_id', 'issuers', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_monthly_mrr_issuer',
            ])
            ->create();
    }
}
