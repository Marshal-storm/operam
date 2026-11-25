<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bloque A — Utilidad por segmento (RF-10) + excepciones comerciales (RF-11.4 / RF-11.5).
 *
 * - Tabla `segment_costs`: captura mensual de costos operativos por segmento.
 * - `users.can_authorize_exceptions`: permiso independiente para brincar políticas.
 * - `overdue_balance_at_exception` en `movements` y `service_requests`: saldo vencido
 *   exacto al momento de autorizar una excepción (reporte Dirección).
 */
final class CreateSegmentCostsAndExceptionsFields extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('segment_costs')) {
            $this->table('segment_costs', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Costos operativos mensuales por segmento (Utilidad / RF-10)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('segment_id', 'integer', [
                    'signed' => false,
                    'null' => false,
                    'comment' => 'FK segments — alcance del costo',
                ])
                ->addColumn('month', 'char', [
                    'limit' => 7,
                    'null' => false,
                    'comment' => 'Mes contable YYYY-MM (ej. 2026-11)',
                ])
                ->addColumn('category', 'enum', [
                    'values' => [
                        'servers',
                        'support_payroll',
                        'implementation_payroll',
                        'pac_folios',
                        'other',
                    ],
                    'default' => 'other',
                    'null' => false,
                    'comment' => 'Categoría de costo operativo',
                ])
                ->addColumn('concept', 'string', [
                    'limit' => 160,
                    'null' => false,
                    'comment' => 'Concepto legible (Servidores GCP, Nómina soporte, etc.)',
                ])
                ->addColumn('amount', 'decimal', [
                    'precision' => 14,
                    'scale' => 2,
                    'null' => false,
                    'default' => '0.00',
                    'comment' => 'Monto MXN DECIMAL(14,2) — sin floats',
                ])
                ->addColumn('notes', 'text', [
                    'null' => true,
                    'comment' => 'Notas libres opcionales',
                ])
                ->addColumn('created_by_user_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'comment' => 'Usuario que capturó el costo',
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
                ->addIndex(['segment_id', 'month'], ['name' => 'idx_segment_costs_segment_month'])
                ->addIndex(['month'], ['name' => 'idx_segment_costs_month'])
                ->addForeignKey('segment_id', 'segments', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_segment_costs_segment',
                ])
                ->addForeignKey('created_by_user_id', 'users', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_segment_costs_created_by',
                ])
                ->create();
        }

        // RF-11.4 — permiso independiente de excepciones comerciales.
        if ($this->hasTable('users')) {
            $users = $this->table('users');
            if (!$users->hasColumn('can_authorize_exceptions')) {
                $users
                    ->addColumn('can_authorize_exceptions', 'boolean', [
                        'default' => false,
                        'null' => false,
                        'after' => 'is_active',
                        'comment' => 'RF-11.4: puede autorizar excepciones comerciales',
                    ])
                    ->update();
            }

            // Marca al administrador principal (idempotente; no revierte en down de change).
            $this->execute(
                "UPDATE users SET can_authorize_exceptions = 1, updated_at = UTC_TIMESTAMP() "
                . "WHERE email = 'admin@operam.mx' AND can_authorize_exceptions = 0"
            );
        }

        // RF-11.5 — saldo vencido al momento de la excepción (movimientos).
        if ($this->hasTable('movements')) {
            $movements = $this->table('movements');
            if (!$movements->hasColumn('overdue_balance_at_exception')) {
                $movements
                    ->addColumn('overdue_balance_at_exception', 'decimal', [
                        'precision' => 14,
                        'scale' => 2,
                        'null' => true,
                        'after' => 'excepted_policy',
                        'comment' => 'Saldo vencido exacto al autorizar excepción (RF-11.5)',
                    ])
                    ->update();
            }
        }

        // RF-11.5 — saldo vencido al momento de la excepción (solicitudes).
        if ($this->hasTable('service_requests')) {
            $requests = $this->table('service_requests');
            if (!$requests->hasColumn('overdue_balance_at_exception')) {
                $requests
                    ->addColumn('overdue_balance_at_exception', 'decimal', [
                        'precision' => 14,
                        'scale' => 2,
                        'null' => true,
                        'after' => 'exception_policy',
                        'comment' => 'Saldo vencido exacto al autorizar excepción (RF-11.5)',
                    ])
                    ->update();
            }
        }
    }
}
