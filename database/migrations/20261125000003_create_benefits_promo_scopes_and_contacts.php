<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bloque B Paso 2 — Reglas de beneficio (RF-2.6), promociones de unidades extra (RF-2.8)
 * y contactos del cliente (RF-1.5). Esquema 100% inglés snake_case.
 */
final class CreateBenefitsPromoScopesAndContacts extends AbstractMigration
{
    public function change(): void
    {
        // ── benefit_rules (RF-2.6) ──────────────────────────────────────────
        if (!$this->hasTable('benefit_rules')) {
            $this->table('benefit_rules', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Reglas de beneficio: unidades incluidas por servicio origen (RF-2.6)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('source_service_id', 'integer', [
                    'signed' => false,
                    'null' => false,
                    'comment' => 'Servicio origen (ej. Usuario concurrente / Paquete)',
                ])
                ->addColumn('benefit_service_id', 'integer', [
                    'signed' => false,
                    'null' => false,
                    'comment' => 'Servicio otorgado (ej. Folios CFDI)',
                ])
                ->addColumn('units_per_unit', 'integer', [
                    'signed' => false,
                    'null' => false,
                    'comment' => 'Unidades del beneficio por cada unidad equivalente origen',
                ])
                ->addColumn('reset_period', 'enum', [
                    'values' => ['monthly', 'anniversary'],
                    'default' => 'monthly',
                    'null' => false,
                ])
                ->addColumn('is_cumulative', 'boolean', [
                    'default' => false,
                    'null' => false,
                ])
                ->addColumn('active', 'boolean', [
                    'default' => true,
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
                ->addIndex(
                    ['source_service_id', 'active'],
                    ['name' => 'idx_benefit_rules_source_active'],
                )
                ->addForeignKey('source_service_id', 'services', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_benefit_rules_source_service',
                ])
                ->addForeignKey('benefit_service_id', 'services', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_benefit_rules_benefit_service',
                ])
                ->create();
        }

        // ── Extensión promotions (RF-2.8) — compatible con cupones existentes ─
        if ($this->hasTable('promotions')) {
            $table = $this->table('promotions');
            if (!$table->hasColumn('promo_mode')) {
                $table->addColumn('promo_mode', 'enum', [
                    'values' => ['price_discount', 'extra_units'],
                    'default' => 'price_discount',
                    'null' => false,
                    'after' => 'name',
                    'comment' => 'price_discount = cupón; extra_units = % unidades gratis en consumibles',
                ])->update();
            }
            if (!$table->hasColumn('service_id')) {
                $table->addColumn('service_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'segment_id',
                    'comment' => 'Consumible al que aplica (solo extra_units)',
                ])->update();
            }
            if (!$table->hasColumn('min_quantity')) {
                $table->addColumn('min_quantity', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'service_id',
                    'comment' => 'Cantidad mínima comprada para activar extra_units',
                ])->update();
            }
            if (!$table->hasColumn('extra_units_pct')) {
                $table->addColumn('extra_units_pct', 'decimal', [
                    'precision' => 5,
                    'scale' => 2,
                    'null' => true,
                    'default' => null,
                    'after' => 'min_quantity',
                    'comment' => 'Porcentaje de unidades extra (ej. 25.00 = +25%)',
                ])->update();
            }
            if (!$table->hasColumn('visible_in_portal')) {
                $table->addColumn('visible_in_portal', 'boolean', [
                    'default' => true,
                    'null' => false,
                    'after' => 'is_active',
                    'comment' => 'Visible en Portal Cliente',
                ])->update();
            }

            // FK service_id (idempotente: solo si no existe el constraint).
            $fkExists = $this->fetchRow(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'promotions'
                   AND CONSTRAINT_NAME = 'fk_promotions_service'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                 LIMIT 1",
            );
            if ($fkExists === false || $fkExists === null) {
                $this->table('promotions')
                    ->addForeignKey('service_id', 'services', 'id', [
                        'delete' => 'SET_NULL',
                        'update' => 'CASCADE',
                        'constraint' => 'fk_promotions_service',
                    ])
                    ->update();
            }
        }

        // ── promotion_scopes (alcance por segmento o cliente) ────────────────
        if (!$this->hasTable('promotion_scopes')) {
            $this->table('promotion_scopes', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Alcance de promociones por segmento o cliente específico (RF-2.8)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('promotion_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('segment_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                ])
                ->addColumn('client_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'comment' => 'Alcance por cliente; aplica aunque su segmento no tenga la promo',
                ])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addIndex(['promotion_id'], ['name' => 'idx_promotion_scopes_promotion'])
                ->addIndex(['segment_id'], ['name' => 'idx_promotion_scopes_segment'])
                ->addIndex(['client_id'], ['name' => 'idx_promotion_scopes_client'])
                ->addForeignKey('promotion_id', 'promotions', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_promotion_scopes_promotion',
                ])
                ->addForeignKey('segment_id', 'segments', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_promotion_scopes_segment',
                ])
                ->addForeignKey('client_id', 'clients', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_promotion_scopes_client',
                ])
                ->create();
        }

        // ── client_contacts (RF-1.5) ─────────────────────────────────────────
        if (!$this->hasTable('client_contacts')) {
            $this->table('client_contacts', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Contactos del cliente autorizados a Portal / Magic Links (RF-1.5)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('erp_contact_id', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'default' => null,
                ])
                ->addColumn('name', 'string', ['limit' => 140, 'null' => false])
                ->addColumn('email', 'string', ['limit' => 160, 'null' => false])
                ->addColumn('phone', 'string', [
                    'limit' => 30,
                    'null' => true,
                    'default' => null,
                ])
                ->addColumn('role', 'enum', [
                    'values' => ['billing', 'admin', 'technical'],
                    'default' => 'admin',
                    'null' => false,
                ])
                ->addColumn('receives_portal', 'boolean', [
                    'default' => true,
                    'null' => false,
                    'comment' => 'Autorizado para recibir Magic Links del Portal Cliente',
                ])
                ->addColumn('active', 'boolean', [
                    'default' => true,
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
                ->addIndex(
                    ['client_id', 'active'],
                    ['name' => 'idx_client_contacts_client_active'],
                )
                ->addIndex(['email'], ['name' => 'idx_client_contacts_email'])
                ->addForeignKey('client_id', 'clients', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_client_contacts_client',
                ])
                ->create();
        }
    }
}
