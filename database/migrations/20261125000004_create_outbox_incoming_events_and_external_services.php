<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bloque C Paso 1 — Servicios externos (RNF-8), Outbox transaccional (Pág. 37),
 * deduplicación de eventos entrantes (RNF-2) y tipo de cambio Banxico (Pág. 13).
 *
 * Esquema 100% inglés snake_case. Los secretos NUNCA se guardan aquí:
 * `secret_refs_json` solo contiene nombres de variables del cofre / `.env`.
 */
final class CreateOutboxIncomingEventsAndExternalServices extends AbstractMigration
{
    public function change(): void
    {
        // ── external_service_configs (RNF-8: cero hardcodeo) ─────────────────
        if (!$this->hasTable('external_service_configs')) {
            $this->table('external_service_configs', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Configuración global de conexiones externas (sin secretos en texto plano)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('service_key', 'enum', [
                    'values' => ['smtp', 'erp_master', 'slack', 'banxico', 'jobs_outbox'],
                    'null' => false,
                    'comment' => 'Llave técnica del servicio externo',
                ])
                ->addColumn('display_name', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('enabled', 'boolean', [
                    'default' => false,
                    'null' => false,
                    'comment' => 'Si es false, el worker simula los envíos (nunca llama al proveedor)',
                ])
                ->addColumn('mode', 'enum', [
                    'values' => ['sandbox', 'production'],
                    'default' => 'sandbox',
                    'null' => false,
                ])
                ->addColumn('config_json', 'json', [
                    'null' => true,
                    'comment' => 'Parámetros operativos NO sensibles (host, puerto, URL base, timeout…)',
                ])
                ->addColumn('secret_refs_json', 'json', [
                    'null' => true,
                    'comment' => 'Solo nombres de variables del cofre de secretos / .env',
                ])
                ->addColumn('last_tested_at', 'timestamp', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addColumn('last_test_status', 'enum', [
                    'values' => ['ok', 'error', 'never'],
                    'default' => 'never',
                    'null' => false,
                ])
                ->addColumn('last_test_message', 'string', [
                    'limit' => 255,
                    'null' => true,
                    'default' => null,
                ])
                ->addColumn('updated_by_user_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
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
                ->addIndex(['service_key'], [
                    'unique' => true,
                    'name' => 'uq_external_service_configs_service_key',
                ])
                ->addForeignKey('updated_by_user_id', 'users', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_external_service_configs_updated_by',
                ])
                ->create();
        }

        // ── outbox (cola transaccional saliente con idempotencia RNF-2) ──────
        if (!$this->hasTable('outbox')) {
            $this->table('outbox', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Mensajes salientes hacia ERP / pasarela / SMTP / Slack con reintentos',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('destination', 'enum', [
                    'values' => ['erp_issuer', 'erp_master', 'payment_gateway', 'smtp', 'slack'],
                    'null' => false,
                ])
                ->addColumn('operation', 'string', [
                    'limit' => 80,
                    'null' => false,
                    'comment' => 'create_preinvoice, stamp_cfdi, suspend_instance, adjust_users, send_email…',
                ])
                ->addColumn('idempotency_key', 'string', [
                    'limit' => 140,
                    'null' => false,
                    'comment' => 'Llave única de negocio: encolar dos veces no duplica (RNF-2)',
                ])
                ->addColumn('issuer_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'comment' => 'Emisor destino (ERP Emisor / IntegroPAY)',
                ])
                ->addColumn('segment_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'comment' => 'Alcance RBAC: usuarios con segmento fijo solo ven sus mensajes',
                ])
                ->addColumn('entity_type', 'string', [
                    'limit' => 60,
                    'null' => true,
                    'default' => null,
                ])
                ->addColumn('entity_id', 'string', [
                    'limit' => 50,
                    'null' => true,
                    'default' => null,
                ])
                ->addColumn('payload_json', 'json', ['null' => false])
                ->addColumn('status', 'enum', [
                    'values' => ['pending', 'processing', 'sent', 'failed'],
                    'default' => 'pending',
                    'null' => false,
                ])
                ->addColumn('attempts', 'tinyinteger', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                ])
                ->addColumn('max_attempts', 'tinyinteger', [
                    'signed' => false,
                    'default' => 3,
                    'null' => false,
                ])
                ->addColumn('next_attempt_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'UTC (RNF-11) — siguiente ventana de reintento',
                ])
                ->addColumn('sent_at', 'timestamp', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addColumn('response_json', 'json', ['null' => true, 'default' => null])
                ->addColumn('last_error', 'text', ['null' => true, 'default' => null])
                ->addColumn('slack_alerted_at', 'timestamp', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'UTC — evita alertas duplicadas al llegar al umbral de fallos (RNF-12)',
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
                ->addIndex(['idempotency_key'], [
                    'unique' => true,
                    'name' => 'uq_outbox_idempotency_key',
                ])
                ->addIndex(['status', 'next_attempt_at'], ['name' => 'idx_outbox_status_next_attempt'])
                ->addIndex(['destination', 'status'], ['name' => 'idx_outbox_destination_status'])
                ->addIndex(['created_at'], ['name' => 'idx_outbox_created_at'])
                ->addIndex(['segment_id'], ['name' => 'idx_outbox_segment'])
                ->addForeignKey('issuer_id', 'issuers', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_outbox_issuer',
                ])
                ->addForeignKey('segment_id', 'segments', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_outbox_segment',
                ])
                ->create();
        }

        // ── incoming_events (deduplicación de webhooks RNF-2) ────────────────
        if (!$this->hasTable('incoming_events')) {
            $this->table('incoming_events', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Eventos/webhooks recibidos; UNIQUE (origin, external_id) evita reprocesos',
            ])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('origin', 'enum', [
                    'values' => ['erp_issuer', 'integropay', 'erp_master'],
                    'null' => false,
                ])
                ->addColumn('external_id', 'string', ['limit' => 140, 'null' => false])
                ->addColumn('event_type', 'string', ['limit' => 80, 'null' => false])
                ->addColumn('payload_json', 'json', ['null' => false])
                ->addColumn('processed', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('processed_at', 'timestamp', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addColumn('error', 'text', ['null' => true, 'default' => null])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addIndex(['origin', 'external_id'], [
                    'unique' => true,
                    'name' => 'uq_incoming_events_origin_external',
                ])
                ->addIndex(['created_at'], ['name' => 'idx_incoming_events_created_at'])
                ->create();
        }

        // ── exchange_rates (tipo de cambio diario Banxico SIE) ───────────────
        if (!$this->hasTable('exchange_rates')) {
            $this->table('exchange_rates', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => 'Tipo de cambio por fecha y moneda (fuente Banxico SIE)',
            ])
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('rate_date', 'date', ['null' => false])
                ->addColumn('currency', 'char', ['limit' => 3, 'default' => 'USD', 'null' => false])
                ->addColumn('rate', 'decimal', [
                    'precision' => 10,
                    'scale' => 4,
                    'null' => false,
                    'comment' => 'MXN por unidad de la moneda (FIX)',
                ])
                ->addColumn('source', 'string', [
                    'limit' => 60,
                    'default' => 'banxico_sie',
                    'null' => false,
                ])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'null' => false,
                    'comment' => 'UTC (RNF-11)',
                ])
                ->addIndex(['rate_date', 'currency'], [
                    'unique' => true,
                    'name' => 'uq_exchange_rates_date_currency',
                ])
                ->create();
        }
    }
}
