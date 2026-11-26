<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bloque D Paso 1 — Portal del Cliente: instrucciones SPEI por emisor (RF-7.6), visibilidad de
 * servicios en la Tienda pública (RF-2.1), compras del portal (Pág. 35) y ligas de pago
 * IntegroPAY (Pág. 36).
 *
 * Esquema 100% inglés snake_case. Montos DECIMAL(14,2) (RNF-1) y timestamps UTC (RNF-11).
 * Los secretos NUNCA se guardan aquí: `integropay_webhook_token_ref` solo almacena el NOMBRE
 * de la variable del cofre de secretos (RNF-8).
 */
final class CreatePortalPurchasesPaymentLinksAndSpeiFields extends AbstractMigration
{
    public function change(): void
    {
        // ── issuers: instrucciones SPEI + referencia del token de webhook IntegroPAY ─────────
        if ($this->hasTable('issuers')) {
            $issuers = $this->table('issuers');
            if ($this->shouldAddColumn($issuers, 'spei_bank_name')) {
                $issuers->addColumn('spei_bank_name', 'string', [
                    'limit' => 100,
                    'null' => true,
                    'default' => null,
                    'after' => 'integropay_device_token_ref',
                    'comment' => 'Banco receptor de transferencias SPEI (se muestra en el Portal Cliente)',
                ])->update();
            }
            if ($this->shouldAddColumn($issuers, 'spei_clabe')) {
                $issuers->addColumn('spei_clabe', 'string', [
                    'limit' => 18,
                    'null' => true,
                    'default' => null,
                    'after' => 'spei_bank_name',
                    'comment' => 'CLABE interbancaria de 18 dígitos',
                ])->update();
            }
            if ($this->shouldAddColumn($issuers, 'spei_account_holder')) {
                $issuers->addColumn('spei_account_holder', 'string', [
                    'limit' => 160,
                    'null' => true,
                    'default' => null,
                    'after' => 'spei_clabe',
                    'comment' => 'Beneficiario de la cuenta SPEI',
                ])->update();
            }
            if ($this->shouldAddColumn($issuers, 'spei_reference_hint')) {
                $issuers->addColumn('spei_reference_hint', 'string', [
                    'limit' => 160,
                    'null' => true,
                    'default' => null,
                    'after' => 'spei_account_holder',
                    'comment' => 'Referencia / concepto sugerido para identificar el pago',
                ])->update();
            }
            if ($this->shouldAddColumn($issuers, 'integropay_webhook_token_ref')) {
                $issuers->addColumn('integropay_webhook_token_ref', 'string', [
                    'limit' => 120,
                    'null' => true,
                    'default' => null,
                    'after' => 'spei_reference_hint',
                    'comment' => 'Nombre de la variable del cofre con el token secreto de la URL del webhook IntegroPAY (RNF-8)',
                ])->update();
            }
        }

        // ── services: visibilidad en la Tienda pública del Portal Cliente ───────────────────
        if ($this->hasTable('services')) {
            $services = $this->table('services');
            if ($this->shouldAddColumn($services, 'is_public')) {
                $services->addColumn('is_public', 'boolean', [
                    'default' => false,
                    'null' => false,
                    'after' => 'is_active',
                    'comment' => 'Si es true aparece en la Tienda del Portal Cliente',
                ])->update();
            }
        }

        // ── purchases: compras iniciadas desde el portal o internamente ─────────────────────
        if ($this->shouldCreateTable('purchases')) {
            $this->table('purchases', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Compras de servicios/folios del Portal Cliente (Pág. 35)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addColumn('status', 'enum', [
                    'values' => ['pending', 'paid', 'cancelled', 'expired'],
                    'default' => 'pending',
                    'null' => false,
                ])
                ->addColumn('subtotal', 'decimal', ['precision' => 14, 'scale' => 2, 'null' => false])
                ->addColumn('tax_amount', 'decimal', ['precision' => 14, 'scale' => 2, 'null' => false])
                ->addColumn('total', 'decimal', ['precision' => 14, 'scale' => 2, 'null' => false])
                ->addColumn('currency', 'char', ['limit' => 3, 'default' => 'MXN', 'null' => false])
                ->addColumn('origin', 'enum', [
                    'values' => ['portal', 'internal'],
                    'default' => 'portal',
                    'null' => false,
                ])
                ->addColumn('promotion_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addColumn('gifted_units', 'integer', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                    'comment' => 'Unidades regaladas por promoción de unidades extra (RF-2.8)',
                ])
                ->addColumn('details_json', 'json', [
                    'null' => true,
                    'comment' => 'Renglones comprados (servicio, cantidad, precio) congelados al comprar',
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
                ->addIndex(['client_id', 'status'], ['name' => 'idx_purchases_client_status'])
                ->addIndex(['contract_id'], ['name' => 'idx_purchases_contract'])
                ->addForeignKey('client_id', 'clients', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_purchases_client',
                ])
                ->addForeignKey('contract_id', 'contracts', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_purchases_contract',
                ])
                ->addForeignKey('promotion_id', 'promotions', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_purchases_promotion',
                ])
                ->create();
        }

