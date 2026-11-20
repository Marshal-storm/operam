<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RNF-3 — Inmutabilidad de la bitácora `movements` a nivel MySQL.
 * up/down (no change()) porque CREATE/DROP TRIGGER no es reversible con change().
 * Triggers: trg_movements_prevent_update / trg_movements_prevent_delete.
 */
final class EnforceMovimientoImmutabilityRnf3 extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_movimiento_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_movimiento_prevent_delete');
        $this->execute('DROP TRIGGER IF EXISTS trg_movements_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_movements_prevent_delete');

        // MESSAGE_TEXT ≤ 128 chars (límite MySQL SIGNAL).
        $this->execute(
            "CREATE TRIGGER trg_movements_prevent_update "
            . "BEFORE UPDATE ON movements FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            . "'RNF-3: movements immutable (UPDATE). Use reversal with reversed_movement_id.'"
        );

        $this->execute(
            "CREATE TRIGGER trg_movements_prevent_delete "
            . "BEFORE DELETE ON movements FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            . "'RNF-3: movements immutable (DELETE forbidden).'"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_movements_prevent_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_movements_prevent_delete');
    }
}
