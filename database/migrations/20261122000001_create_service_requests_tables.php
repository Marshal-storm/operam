<?php
/**
 * Migración Módulo 4 Paso 1 — Bandeja de Solicitudes (`service_requests` / `service_request_items`).
 * Esquema 100% inglés. Montos DECIMAL(14,2) — RNF-1. Timestamps UTC — RNF-11.
 * Asegura índice de `movements.request_id` para vincular expansiones aprobadas.
 */

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateServiceRequestsTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('service_requests', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Solicitudes internas o de portal (cargos únicos, consumo, expansión recurrente)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('folio', 'string', [
                'limit' => 30,
                'null' => false,
                'comment' => 'Folio correlativo SOL-0001',
            ])
            ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('issuer_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('origin', 'enum', [
                'values' => ['internal', 'client_portal'],
                'default' => 'internal',
                'null' => false,
            ])
            ->addColumn('request_type', 'enum', [
                'values' => ['one_time', 'consumption', 'recurring_expansion'],
                'null' => false,
            ])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'quoted', 'approved', 'rejected', 'cancelled'],
                'default' => 'pending',
                'null' => false,
            ])
            ->addColumn('currency', 'enum', [
                'values' => ['MXN', 'USD'],
                'default' => 'MXN',
                'null' => false,
            ])
            ->addColumn('subtotal', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('mrr_impact_mxn', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
                'comment' => 'Impacto MRR MXN; 0.00 en one_time/consumption',
            ])
            ->addColumn('notes', 'text', [
                'null' => true,
                'comment' => 'Descripción del pedido (ej. 4 Formatos PDF)',
            ])
            ->addColumn('resolution_notes', 'text', [
                'null' => true,
                'comment' => 'Motivo de cotización, aprobación o rechazo',
            ])
            ->addColumn('with_exception', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'Aprobado pese a adeudo vencido',
            ])
            ->addColumn('exception_policy', 'string', [
                'limit' => 120,
                'null' => true,
            ])
            ->addColumn('requested_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('requested_by_name', 'string', [
                'limit' => 120,
                'null' => true,
                'comment' => 'Nombre snapshot (útil para portal cliente)',
            ])
            ->addColumn('resolved_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('resolved_at', 'timestamp', ['null' => true, 'default' => null])
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
            ->addIndex(['folio'], ['unique' => true, 'name' => 'uk_service_requests_folio'])
            ->addIndex(['segment_id', 'status'], ['name' => 'idx_service_requests_segment_status'])
            ->addIndex(['contract_id'], ['name' => 'idx_service_requests_contract'])
            ->addIndex(['created_at'], ['name' => 'idx_service_requests_created'])
            ->addForeignKey('contract_id', 'contracts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_service_requests_contract',
            ])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_service_requests_client',
            ])
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_service_requests_segment',
            ])
            ->addForeignKey('issuer_id', 'issuers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_service_requests_issuer',
            ])
            ->addForeignKey('requested_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_service_requests_requested_by',
            ])
            ->addForeignKey('resolved_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_service_requests_resolved_by',
            ])
            ->create();

        $this->table('service_request_items', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Líneas de solicitud (precios congelados al crear/cotizar)',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('service_request_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('service_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('service_type', 'enum', [
                'values' => ['recurring', 'consumption', 'one_time'],
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
            ->addColumn('subtotal', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('mrr_impact_mxn', 'decimal', [
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
            ->addIndex(['service_request_id'], ['name' => 'idx_service_request_items_request'])
            ->addIndex(['service_id'], ['name' => 'idx_service_request_items_service'])
            ->addForeignKey('service_request_id', 'service_requests', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_service_request_items_request',
            ])
            ->addForeignKey('service_id', 'services', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_service_request_items_service',
            ])
            ->create();

        // request_id ya existe en movements (000001); asegurar índice si falta.
        $movements = $this->table('movements');
        if (!$movements->hasColumn('request_id')) {
            $movements
                ->addColumn('request_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'comment' => 'FK lógica a service_requests.id (expansión desde solicitud)',
                ])
                ->addIndex(['request_id'], ['name' => 'idx_movements_request'])
                ->update();
        } elseif (!$this->hasMovementsRequestIndex()) {
            $movements
                ->addIndex(['request_id'], ['name' => 'idx_movements_request'])
                ->update();
        }
    }

    /** ¿Existe ya un índice sobre movements.request_id? */
    private function hasMovementsRequestIndex(): bool
    {
        $rows = $this->fetchAll(
            "SHOW INDEX FROM `movements` WHERE Column_name = 'request_id'"
        );

        return $rows !== [];
    }
}
