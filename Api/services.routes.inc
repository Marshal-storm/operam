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
 */

declare(strict_types=1);
