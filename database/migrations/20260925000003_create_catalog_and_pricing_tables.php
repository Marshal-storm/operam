<?php
/**
 * Migración Módulo 2 — Catálogos, Instancias, Listas de Precios y Promociones (RF-1 / RF-2).
 * Esquema 100% inglés. Montos DECIMAL(14,2) — RNF-1. Timestamps UTC — RNF-11.
 */

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateCatalogAndPricingTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('services', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Catálogo de productos/servicios (RF-1)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('default_issuer_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('code', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('service_type', 'enum', [
                'values' => ['recurring', 'consumption', 'single'],
                'null' => false,
            ])
            ->addColumn('unit_of_measure', 'enum', [
                'values' => [
                    'user',
                    'pack_5_users',
                    'stamp',
                    'hour',
                    'license',
                    'instance',
                ],
                'null' => false,
            ])
            ->addColumn('allow_package_conversion', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'RF-2: conversión automática cada N usuarios',
            ])
            ->addColumn('package_service_id', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'RF-2: producto paquete destino (FK services.id)',
            ])
            ->addColumn('package_factor', 'integer', [
                'signed' => false,
                'default' => 5,
                'null' => false,
                'comment' => 'RF-2: usuarios por paquete (default 5)',
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
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_services_segment',
            ])
            ->addForeignKey('default_issuer_id', 'issuers', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_services_default_issuer',
            ])
            ->addForeignKey('package_service_id', 'services', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_services_package',
            ])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_services_code'])
            ->addIndex(['segment_id'], ['name' => 'idx_services_segment'])
            ->addIndex(['package_service_id'], ['name' => 'idx_services_package'])
            ->create();

        $this->table('instances', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Entornos técnicos / VPS de clientes',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('code', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('issuer_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('access_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('server_ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('status', 'enum', [
                'values' => ['operational', 'maintenance', 'suspended', 'decommissioned'],
                'null' => false,
                'default' => 'operational',
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
            ->addForeignKey('issuer_id', 'issuers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_instances_issuer',
            ])
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_instances_segment',
            ])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_instances_code'])
            ->create();

        $this->table('price_lists', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Listas de precios por segmento',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('currency', 'enum', [
                'values' => ['MXN', 'USD'],
                'default' => 'MXN',
                'null' => false,
            ])
            ->addColumn('is_default', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('valid_from', 'date', ['null' => false])
            ->addColumn('valid_until', 'date', ['null' => true])
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
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_price_lists_segment',
            ])
            ->addIndex(['segment_id', 'name'], ['name' => 'idx_price_lists_segment_name'])
            ->create();

        $this->table('price_list_items', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Escalas y precios por frecuencia (RF-2)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('price_list_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('service_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('frequency', 'enum', [
                'values' => ['monthly', 'annual', 'single'],
                'null' => false,
            ])
            ->addColumn('min_quantity', 'integer', [
                'signed' => false,
                'default' => 1,
                'null' => false,
            ])
            ->addColumn('max_quantity', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'NULL = rango abierto',
            ])
            ->addColumn('unit_price', 'decimal', [
                'precision' => 14,
                'scale' => 2,
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
            ->addForeignKey('price_list_id', 'price_lists', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_price_list_items_list',
            ])
            ->addForeignKey('service_id', 'services', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_price_list_items_service',
            ])
            ->addIndex(
                ['price_list_id', 'service_id', 'frequency', 'min_quantity'],
                ['name' => 'idx_price_list_items_scale']
            )
            ->create();

        $this->table('promotions', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Promociones y descuentos comerciales',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('code', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('discount_type', 'enum', [
                'values' => ['percentage', 'fixed_amount', 'free_months'],
                'null' => false,
            ])
            ->addColumn('discount_value', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('applies_to', 'enum', [
                'values' => ['recurring', 'single', 'both'],
                'default' => 'recurring',
                'null' => false,
            ])
            ->addColumn('valid_from', 'date', ['null' => false])
            ->addColumn('valid_until', 'date', ['null' => true])
            ->addColumn('max_uses', 'integer', [
                'limit' => MysqlAdapter::INT_MEDIUM,
                'signed' => false,
                'null' => true,
            ])
            ->addColumn('current_uses', 'integer', [
                'limit' => MysqlAdapter::INT_MEDIUM,
                'signed' => false,
                'default' => 0,
                'null' => false,
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
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_promotions_segment',
            ])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uk_promotions_code'])
            ->create();
    }
}
