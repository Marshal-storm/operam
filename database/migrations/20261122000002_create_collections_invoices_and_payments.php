<?php
/**
 * Migración Módulo 4 Paso 2 — Cobranza: documentos (`invoices`) y pagos (`payments`).
 * Esquema 100% inglés. Montos DECIMAL(14,2) — RNF-1. Timestamps UTC — RNF-11.
 */

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCollectionsInvoicesAndPayments extends AbstractMigration
{
    public function change(): void
    {
        $this->table('invoices', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Documentos / facturas emitidas por cobrar',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('folio', 'string', [
                'limit' => 30,
                'null' => false,
                'comment' => 'Folio correlativo FAC-0001',
            ])
            ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('segment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('issuer_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('request_id', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'FK a service_requests (cargos de solicitudes aprobadas)',
            ])
            ->addColumn('invoice_type', 'enum', [
                'values' => ['recurring', 'one_time', 'consumption', 'mixed'],
                'default' => 'recurring',
                'null' => false,
            ])
            ->addColumn('concept', 'string', [
                'limit' => 255,
                'null' => false,
                'comment' => 'Descripción clara del cargo',
            ])
            ->addColumn('currency', 'enum', [
                'values' => ['MXN', 'USD'],
                'default' => 'MXN',
                'null' => false,
            ])
            ->addColumn('subtotal', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('tax_amount', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('total', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('paid_amount', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
            ])
            ->addColumn('balance', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('issue_date', 'date', ['null' => false])
            ->addColumn('due_date', 'date', [
                'null' => false,
                'comment' => 'issue_date + contract.credit_days',
            ])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'partial', 'paid', 'overdue', 'cancelled'],
                'default' => 'pending',
                'null' => false,
            ])
            ->addColumn('stamped_uuid', 'string', [
                'limit' => 64,
                'null' => true,
                'comment' => 'Referencia CFDI si aplica',
            ])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
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
            ->addIndex(['folio'], ['unique' => true, 'name' => 'uk_invoices_folio'])
            ->addIndex(['contract_id', 'status'], ['name' => 'idx_invoices_contract_status'])
            ->addIndex(['segment_id', 'status'], ['name' => 'idx_invoices_segment_status'])
            ->addIndex(['due_date', 'status'], ['name' => 'idx_invoices_due_status'])
            ->addIndex(['request_id'], ['name' => 'idx_invoices_request'])
            ->addForeignKey('contract_id', 'contracts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_invoices_contract',
            ])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_invoices_client',
            ])
            ->addForeignKey('segment_id', 'segments', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_invoices_segment',
            ])
            ->addForeignKey('issuer_id', 'issuers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_invoices_issuer',
            ])
            ->addForeignKey('request_id', 'service_requests', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_invoices_request',
            ])
            ->addForeignKey('created_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_invoices_created_by',
            ])
            ->create();

        $this->table('payments', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Pagos parciales o totales aplicados a facturas',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('invoice_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('amount', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('payment_method', 'enum', [
                'values' => ['transfer', 'card_integropay', 'cash', 'check', 'other'],
                'default' => 'transfer',
                'null' => false,
            ])
            ->addColumn('reference', 'string', [
                'limit' => 120,
                'null' => true,
            ])
            ->addColumn('payment_date', 'date', ['null' => false])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('recorded_by_user_id', 'integer', ['signed' => false, 'null' => true])
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
            ->addIndex(['invoice_id'], ['name' => 'idx_payments_invoice'])
            ->addIndex(['contract_id', 'payment_date'], ['name' => 'idx_payments_contract_date'])
            ->addForeignKey('invoice_id', 'invoices', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_payments_invoice',
            ])
            ->addForeignKey('contract_id', 'contracts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_payments_contract',
            ])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_payments_client',
            ])
            ->addForeignKey('recorded_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_payments_recorded_by',
            ])
            ->create();
    }
}
