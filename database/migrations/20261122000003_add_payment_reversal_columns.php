<?php
/**
 * Migración Módulo 4 Paso 2 (pulido) — Reversión contable de pagos (sin DELETE físico).
 * Columnas: is_reversed, reversed_at, reversal_reason, reversed_by_user_id.
 */

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPaymentReversalColumns extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('payments');

        if (!$table->hasColumn('is_reversed')) {
            $table->addColumn('is_reversed', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'Pago revertido contablemente (append-only lógico)',
            ]);
        }

        if (!$table->hasColumn('reversed_at')) {
            $table->addColumn('reversed_at', 'timestamp', [
                'null' => true,
                'default' => null,
                'comment' => 'UTC — momento de la reversión',
            ]);
        }

        if (!$table->hasColumn('reversal_reason')) {
            $table->addColumn('reversal_reason', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Motivo obligatorio de la reversión',
            ]);
        }

        if (!$table->hasColumn('reversed_by_user_id')) {
            $table->addColumn('reversed_by_user_id', 'integer', [
                'signed' => false,
                'null' => true,
            ]);
        }

        $table->update();

        // FK opcional tras agregar la columna (idempotente si ya existe).
        if ($table->hasColumn('reversed_by_user_id')) {
            $hasFk = false;
            foreach ($this->fetchAll(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'payments'
                   AND COLUMN_NAME = 'reversed_by_user_id'
                   AND REFERENCED_TABLE_NAME IS NOT NULL"
            ) as $row) {
                $hasFk = true;
                break;
            }
            if (!$hasFk) {
                $this->table('payments')
                    ->addForeignKey('reversed_by_user_id', 'users', 'id', [
                        'delete' => 'SET_NULL',
                        'update' => 'CASCADE',
                        'constraint' => 'fk_payments_reversed_by',
                    ])
                    ->update();
            }
        }
    }
}
