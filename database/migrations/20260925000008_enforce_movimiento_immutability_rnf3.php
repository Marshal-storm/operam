<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RNF-3 — Inmutabilidad de la bitácora `movimiento` a nivel MySQL.
 * up/down (no change()) porque CREATE/DROP TRIGGER no es reversible con change().
 *
 * Nota: el timestamp 000005 ya estaba ocupado por RBAC multinivel;
 * esta migración continúa la secuencia como 000008.
 */
final class EnforceMovimientoImmutabilityRnf3 extends AbstractMigration
{
    public function up(): void
    {
        // Columna de enlace contable para reversiones (INSERT de movimiento inverso).
        $table = $this->table('movimiento');
        if (!$table->hasColumn('movimiento_revertido_id')) {
            $table
                ->addColumn('movimiento_revertido_id', 'biginteger', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'con_excepcion',
                    'comment' => 'FK lógica al movimiento original cuando este registro es una reversión',
                ])
                ->addIndex(['movimiento_revertido_id'], ['name' => 'idx_movimiento_revertido'])
                ->update();
        }

        // Evita redefinición si se re-ejecuta parcialmente en entornos locales.
        $this->execute('DROP TRIGGER IF EXISTS trg_movimiento_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_movimiento_prevent_delete');

        // MESSAGE_TEXT ≤ 128 chars (límite MySQL SIGNAL). Una sola sentencia (sin BEGIN/END).
        $this->execute(
            "CREATE TRIGGER trg_movimiento_prevent_update "
            . "BEFORE UPDATE ON movimiento FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            . "'RNF-3: movimiento inmutable (UPDATE). Use reversión con movimiento_revertido_id.'"
        );

        $this->execute(
            "CREATE TRIGGER trg_movimiento_prevent_delete "
            . "BEFORE DELETE ON movimiento FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            . "'RNF-3: movimiento inmutable (DELETE prohibido).'"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_movimiento_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_movimiento_prevent_delete');

        // Índice + columna de reversión (SQL directo para compatibilidad Phinx).
        $this->execute('ALTER TABLE movimiento DROP INDEX IF EXISTS idx_movimiento_revertido');
        $table = $this->table('movimiento');
        if ($table->hasColumn('movimiento_revertido_id')) {
            $table->removeColumn('movimiento_revertido_id')->update();
        }
    }
}
