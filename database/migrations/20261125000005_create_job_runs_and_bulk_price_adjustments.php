<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bloque C Paso 2 — Jobs de Google Cloud (Cloud Scheduler / Cloud Run Jobs), lotes de
 * Ajuste Masivo de Precios (RF-3.6) y periodo facturado en `invoices` (idempotencia RNF-2).
 *
 * Esquema 100% inglés snake_case; timestamps en UTC (RNF-11).
 */
final class CreateJobRunsAndBulkPriceAdjustments extends AbstractMigration
{
    public function change(): void
    {
        // ── job_runs: bitácora de cada ejecución de los 4 jobs oficiales ─────
        $this->table('job_runs', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Historial de ejecuciones de jobs (Cloud Scheduler, Cloud Run Jobs y disparos manuales)',
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('job_name', 'enum', [
                'values' => ['daily_billing', 'reset_included_balances', 'daily_collections', 'process_outbox'],
                'null' => false,
                'comment' => 'Job oficial ejecutado',
            ])
            ->addColumn('trigger_source', 'enum', [
                'values' => ['cloud_scheduler', 'cli', 'manual_ui'],
                'null' => false,
                'comment' => 'Origen del disparo: HTTP de Cloud Scheduler, CLI de Cloud Run Jobs o botón en la UI',
            ])
            ->addColumn('status', 'enum', [
                'values' => ['running', 'completed', 'failed'],
                'default' => 'running',
                'null' => false,
            ])
            ->addColumn('started_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addColumn('finished_at', 'timestamp', [
                'null' => true,
                'default' => null,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addColumn('processed_count', 'integer', [
                'signed' => false,
                'default' => 0,
                'null' => false,
                'comment' => 'Registros procesados por la corrida (contratos, bolsas o mensajes)',
            ])
            ->addColumn('summary_json', 'json', [
                'null' => true,
                'default' => null,
                'comment' => 'Resumen estructurado de la corrida (conteos, folios, omitidos)',
            ])
            ->addColumn('error_message', 'text', ['null' => true, 'default' => null])
            ->addColumn('triggered_by_user_id', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'comment' => 'Usuario que disparó manualmente (NULL para Cloud Scheduler / CLI)',
            ])
            ->addIndex(['job_name', 'started_at'], ['name' => 'idx_job_runs_job_started'])
            ->addIndex(['status'], ['name' => 'idx_job_runs_status'])
            ->addForeignKey('triggered_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_job_runs_triggered_by',
            ])
            ->create();

        // ── bulk_price_adjustments: trazabilidad de lotes RF-3.6 (AJ-2026-001) ─
        $this->table('bulk_price_adjustments', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Lotes de ajuste masivo de precios sobre líneas recurrentes de contratos activos',
        ])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('batch_code', 'string', [
                'limit' => 30,
                'null' => false,
                'comment' => 'Folio del lote AJ-YYYY-XXX (se replica en movements.reason)',
            ])
            ->addColumn('percentage', 'decimal', [
                'precision' => 6,
                'scale' => 2,
                'null' => false,
                'comment' => 'Porcentaje aplicado al precio unitario (positivo incrementa, negativo reduce)',
            ])
            ->addColumn('filters_json', 'json', [
                'null' => false,
                'comment' => 'Filtros usados: segment_id, issuer_id, service_id, anniversary_from/to',
            ])
            ->addColumn('affected_contracts_count', 'integer', [
                'signed' => false,
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('mrr_delta_total_mxn', 'decimal', [
                'precision' => 14,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
                'comment' => 'Impacto neto en MRR (suma de deltas de movements del lote)',
            ])
            ->addColumn('reason', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('executed_by_user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
                'comment' => 'UTC (RNF-11)',
            ])
            ->addIndex(['batch_code'], ['unique' => true, 'name' => 'uq_bulk_price_adjustments_batch_code'])
            ->addIndex(['created_at'], ['name' => 'idx_bulk_price_adjustments_created_at'])
            ->addForeignKey('executed_by_user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_bulk_price_adjustments_executed_by',
            ])
            ->create();

        // ── invoices: periodo facturado por el job daily_billing (RF-5.1 / RNF-2) ─
        // El índice único (contract_id, period_start) impide duplicar la factura recurrente de un
        // mismo aniversario aunque el job corra dos veces; facturas manuales dejan period_start NULL.
        $this->table('invoices')
            ->addColumn('period_start', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'due_date',
                'comment' => 'Inicio del periodo recurrente facturado (fecha de aniversario/corte)',
            ])
            ->addColumn('period_end', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'period_start',
                'comment' => 'Fin del periodo recurrente facturado (día previo al siguiente corte)',
            ])
            ->addIndex(['contract_id', 'period_start'], [
                'unique' => true,
                'name' => 'uq_invoices_contract_period',
            ])
            ->update();
    }
}
