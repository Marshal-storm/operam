<?php
/**
 * Índice de rutas HTTP del framework operam/servicios (documentación viva).
 * El despacho real vive en `Api/index.php` (JWT + match de recurso).
 *
 * Módulo 4 Paso 1 — Solicitudes:
 *   GET    /api/requests                  — listado paginado + kpis (can_view)
 *   GET    /api/requests/pending-count    — badge Sidebar (can_view)
 *   GET    /api/requests/{id}             — detalle + items (can_view)
 *   POST   /api/requests                  — alta interna SOL-XXXX (can_create)
 *   POST   /api/requests/{id}/quote       — cotizar / ajustar (can_edit)
 *   POST   /api/requests/{id}/approve     — aprobar (+ expansión / factura) (can_edit)
 *   POST   /api/requests/{id}/reject      — rechazar (can_edit)
 *   POST   /api/requests/{id}/cancel      — cancelar (can_delete)
 *   DELETE /api/requests/{id}             — cancelar (can_delete)
 *
 * Alias: /api/solicitudes → requests
 *
 * Módulo 4 Paso 2 — Cobranza:
 *   GET    /api/collections/invoices              — listado + kpis + aging (can_view)
 *   GET    /api/collections/contracts             — cartera consolidada (can_view)
 *   GET    /api/collections/invoices/{id}         — detalle + payments (can_view)
 *   POST   /api/collections/invoices              — emitir FAC-XXXX (can_create)
 *   POST   /api/collections/invoices/from-request/{requestId} — factura desde SOL (can_create)
 *   POST   /api/collections/invoices/{id}/payments — registrar pago (can_edit)
 *   POST   /api/collections/invoices/{id}/cancel   — cancelar sin pagos (can_delete)
 *   POST   /api/collections/payments/{id}/reverse  — revertir pago (can_edit, sin DELETE)
 *   POST   /api/collections/run-suspension-check   — suspensión +90d (can_edit)
 *
 * Alias: /api/cobranza → collections
 *
 * Bloque A — Utilidad por segmento (RF-10 / RF-11.5):
 *   GET    /api/segment-profitability/summary          — KPIs + unit economics + tendencia 6 meses
 *   GET    /api/segment-profitability/costs            — listado paginado segment_costs
 *   POST   /api/segment-profitability/costs            — alta de costo mensual
 *   PUT    /api/segment-profitability/costs/{id}       — editar costo
 *   DELETE /api/segment-profitability/costs/{id}       — eliminar costo
 *   GET    /api/segment-profitability/non-recurring    — ingresos one_time / consumption
 *   GET    /api/segment-profitability/exceptions        — reporte Dirección (movements + requests)
 *
 * Alias: /api/utilidad-segmento → segment-profitability
 * RBAC: segment_profitability (CRUD completo para captura de costos)
 *
 * Bloque B Paso 1 — Saldos / bolsas / consumos + API de instancias (RF-4):
 *   GET    /api/balances/contract/{id}     — bolsas + historiales
 *   POST   /api/balances/consume           — consumo manual (Soporte)
 *   POST   /api/balances/grant             — acreditar bolsa (Admin)
 *   POST   /api/instances/{id}/rotate-token
 *   GET    /api/instance-api/balance?service_code=
 *   POST   /api/instance-api/consumption
 *   GET    /api/instance-api/collection-status
 *   GET    /api/instance-api/portal-link
 *
 * Bloque C Paso 1 — Servicios externos, Outbox y Webhooks (RNF-2 / RNF-8 / RNF-12):
 *   GET    /api/external-services                — 5 servicios globales + estado de referencias (can_view)
 *   PUT    /api/external-services/{serviceKey}   — config + nombres de referencia del cofre (can_edit, alcance Todos)
 *   POST   /api/external-services/{serviceKey}/test — prueba sandbox/producción (can_edit, alcance Todos)
 *   GET    /api/outbox                           — cola paginada + KPIs (can_view, filtrada por segmento)
 *   GET    /api/outbox/{id}                      — detalle con payload/respuesta (can_view)
 *   POST   /api/outbox/{id}/retry                — reintento manual (can_edit, alcance Todos)
 *   POST   /api/outbox/process-now               — procesar cola ahora (can_edit, alcance Todos)
 *   GET    /api/incoming-events                  — webhooks recibidos, solo lectura (can_view, alcance Todos)
 *
 * Alias: /api/servicios-externos → external-services · /api/webhooks → incoming-events
 * RBAC: config_external_services (Ver + Editar)
 * Worker CLI: php scripts/run_outbox_worker.php [--limit=25] [--drain]
 *
 * Bloque C Paso 2 — Jobs de GCP, reactivación automática y ajuste masivo (RF-3.6 / RF-5 / RF-6.3):
 *   POST   /api/jobs/run/{jobName|all}           — Cloud Scheduler con header X-Job-Secret (hash_equals contra
 *                                                  SecretResolver::get(GCP_JOB_SECRET_REF)) o JWT
 *                                                  config_external_services.edit + alcance Todos
 *   GET    /api/jobs/runs                        — historial paginado + última corrida por job (can_view)
 *   GET    /api/contracts/bulk-price-adjustment/options — servicios recurrentes ajustables (contracts.edit)
 *   POST   /api/contracts/bulk-price-adjustment/preview — vista previa UI-9 sin escritura (contracts.edit)
 *   POST   /api/contracts/bulk-price-adjustment/apply   — aplica lote AJ-YYYY-XXX (contracts.edit, motivo ≥5)
 *
 * Jobs: daily_billing · reset_included_balances · daily_collections · process_outbox
 * CLI (Cloud Run Jobs): php scripts/jobs/run_job.php <jobName|all>
 */

declare(strict_types=1);
