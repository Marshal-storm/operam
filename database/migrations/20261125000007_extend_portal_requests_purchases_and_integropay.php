<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bloque D Paso 2 — IntegroPAY (ligas + webhook verificado), Tienda con promociones RF-2.8 y
 * Solicitudes desde el Portal del Cliente (RF-8.1).
 *
 *  - `service_requests.origin`: el valor legado `client_portal` se unifica como `portal`.
 *  - `service_requests.request_type`: agrega `plan_change` (bajas, cambio de frecuencia y
 *    cancelación) que la bandeja interna ejecuta manualmente sin tocar MRR al aprobar.
 *  - `service_requests.portal_request_type`: intención original del cliente en el portal.
 *  - `invoices.purchase_id`: vincula la factura/recibo con la compra de la Tienda.
 *  - `purchases.payment_method`: tarjeta (IntegroPAY) o transferencia SPEI.
 *  - `payment_links.transaction_id` / `paid_at`: evidencia de la transacción verificada.
 *  - `external_service_configs.service_key`: agrega `integropay` (URL base y timeouts, RNF-8).
 *
 * Usa `up()/down()` porque la unificación de `client_portal → portal` es una migración de datos
 * que `change()` no puede revertir automáticamente. Esquema 100% inglés snake_case.
 */
final class ExtendPortalRequestsPurchasesAndIntegropay extends AbstractMigration
{
    private const PORTAL_REQUEST_TYPES = [
        'add_users',
        'remove_users',
        'change_frequency',
        'cancel_service',
        'extra_service',
    ];

    public function up(): void
    {
        // ── service_requests.origin: client_portal → portal ─────────────────────────────────
        $requests = $this->table('service_requests');
        $requests->changeColumn('origin', 'enum', [
            'values' => ['internal', 'client_portal', 'portal'],
            'default' => 'internal',
            'null' => false,
        ])->update();
        $this->execute("UPDATE service_requests SET origin = 'portal' WHERE origin = 'client_portal'");
        $this->table('service_requests')->changeColumn('origin', 'enum', [
            'values' => ['internal', 'portal'],
            'default' => 'internal',
            'null' => false,
            'comment' => 'internal = bandeja ERP; portal = creada por el cliente en el Portal',
        ])->update();

        // ── service_requests.request_type + portal_request_type ─────────────────────────────
        $this->table('service_requests')->changeColumn('request_type', 'enum', [
            'values' => ['one_time', 'consumption', 'recurring_expansion', 'plan_change'],
            'null' => false,
            'comment' => 'plan_change = baja/cambio de frecuencia/cancelación ejecutada manualmente',
        ])->update();
        if (!$this->table('service_requests')->hasColumn('portal_request_type')) {
            $this->table('service_requests')->addColumn('portal_request_type', 'enum', [
                'values' => self::PORTAL_REQUEST_TYPES,
                'null' => true,
                'default' => null,
                'after' => 'request_type',
                'comment' => 'Intención original del cliente al solicitar desde el Portal (RF-8.1)',
            ])->update();
        }

        // ── invoices.purchase_id ────────────────────────────────────────────────────────────
        if (!$this->table('invoices')->hasColumn('purchase_id')) {
            $this->table('invoices')
                ->addColumn('purchase_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'request_id',
                    'comment' => 'Compra de la Tienda del Portal que originó el documento',
                ])
                ->addIndex(['purchase_id'], ['name' => 'idx_invoices_purchase'])
                ->addForeignKey('purchase_id', 'purchases', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_invoices_purchase',
                ])
                ->update();
        }

        // ── purchases.payment_method ────────────────────────────────────────────────────────
        if (!$this->table('purchases')->hasColumn('payment_method')) {
            $this->table('purchases')->addColumn('payment_method', 'enum', [
                'values' => ['card_integropay', 'spei'],
                'default' => 'card_integropay',
                'null' => false,
                'after' => 'origin',
                'comment' => 'Medio elegido por el cliente en el checkout de la Tienda',
            ])->update();
        }

        // ── payment_links.transaction_id + paid_at ──────────────────────────────────────────
        $links = $this->table('payment_links');
        if (!$links->hasColumn('transaction_id')) {
            $links->addColumn('transaction_id', 'string', [
                'limit' => 120,
                'null' => true,
                'default' => null,
                'after' => 'external_link_id',
                'comment' => 'Transacción verificada contra la pasarela (jamás confiar en el payload del webhook)',
            ])->update();
        }
        if (!$this->table('payment_links')->hasColumn('paid_at')) {
            $this->table('payment_links')->addColumn('paid_at', 'timestamp', [
                'null' => true,
                'default' => null,
                'after' => 'payment_id',
                'comment' => 'UTC (RNF-11)',
            ])->update();
        }

        // ── external_service_configs.service_key: integropay ────────────────────────────────
        $this->table('external_service_configs')->changeColumn('service_key', 'enum', [
            'values' => ['smtp', 'erp_master', 'slack', 'banxico', 'jobs_outbox', 'integropay'],
            'null' => false,
            'comment' => 'Llave técnica del servicio externo',
        ])->update();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM external_service_configs WHERE service_key = 'integropay'");
        $this->table('external_service_configs')->changeColumn('service_key', 'enum', [
            'values' => ['smtp', 'erp_master', 'slack', 'banxico', 'jobs_outbox'],
            'null' => false,
            'comment' => 'Llave técnica del servicio externo',
        ])->update();

        $links = $this->table('payment_links');
        if ($links->hasColumn('paid_at')) {
            $links->removeColumn('paid_at')->update();
        }
        if ($this->table('payment_links')->hasColumn('transaction_id')) {
            $this->table('payment_links')->removeColumn('transaction_id')->update();
        }

        if ($this->table('purchases')->hasColumn('payment_method')) {
            $this->table('purchases')->removeColumn('payment_method')->update();
        }

        if ($this->table('invoices')->hasColumn('purchase_id')) {
            $this->table('invoices')
                ->dropForeignKey('purchase_id', 'fk_invoices_purchase')
                ->removeIndexByName('idx_invoices_purchase')
                ->update();
            $this->table('invoices')->removeColumn('purchase_id')->update();
        }

        if ($this->table('service_requests')->hasColumn('portal_request_type')) {
            $this->table('service_requests')->removeColumn('portal_request_type')->update();
        }
        // Las solicitudes `plan_change` no existen en el esquema previo: se reclasifican como one_time.
        $this->execute("UPDATE service_requests SET request_type = 'one_time' WHERE request_type = 'plan_change'");
        $this->table('service_requests')->changeColumn('request_type', 'enum', [
            'values' => ['one_time', 'consumption', 'recurring_expansion'],
            'null' => false,
        ])->update();

        $this->table('service_requests')->changeColumn('origin', 'enum', [
            'values' => ['internal', 'client_portal', 'portal'],
            'default' => 'internal',
            'null' => false,
        ])->update();
        $this->execute("UPDATE service_requests SET origin = 'client_portal' WHERE origin = 'portal'");
        $this->table('service_requests')->changeColumn('origin', 'enum', [
            'values' => ['internal', 'client_portal'],
            'default' => 'internal',
            'null' => false,
        ])->update();
    }
}
