<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Módulo 3 Paso 2 — Enriquecimiento de `contracts` + tabla `contract_lines`.
 * Respeta columnas existentes (`frequency`, `next_cutoff_date`, status con `draft`).
 */
final class EnrichContractsModule3Paso2 extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('contracts');

        if (!$table->hasColumn('issuer_id')) {
            $table
                ->addColumn('issuer_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'segment_id',
                    'comment' => 'Emisor facturador (heredado del cliente)',
                ])
                ->update();
        }

        // Backfill emisor desde el cliente.
        $this->execute(
            'UPDATE contracts c
             INNER JOIN clients cli ON cli.id = c.client_id
             SET c.issuer_id = cli.issuer_id
             WHERE c.issuer_id IS NULL'
        );

        $table = $this->table('contracts');
        if ($table->hasColumn('issuer_id')) {
            $table
                ->changeColumn('issuer_id', 'integer', [
                    'signed' => false,
                    'null' => false,
                    'comment' => 'Emisor facturador (heredado del cliente)',
                ])
                ->update();
        }

        $table = $this->table('contracts');
        if (!$table->hasForeignKey('issuer_id')) {
            $table
                ->addForeignKey('issuer_id', 'issuers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_contracts_issuer',
                ])
                ->update();
        }

        $table = $this->table('contracts');
        $additions = [
            'instance_id' => [
                'type' => 'integer',
                'options' => [
                    'signed' => false,
                    'null' => true,
                    'after' => 'issuer_id',
                    'comment' => 'Instancia técnica opcional',
                ],
            ],
            'price_list_id' => [
                'type' => 'integer',
                'options' => [
                    'signed' => false,
                    'null' => true,
                    'after' => 'instance_id',
                    'comment' => 'Lista de precios pactada',
                ],
            ],
            'promotion_id' => [
                'type' => 'integer',
                'options' => [
                    'signed' => false,
                    'null' => true,
                    'after' => 'price_list_id',
                    'comment' => 'Promoción aplicada al alta',
                ],
            ],
            'exchange_rate' => [
                'type' => 'decimal',
                'options' => [
                    'precision' => 10,
                    'scale' => 4,
                    'default' => '1.0000',
                    'null' => false,
                    'after' => 'currency',
                    'comment' => 'Tipo de cambio USD→MXN al pactar (RNF-1)',
                ],
            ],
            'mrr_mxn' => [
                'type' => 'decimal',
                'options' => [
                    'precision' => 14,
                    'scale' => 2,
                    'default' => '0.00',
                    'null' => false,
                    'after' => 'stamp_on_issue',
                    'comment' => 'MRR mensual congelado en MXN',
                ],
            ],
            'notes' => [
                'type' => 'text',
                'options' => [
                    'null' => true,
                    'after' => 'termination_reason',
                    'comment' => 'Notas internas',
                ],
            ],
            'created_by' => [
                'type' => 'integer',
                'options' => [
                    'signed' => false,
                    'null' => true,
                    'after' => 'notes',
                    'comment' => 'Usuario que creó el contrato',
                ],
            ],
        ];

        foreach ($additions as $column => $meta) {
            $table = $this->table('contracts');
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, $meta['type'], $meta['options'])->update();
            }
        }

        $table = $this->table('contracts');
        if (!$table->hasIndexByName('idx_contracts_issuer')) {
            $table->addIndex(['issuer_id'], ['name' => 'idx_contracts_issuer'])->update();
        }

        $table = $this->table('contracts');
        if (!$table->hasForeignKey('instance_id')) {
            $table
                ->addForeignKey('instance_id', 'instances', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_contracts_instance',
                ])
                ->update();
        }

        $table = $this->table('contracts');
        if (!$table->hasForeignKey('price_list_id')) {
            $table
                ->addForeignKey('price_list_id', 'price_lists', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_contracts_price_list',
                ])
                ->update();
        }

        $table = $this->table('contracts');
        if (!$table->hasForeignKey('promotion_id')) {
            $table
                ->addForeignKey('promotion_id', 'promotions', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_contracts_promotion',
                ])
                ->update();
        }

        $table = $this->table('contracts');
        if (!$table->hasForeignKey('created_by')) {
            $table
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_contracts_created_by',
                ])
                ->update();
        }

        if (!$this->hasTable('contract_lines')) {
            $this->table('contract_lines', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Líneas de contrato con precios congelados (RNF-3)',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('service_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('description', 'string', ['limit' => 200, 'null' => false])
                ->addColumn('service_type', 'enum', [
                    'values' => ['recurring', 'consumption', 'single'],
                    'null' => false,
                ])
                ->addColumn('billing_frequency', 'enum', [
                    'values' => ['monthly', 'annual', 'single'],
                    'null' => false,
                ])
                ->addColumn('quantity', 'integer', [
                    'signed' => false,
                    'null' => false,
                    'default' => 1,
                ])
                ->addColumn('unit_price', 'decimal', [
                    'precision' => 14,
                    'scale' => 2,
                    'null' => false,
                ])
                ->addColumn('discount_amount', 'decimal', [
                    'precision' => 14,
                    'scale' => 2,
                    'default' => '0.00',
                    'null' => false,
                ])
                ->addColumn('subtotal', 'decimal', [
                    'precision' => 14,
                    'scale' => 2,
                    'null' => false,
                ])
                ->addColumn('mrr_mxn', 'decimal', [
                    'precision' => 14,
                    'scale' => 2,
                    'default' => '0.00',
                    'null' => false,
                ])
                ->addColumn('is_package_converted', 'boolean', [
                    'default' => false,
                    'null' => false,
                ])
                ->addColumn('active', 'boolean', ['default' => true, 'null' => false])
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
                ->addIndex(['contract_id'], ['name' => 'idx_contract_lines_contract'])
                ->addIndex(['service_id'], ['name' => 'idx_contract_lines_service'])
                ->addForeignKey('contract_id', 'contracts', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_contract_lines_contract',
                ])
                ->addForeignKey('service_id', 'services', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_contract_lines_service',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('contract_lines')) {
            $this->table('contract_lines')->drop()->save();
        }

        $table = $this->table('contracts');
        foreach (['created_by', 'promotion_id', 'price_list_id', 'instance_id'] as $fkCol) {
            $table = $this->table('contracts');
            if ($table->hasForeignKey($fkCol)) {
                $table->dropForeignKey($fkCol)->update();
            }
        }

        $table = $this->table('contracts');
        if ($table->hasForeignKey('issuer_id')) {
            $table->dropForeignKey('issuer_id')->update();
        }

        $table = $this->table('contracts');
        if ($table->hasIndexByName('idx_contracts_issuer')) {
            $table->removeIndexByName('idx_contracts_issuer')->update();
        }

        foreach ([
            'created_by',
            'notes',
            'mrr_mxn',
            'exchange_rate',
            'promotion_id',
            'price_list_id',
            'instance_id',
            'issuer_id',
        ] as $column) {
            $table = $this->table('contracts');
            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->update();
            }
        }
    }
}
