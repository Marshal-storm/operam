<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Módulo 3 Paso 1 — Enriquecimiento de `clients` para el directorio CRUD.
 * Agrega alcance de segmento (RBAC), contacto, bandera `active` e índices de búsqueda.
 */
final class EnrichClientsDirectoryModule3 extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('clients');

        if (!$table->hasColumn('segment_id')) {
            $table
                ->addColumn('segment_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'id',
                    'comment' => 'Segmento comercial (alcance RBAC y herencia de crédito)',
                ])
                ->update();
        }

        // Backfill: contrato más reciente del cliente, o segmento Operam / primer segmento.
        $this->execute(
            'UPDATE clients c
             LEFT JOIN (
                 SELECT client_id, MAX(segment_id) AS segment_id
                 FROM contracts
                 GROUP BY client_id
             ) ct ON ct.client_id = c.id
             SET c.segment_id = COALESCE(
                 ct.segment_id,
                 (SELECT id FROM segments WHERE name = \'Operam\' ORDER BY id ASC LIMIT 1),
                 (SELECT id FROM segments ORDER BY id ASC LIMIT 1)
             )
             WHERE c.segment_id IS NULL'
        );

        $table = $this->table('clients');

        if ($table->hasColumn('segment_id')) {
            $table
                ->changeColumn('segment_id', 'integer', [
                    'signed' => false,
                    'null' => false,
                    'comment' => 'Segmento comercial (alcance RBAC y herencia de crédito)',
                ])
                ->update();
        }

        $table = $this->table('clients');

        if (!$table->hasForeignKey('segment_id')) {
            $table
                ->addForeignKey('segment_id', 'segments', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_clients_segment',
                ])
                ->update();
        }

        $table = $this->table('clients');

        if (!$table->hasColumn('contact_name')) {
            $table
                ->addColumn('contact_name', 'string', [
                    'limit' => 120,
                    'null' => true,
                    'after' => 'legal_name',
                    'comment' => 'Nombre de contacto comercial',
                ])
                ->update();
        }

        $table = $this->table('clients');

        if (!$table->hasColumn('contact_email')) {
            $table
                ->addColumn('contact_email', 'string', [
                    'limit' => 150,
                    'null' => true,
                    'after' => 'contact_name',
                    'comment' => 'Correo de contacto',
                ])
                ->update();
        }

        $table = $this->table('clients');

        if (!$table->hasColumn('contact_phone')) {
            $table
                ->addColumn('contact_phone', 'string', [
                    'limit' => 30,
                    'null' => true,
                    'after' => 'contact_email',
                    'comment' => 'Teléfono de contacto',
                ])
                ->update();
        }

        $table = $this->table('clients');

        if (!$table->hasColumn('active')) {
            $table
                ->addColumn('active', 'boolean', [
                    'default' => true,
                    'null' => false,
                    'after' => 'exclude_suspension',
                    'comment' => 'Cliente activo en el directorio',
                ])
                ->update();
        }

        // Normalización de datos antes de endurecer NOT NULL / longitudes.
        $this->execute(
            "UPDATE clients SET rfc = UPPER(TRIM(rfc)) WHERE rfc IS NOT NULL AND rfc <> ''"
        );
        $this->execute(
            "UPDATE clients SET rfc = CONCAT('TMP', LPAD(id, 10, '0'))
             WHERE rfc IS NULL OR TRIM(rfc) = ''"
        );
        $this->execute(
            "UPDATE clients SET legal_name = name
             WHERE legal_name IS NULL OR TRIM(legal_name) = ''"
        );
        $this->execute(
            'UPDATE clients SET credit_days = 30 WHERE credit_days IS NULL'
        );

        $table = $this->table('clients');
        $table
            ->changeColumn('name', 'string', [
                'limit' => 150,
                'null' => false,
                'comment' => 'Nombre comercial',
            ])
            ->changeColumn('legal_name', 'string', [
                'limit' => 200,
                'null' => false,
                'comment' => 'Razón social',
            ])
            ->changeColumn('rfc', 'string', [
                'limit' => 13,
                'null' => false,
                'comment' => 'RFC en mayúsculas',
            ])
            ->changeColumn('credit_days', 'integer', [
                'limit' => MysqlAdapter::INT_SMALL,
                'signed' => false,
                'null' => false,
                'default' => 30,
                'comment' => 'Días de crédito (hereda de segments.default_credit_days)',
            ])
            ->changeColumn('stamp_on_issue', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'Timbrar automáticamente al emitir',
            ])
            ->update();

        $table = $this->table('clients');
        if (!$table->hasIndexByName('idx_clients_segment_active')) {
            $table->addIndex(['segment_id', 'active'], ['name' => 'idx_clients_segment_active'])->update();
        }

        $table = $this->table('clients');
        if (!$table->hasIndexByName('idx_clients_rfc')) {
            $table->addIndex(['rfc'], ['name' => 'idx_clients_rfc'])->update();
        }

        // issuer_id y erp_client_id ya tienen índice desde 000001.
    }

    public function down(): void
    {
        $table = $this->table('clients');

        if ($table->hasIndexByName('idx_clients_segment_active')) {
            $table->removeIndexByName('idx_clients_segment_active')->update();
        }

        $table = $this->table('clients');
        if ($table->hasIndexByName('idx_clients_rfc')) {
            $table->removeIndexByName('idx_clients_rfc')->update();
        }

        $table = $this->table('clients');
        if ($table->hasForeignKey('segment_id')) {
            $table->dropForeignKey('segment_id')->update();
        }

        $table = $this->table('clients');
        foreach (['active', 'contact_phone', 'contact_email', 'contact_name', 'segment_id'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->update();
                $table = $this->table('clients');
            }
        }

        $table = $this->table('clients');
        $table
            ->changeColumn('name', 'string', ['limit' => 160, 'null' => false])
            ->changeColumn('legal_name', 'string', ['limit' => 255, 'null' => true])
            ->changeColumn('rfc', 'string', ['limit' => 13, 'null' => true])
            ->changeColumn('credit_days', 'integer', [
                'limit' => MysqlAdapter::INT_SMALL,
                'signed' => false,
                'null' => true,
            ])
            ->changeColumn('stamp_on_issue', 'boolean', ['default' => true, 'null' => false])
            ->update();
    }
}
