<?php
/**
 * Migración Módulo 1 — tablas del Dashboard / Puente de MRR.
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
        $this->table('segmento', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Segmentos de producto (Operam, Construsync, etc.)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('nombre', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('dias_credito_default', 'integer', [
                'limit' => MysqlAdapter::INT_SMALL,
                'signed' => false,
                'default' => 30,
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
            ->addIndex(['nombre'], ['unique' => true, 'name' => 'uk_segmento_nombre'])
            ->create();

        $this->table('emisor', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('nombre', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('rfc', 'string', ['limit' => 13, 'null' => false])
            ->addColumn('api_url', 'string', ['limit' => 255, 'null' => true])
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
            ->addIndex(['rfc'], ['unique' => true, 'name' => 'uk_emisor_rfc'])
            ->create();

        $this->table('cliente', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('emisor_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('erp_cliente_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('nombre', 'string', ['limit' => 160, 'null' => false])
            ->addColumn('rfc', 'string', ['limit' => 13, 'null' => true])
            ->addColumn('razon_social', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('dias_credito', 'integer', [
                'limit' => MysqlAdapter::INT_SMALL,
                'signed' => false,
                'null' => true,
            ])
            ->addColumn('timbrar_al_emitir', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('excluir_suspension', 'boolean', ['default' => false, 'null' => false])
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
            ->addIndex(['emisor_id'], ['name' => 'idx_cliente_emisor'])
            ->addIndex(['erp_cliente_id'], ['name' => 'idx_cliente_erp'])
            ->addForeignKey('emisor_id', 'emisor', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_cliente_emisor',
            ])
            ->create();

        $this->table('contrato', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('folio', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('cliente_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('segmento_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('frecuencia', 'enum', [
                'values' => ['mensual', 'trimestral', 'semestral', 'anual'],
                'default' => 'mensual',
                'null' => false,
            ])
            ->addColumn('fecha_aniversario', 'date', [
                'null' => false,
                'comment' => 'Fecha de corte en calendario America/Mexico_City',
            ])
            ->addColumn('moneda', 'char', ['limit' => 3, 'default' => 'MXN', 'null' => false])
            ->addColumn('dias_credito', 'integer', [
                'limit' => MysqlAdapter::INT_SMALL,
                'signed' => false,
                'default' => 30,
                'null' => false,
            ])
            ->addColumn('timbrar_al_emitir', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('estado', 'enum', [
                'values' => ['activo', 'suspendido', 'cancelado', 'borrador'],
                'default' => 'activo',
                'null' => false,
            ])
            ->addColumn('proximo_corte', 'date', [
                'null' => true,
                'comment' => 'Próximo corte America/Mexico_City',
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
            ->addIndex(['folio'], ['unique' => true, 'name' => 'uk_contrato_folio'])
            ->addIndex(['cliente_id'], ['name' => 'idx_contrato_cliente'])
            ->addIndex(['segmento_id'], ['name' => 'idx_contrato_segmento'])
            ->addIndex(['estado'], ['name' => 'idx_contrato_estado'])
            ->addIndex(['segmento_id', 'estado'], ['name' => 'idx_contrato_segmento_estado'])
            ->addForeignKey('cliente_id', 'cliente', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_contrato_cliente',
            ])
            ->addForeignKey('segmento_id', 'segmento', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_contrato_segmento',
            ])
            ->create();

        // Bitácora inmutable (RNF-3): solo INSERT desde la aplicación.
        $this->table('movimiento', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Bitácora inmutable de MRR (RNF-3)',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('contrato_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('contrato_linea_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('servicio_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('tipo', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('categoria_mrr', 'enum', [
                'values' => [
                    'nuevo',
                    'expansion',
                    'ajuste_precio',
                    'cambio_frecuencia',
                    'contraccion',
                    'churn',
                    'reactivacion',
                ],
                'null' => false,
            ])
            ->addColumn('fecha_efectiva', 'date', [
                'null' => false,
                'comment' => 'Efectividad en calendario America/Mexico_City',
            ])
            ->addColumn('cantidad_antes', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('cantidad_despues', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('mrr_antes', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('mrr_despues', 'decimal', [
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
            ->addColumn('tipo_cambio', 'decimal', [
                'precision' => 14,
                'scale' => 6,
                'default' => '1.000000',
                'null' => false,
            ])
            ->addColumn('usuario_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('solicitud_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('motivo', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('con_excepcion', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['contrato_id'], ['name' => 'idx_movimiento_contrato'])
            ->addIndex(['fecha_efectiva'], ['name' => 'idx_movimiento_fecha'])
            ->addIndex(['categoria_mrr'], ['name' => 'idx_movimiento_categoria'])
            ->addIndex(['fecha_efectiva', 'categoria_mrr'], ['name' => 'idx_movimiento_fecha_categoria'])
            ->addForeignKey('contrato_id', 'contrato', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_movimiento_contrato',
            ])
            ->create();

        $this->table('cobranza_estado', [
            'id' => false,
            'primary_key' => ['contrato_id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('contrato_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('saldo_vencido', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('dias_vencido', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('nivel_aviso', 'integer', [
                'limit' => MysqlAdapter::INT_TINY,
                'signed' => false,
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('suspendido', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('actualizado', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addForeignKey('contrato_id', 'contrato', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_cobranza_contrato',
            ])
            ->create();

        $this->table('mrr_mensual', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Agregado mensual del Puente de MRR',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('mes', 'char', ['limit' => 7, 'null' => false, 'comment' => 'YYYY-MM'])
            ->addColumn('segmento_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('emisor_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('servicio_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('categoria', 'enum', [
                'values' => [
                    'inicio',
                    'nuevo',
                    'expansion',
                    'ajuste_precio',
                    'cambio_frecuencia',
                    'contraccion',
                    'churn',
                    'reactivacion',
                    'fin',
                ],
                'null' => false,
            ])
            ->addColumn('monto', 'decimal', [
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
                ['mes', 'categoria', 'segmento_id', 'emisor_id', 'servicio_id'],
                ['unique' => true, 'name' => 'uk_mrr_mensual_dim']
            )
            ->addIndex(['mes', 'segmento_id'], ['name' => 'idx_mrr_mensual_mes_segmento'])
            ->addIndex(['segmento_id'], ['name' => 'idx_mrr_mensual_segmento'])
            ->addIndex(['emisor_id'], ['name' => 'idx_mrr_mensual_emisor'])
            ->addForeignKey('segmento_id', 'segmento', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_mrr_mensual_segmento',
            ])
            ->addForeignKey('emisor_id', 'emisor', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_mrr_mensual_emisor',
            ])
            ->create();
    }
}
