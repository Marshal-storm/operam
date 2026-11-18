<?php
/**
 * Migración Módulo 2 — Catálogos, Instancias, Listas de Precios y Promociones (RF-1 / RF-2).
 * Reversible vía change(). Montos DECIMAL(14,2) — RNF-1. Timestamps UTC — RNF-11.
 */

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateCatalogAndPricingTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('servicio', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Catálogo de productos/servicios (RF-1)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('segmento_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('emisor_default_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('codigo', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('descripcion', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('tipo', 'enum', [
                'values' => ['recurrente', 'consumo', 'unico'],
                'null' => false,
            ])
            ->addColumn('unidad_medida', 'enum', [
                'values' => [
                    'usuario',
                    'paquete_5_usuarios',
                    'timbre',
                    'hora',
                    'licencia',
                    'instancia',
                ],
                'null' => false,
            ])
            ->addColumn('permite_conversion_paquete', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'RF-2: conversión automática cada 5 usuarios',
            ])
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
            ->addForeignKey('segmento_id', 'segmento', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_servicio_segmento',
            ])
            ->addForeignKey('emisor_default_id', 'emisor', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_servicio_emisor',
            ])
            ->addIndex(['codigo'], ['unique' => true, 'name' => 'uk_servicio_codigo'])
            ->addIndex(['segmento_id'], ['name' => 'idx_servicio_segmento'])
            ->create();

        $this->table('instancia', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Entornos técnicos / VPS de clientes',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('codigo', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('emisor_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('segmento_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('url_acceso', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('ip_servidor', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('estado', 'enum', [
                'values' => ['operativa', 'mantenimiento', 'suspendida', 'baja'],
                'null' => false,
                'default' => 'operativa',
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
            ->addForeignKey('emisor_id', 'emisor', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_instancia_emisor',
            ])
            ->addForeignKey('segmento_id', 'segmento', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_instancia_segmento',
            ])
            ->addIndex(['codigo'], ['unique' => true, 'name' => 'uk_instancia_codigo'])
            ->create();

        $this->table('lista_precio', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('segmento_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('moneda', 'enum', [
                'values' => ['MXN', 'USD'],
                'default' => 'MXN',
                'null' => false,
            ])
            ->addColumn('es_default', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('vigente_desde', 'date', ['null' => false])
            ->addColumn('vigente_hasta', 'date', ['null' => true])
            ->addColumn('activa', 'boolean', ['default' => true, 'null' => false])
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
            ->addForeignKey('segmento_id', 'segmento', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_lista_precio_segmento',
            ])
            ->addIndex(['segmento_id', 'nombre'], ['name' => 'idx_lista_precio_segmento_nombre'])
            ->create();

        $this->table('lista_precio_item', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Escalas y precios por frecuencia (RF-2)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('lista_precio_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('servicio_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('frecuencia', 'enum', [
                'values' => ['mensual', 'anual', 'unico'],
                'null' => false,
            ])
            ->addColumn('cantidad_min', 'integer', [
                'signed' => false,
                'default' => 1,
                'null' => false,
            ])
            ->addColumn('cantidad_max', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'NULL = rango abierto',
            ])
            ->addColumn('precio_unitario', 'decimal', [
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
            ->addForeignKey('lista_precio_id', 'lista_precio', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_lista_precio_item_lista',
            ])
            ->addForeignKey('servicio_id', 'servicio', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_lista_precio_item_servicio',
            ])
            ->addIndex(
                ['lista_precio_id', 'servicio_id', 'frecuencia', 'cantidad_min'],
                ['name' => 'idx_lista_precio_item_scale']
            )
            ->create();

        $this->table('promocion', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('codigo', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('nombre', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('segmento_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('tipo_descuento', 'enum', [
                'values' => ['porcentaje', 'monto_fijo', 'meses_gratis'],
                'null' => false,
            ])
            ->addColumn('valor_descuento', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('aplica_a', 'enum', [
                'values' => ['recurrente', 'unico', 'ambos'],
                'default' => 'recurrente',
                'null' => false,
            ])
            ->addColumn('vigente_desde', 'date', ['null' => false])
            ->addColumn('vigente_hasta', 'date', ['null' => true])
            ->addColumn('usos_maximos', 'integer', [
                'limit' => MysqlAdapter::INT_MEDIUM,
                'signed' => false,
                'null' => true,
            ])
            ->addColumn('usos_actuales', 'integer', [
                'limit' => MysqlAdapter::INT_MEDIUM,
                'signed' => false,
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('activa', 'boolean', ['default' => true, 'null' => false])
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
            ->addForeignKey('segmento_id', 'segmento', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_promocion_segmento',
            ])
            ->addIndex(['codigo'], ['unique' => true, 'name' => 'uk_promocion_codigo'])
            ->create();
    }
}
