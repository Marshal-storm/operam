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
 *   POST   /api/requests/{id}/approve     — aprobar (+ expansión si recurrente) (can_edit)
 *   POST   /api/requests/{id}/reject      — rechazar (can_edit)
 *   POST   /api/requests/{id}/cancel      — cancelar (can_delete)
 *   DELETE /api/requests/{id}             — cancelar (can_delete)
 *
 * Alias: /api/solicitudes → requests
 */

declare(strict_types=1);
