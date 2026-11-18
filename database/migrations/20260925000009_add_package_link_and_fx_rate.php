<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Micro-ajuste Módulo 2 — RF-2 vínculo de paquete + tipo de cambio USD por segmento.
 *
 * Nota: el timestamp 000006 ya estaba ocupado por refs de emisor;
 * esta migración continúa la secuencia como 000009.
 * up()/down() porque incluye UPDATE de datos de seed y FK self-reference.
 */
final class AddPackageLinkAndFxRate extends AbstractMigration
{
    public function up(): void
    {
        $servicio = $this->table('servicio');

        if (!$servicio->hasColumn('servicio_paquete_id')) {
            $servicio
                ->addColumn('servicio_paquete_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'permite_conversion_paquete',
                    'comment' => 'RF-2: producto paquete destino (FK servicio.id)',
                ])
                ->update();
        }

        if (!$servicio->hasColumn('factor_paquete')) {
            $servicio
                ->addColumn('factor_paquete', 'integer', [
                    'signed' => false,
                    'default' => 5,
                    'null' => false,
                    'after' => 'servicio_paquete_id',
                    'comment' => 'RF-2: usuarios por paquete (default 5)',
                ])
                ->update();
        }

        $servicio = $this->table('servicio');
        if (!$servicio->hasForeignKey('servicio_paquete_id')) {
            $servicio
                ->addForeignKey('servicio_paquete_id', 'servicio', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_servicio_paquete',
                ])
                ->addIndex(['servicio_paquete_id'], ['name' => 'idx_servicio_paquete'])
                ->update();
        }

        $segmento = $this->table('segmento');
        if (!$segmento->hasColumn('tipo_cambio_usd')) {
            $segmento
                ->addColumn('tipo_cambio_usd', 'decimal', [
                    'precision' => 10,
                    'scale' => 4,
                    'default' => '19.5000',
                    'null' => false,
                    'after' => 'dias_credito_default',
                    'comment' => 'RF-1: tipo de cambio USD→MXN de referencia del segmento',
                ])
                ->update();
        }

        // Vincula OP-USR → OP-PKG5 si ambos ya existen (entornos con seed previo).
        $this->execute(
            "UPDATE servicio AS u "
            . "INNER JOIN servicio AS p ON p.codigo = 'OP-PKG5' "
            . "SET u.servicio_paquete_id = p.id, u.factor_paquete = 5, u.permite_conversion_paquete = 1 "
            . "WHERE u.codigo = 'OP-USR'"
        );
    }

    public function down(): void
    {
        $servicio = $this->table('servicio');
        if ($servicio->hasForeignKey('servicio_paquete_id')) {
            $servicio->dropForeignKey('servicio_paquete_id')->update();
        }
        if ($servicio->hasIndexByName('idx_servicio_paquete')) {
            $servicio->removeIndexByName('idx_servicio_paquete')->update();
        }
        if ($servicio->hasColumn('factor_paquete')) {
            $servicio->removeColumn('factor_paquete')->update();
        }
        if ($servicio->hasColumn('servicio_paquete_id')) {
            $servicio->removeColumn('servicio_paquete_id')->update();
        }

        $segmento = $this->table('segmento');
        if ($segmento->hasColumn('tipo_cambio_usd')) {
            $segmento->removeColumn('tipo_cambio_usd')->update();
        }
    }
}