        // ── payment_links: ligas de pago con tarjeta (IntegroPAY) ───────────────────────────
        if ($this->shouldCreateTable('payment_links')) {
            $this->table('payment_links', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Ligas de pago generadas en la pasarela (Pág. 36)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('issuer_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('purchase_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addColumn('contract_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addColumn('amount', 'decimal', ['precision' => 14, 'scale' => 2, 'null' => false])
                ->addColumn('currency', 'char', ['limit' => 3, 'default' => 'MXN', 'null' => false])
                ->addColumn('concept', 'string', ['limit' => 200, 'null' => false])
                ->addColumn('gateway', 'enum', [
                    'values' => ['integropay'],
                    'default' => 'integropay',
                    'null' => false,
                ])
                ->addColumn('external_link_id', 'string', [
                    'limit' => 120,
                    'null' => true,
                    'default' => null,
                    'comment' => 'Identificador de la liga en la pasarela (idempotencia de webhooks)',
                ])
                ->addColumn('url', 'string', ['limit' => 500, 'null' => false])
                ->addColumn('status', 'enum', [
                    'values' => ['created', 'paid', 'expired', 'cancelled'],
                    'default' => 'created',
                    'null' => false,
                ])
                ->addColumn('expires_at', 'timestamp', ['null' => false, 'comment' => 'UTC (RNF-11)'])
                ->addColumn('payment_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
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
                ->addIndex(['client_id', 'status'], ['name' => 'idx_payment_links_client_status'])
                ->addIndex(['external_link_id'], ['name' => 'idx_payment_links_external'])
                ->addIndex(['expires_at'], ['name' => 'idx_payment_links_expires'])
                ->addForeignKey('issuer_id', 'issuers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_payment_links_issuer',
                ])
                ->addForeignKey('client_id', 'clients', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_payment_links_client',
                ])
                ->addForeignKey('purchase_id', 'purchases', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_payment_links_purchase',
                ])
                ->addForeignKey('contract_id', 'contracts', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_payment_links_contract',
                ])
                ->addForeignKey('payment_id', 'payments', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_payment_links_payment',
                ])
                ->create();
        }

        // ── payment_link_documents: facturas que cubre cada liga ────────────────────────────
        if ($this->shouldCreateTable('payment_link_documents')) {
            $this->table('payment_link_documents', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Documentos (facturas) cubiertos por una liga de pago (Pág. 36)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('payment_link_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('invoice_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('amount', 'decimal', [
                    'precision' => 14,
                    'scale' => 2,
                    'null' => false,
                    'comment' => 'Monto de la liga aplicado a esta factura',
                ])
                ->addIndex(['payment_link_id'], ['name' => 'idx_payment_link_documents_link'])
                ->addIndex(['invoice_id'], ['name' => 'idx_payment_link_documents_invoice'])
                ->addForeignKey('payment_link_id', 'payment_links', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_payment_link_documents_link',
                ])
                ->addForeignKey('invoice_id', 'invoices', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_payment_link_documents_invoice',
                ])
                ->create();
        }
    }

    /**
     * Al migrar solo agrega la columna si falta (idempotente); al hacer rollback siempre la
     * registra para que Phinx pueda invertirla (de lo contrario `change()` no revertiría nada).
     */
    private function shouldAddColumn(\Phinx\Db\Table $table, string $column): bool
    {
        return !$this->isMigratingUp() || !$table->hasColumn($column);
    }

    /** Mismo criterio que {@see shouldAddColumn()} pero para tablas completas. */
    private function shouldCreateTable(string $tableName): bool
    {
        return !$this->isMigratingUp() || !$this->hasTable($tableName);
    }
}
