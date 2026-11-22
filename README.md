# Operam — Plataforma Central de Contratos y Suscripciones

Manual técnico oficial de onboarding para desarrolladores.  
**Frontend:** React + TypeScript + Vite + Tailwind + shadcn/ui · iOS Liquid Glass · tipografía Ubuntu / DM Sans / DM Mono.  
**Backend:** PHP 8.3 + Illuminate 11 + Phinx + `operam/servicios` + JWT HS256.  
**Shell:** Sidebar full-height + Header en columna derecha · barra arcoíris Operam + candado de pantalla.

---

## 1. Resumen y Arquitectura

Operam es la **plataforma central de contratos y suscripciones** para los productos **Operam** y **Construsync** (y segmentos adicionales).

### Modo de datos (política vigente)

- **Login, Catálogo y módulos futuros:** 100% API real + MySQL. **Prohibido** mockups, switches `Mockup / API Real` o datos en memoria.
- **Única excepción — Dashboard:** conserva el switch **Mockup | API Real** solo para demos del Puente de MRR (`DashboardPage` + `mockDashboardData`).
- API Real consume `VITE_API_URL` / `VITE_API_BASE_URL` con Bearer JWT (`POST /api/login` → `LoginService`).

### Regla de negocio inmutable (MRR)

El **MRR nace exclusivamente de la bitácora `movements`**.  
No se recalcula desde estado mutable de contratos: los deltas del Puente de MRR se derivan de esa bitácora (RNF-3). Montos siempre `DECIMAL(14,2)` / `brick-math` — **prohibido `float`**.

---

## 2. Requisitos del Sistema

| Componente | Versión / detalle |
|------------|-------------------|
| Node.js | **20+** |
| PHP | **8.3** con `declare(strict_types=1);`, `ext-bcmath`, `ext-pdo_mysql` |
| Composer | **2+** |
| MySQL | **8.0+** |
| Framework | `operam/servicios` (núcleo en `System/`) + Illuminate **10/11** (Eloquent Capsule) |
| Frontend | React 19, Vite, Tailwind v4, TanStack Query, Recharts |

---

## 3. Guía de Instalación Paso a Paso (Quickstart)

### Paso 1 — Clonar y entorno

```bash
git clone <url-del-repo> operam
cd operam
cp .env.example .env
```

Edita `.env`: `DB_*`, `JWT_TOKEN_KEY` (llave larga aleatoria), `VITE_API_URL`.

### Paso 2 — Frontend

```bash
npm install
npm run dev
```

App en `http://localhost:5173`.

### Paso 3 — Backend y base de datos (Phinx)

```bash
composer install
```

En MySQL:

```sql
CREATE DATABASE operam_contratos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Migraciones y seeders (esquema **100% inglés** en `snake_case`):

```bash
# Preferir PHP 8.3 de Laragon si el PATH trae otra versión
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phinx migrate
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phinx seed:run
```

Reset limpio local (DROP tablas legacy en español + actuales, luego migrate/seed):

```bash
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe database/scripts/reset_english_schema.php
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phinx migrate
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phinx seed:run
```

Vaciar solo datos operativos/comerciales (conserva users, roles, permisos, sesiones, segmentos, `system_settings` y `phinxlog`):

```bash
php scripts/reset_operational_data.php
```

(Alternativa Composer: `composer migrate` / `composer seed`.)

Tablas canónicas: `segments`, `issuers`, `clients`, `contracts`, `movements`, `collection_statuses`, `monthly_mrr`, `users`, `active_sessions`, `auth_attempts`, `services`, `instances`, `price_lists`, `price_list_items`, `promotions`, `roles`, `system_modules`, `role_permissions`. Triggers RNF-3: `trg_movements_prevent_update` / `trg_movements_prevent_delete`.

### Paso 4 — Generador CLI de `operam/servicios`

Cuando el CLI del framework esté disponible en el repo:

```bash
php console.php create <Service> --tbl=<tabla>
php console.php build
```

`build` regenera `Api/services.routes.inc`. Hasta entonces, los servicios viven en `System/Services/` y se enrutan desde `Api/index.php`.

### Paso 5 — API PHP local

```bash
php -S localhost:8080 -t Api
```

El front controller (`Api/index.php`) carga `.env` vía `backend/bootstrap/env.php` y arranca Capsule Eloquent.

Build de verificación frontend:

```bash
npm run build
```

### Paso 6 — Suite PHPUnit (reglas financieras RNF-1 / RF-2 / RNF-3)

```bash
# Preferir el PHP 8.3 de Laragon si el PATH trae otra versión
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phpunit
# o vía Composer:
composer test
```

Suite oficial en `tests/Unit/FinancialRulesTest.php` (configurada en `phpunit.xml`):
1. Puente de MRR oficial sep 2026 → Fin `1284500.00` / neto `+30200.00`.
2. RF-2 paquetes de 5 usuarios → `1860.00` / ahorro `300.00`.
3. Reversión inmutable RNF-3 → deltas que se cancelan a `0.00`.
4. `MovementsModel` bloquea UPDATE/DELETE con `RuntimeException`.
5. Acumulación `monthly_mrr` con `bcadd` (RF-10) sin pérdida de centavos.

---

## 4. Credenciales de Acceso (Desarrollo / Seeders)

Usuarios del `EnterpriseUsersSeeder` + vínculo RBAC (`RbacRolesAndPermissionsSeeder`):

| Email | Password | Rol RBAC | Segmento |
|-------|----------|----------|----------|
| `admin@operam.mx` | `Operam2026*` | Administrador General (`admin_general`) | Todos (`segment_id = NULL`) |
| `operador@operam.mx` | `Operam2026*` | Operador Operam (`operador_comercial`) | Solo Operam |
| `supervisor@construsync.mx` | `Constru2026*` | Supervisor Construsync (`supervisor_segmento`) | Solo Construsync |

### Flujo JWT empresarial + RBAC

1. `POST /api/login` → `LoginService` valida credenciales contra `users` (solo `role_id`, sin columna legacy `rol`).
2. Firma **JWT HS256** con `JWT_TOKEN_KEY` (`iss` = `JWT_TOKEN_ISSUER`, `exp` = `DEF_EXPIRATION_TIME`).
3. Claim `jti` incluye `name`, `role_id`, `role_name`, `role_code`, `segment_id`, `segment_name` (`Todos` si NULL) y mapa `permissions` con `view` / `create` / `edit` / `delete` por `module_key` (`contracts`, `clients`, `config_catalog`, `config_issuers`, `config_roles`, etc.).
4. Sesión revocable en `active_sessions` (`is_revoked`, `expires_at`, `user_id`). Cambiar `role_id` / `segment_id` / `is_active` o la matriz `role_permissions` revoca sesiones afectadas → HTTP 401.
5. Rate limiting de fallos en `auth_attempts` (`is_successful`, bloqueo temporal 429).
6. Rutas protegidas en `AppService::before_get` excepto `$freeuris` (`login`).
7. Perfil: `GET /api/me` (payload en inglés). Roles (`config_roles`): `/api/roles`, `/api/roles/modules`, `PUT /api/roles/{id}/permissions`. Usuarios (`config_users`): `/api/users`.
8. Auditoría: las escrituras de Módulos 1/2 registran `AuditLogger` tras éxito. Lectura `GET /api/audit-logs`. Configuración `GET|PUT|PATCH /api/system-settings` y depuración `POST /api/system-settings/purge-audit-logs`.

En UI: botón **Autocompletar credenciales de prueba** en Login (rellena el formulario; el login sigue siendo HTTP real).

### Migraciones / seeders RBAC

```bash
# Preferir el PHP 8.3 de Laragon si el PATH trae otra versión
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phinx migrate
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phinx seed:run
# o solo el seeder RBAC:
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phinx seed:run -s RbacRolesAndPermissionsSeeder
```

---

## 5. Estándares de Desarrollo (`.cursorrules`)

Obligatorios para quien continúe el proyecto:

- **Código** (variables, funciones, clases, archivos, payloads JSON): 100% **inglés**.
- **Comentarios**: español. **UI**: español (`es-MX`).
- **Base de datos (inquebrantable):** tablas, columnas y valores `ENUM` **solo inglés** `snake_case`. Prohibido Spanglish en MySQL/Phinx.
- Dinero: `DECIMAL(14,2)` / Money — **sin floats**.
- Tipografía: **Ubuntu** (títulos/KPI), **DM Sans** / Ubuntu (UI), **DM Mono** + `tabular-nums` (folios/montos).
- Loading: barra arcoíris Operam + candado de pantalla (sin modal central).
- Tema dual Light crisp / True Dark OLED; shell Sidebar `h-screen` + Header solo columna derecha.
- DB: solo migraciones/seeders Phinx — **prohibido `.sql` sueltos**.
- Auth: JWT del framework — **prohibido Sanctum**.
- **Cero mockups** fuera del Dashboard (Login, Catálogo, Contratos, Clientes, Cobranza, Solicitudes).
- **Kit atómico (Single Source of Truth):** prohibido `<select>` / `<input>` / `<textarea>` / `<button>` nativos en `src/pages/**` o componentes de negocio. Importar solo desde `src/components/ui/` (`AppCard`, `AppInput`, `AppSelect`, `AppButton`, `AppBadge`, `AppModal`, `AppSwitch`, `AppTableWrapper`, `AppFilterField`). Un cambio visual global = un archivo del kit.
- **RBAC multinivel (18 `module_key`):** operativos (`main`: `dashboard`…`sales_promotions` + `segment_profitability`) y `config_*` (`configuration`); sin permiso monolítico `configuration`.
- **Filtros con `<label>` visible:** todo criterio de toolbar/tabla usa `AppFilterField` (o equivalente); catálogos dinámicos desde MySQL.
- **Auditoría automática (`audit_logs`):** toda escritura vía `System/Support/AuditLogger.php`; secretos redactados; módulo Sistema siempre audita; depuración solo con `@allow_audit_purge`.

Detalle completo: archivo `.cursorrules` en la raíz.

---

## 6. Estado de Módulos (Roadmap)

- [x] **Módulo 0:** Cimientos, Stack, Phinx y Reglas Senior.
- [x] **Módulo 1:** Dashboard General Interno, Puente de MRR, Cartera y Seguridad JWT Nivel Empresa.
- [x] **Módulo 2 (checkpoint oficial cerrado):** Catálogos, Listas de Precios con escalas, Promociones, Emisores, Instancias, Empresa, Preferencias, RBAC multinivel, BD 100% inglés, Kit Atómico, segmentos dinámicos, Sistema (`config_system`), Bitácora global `audit_logs` (`config_audit_logs`), Spinner + Toasts, cero mockups fuera del Dashboard.
- [x] **Infra transversal M2:** RBAC por `module_key`, Kit Atómico `src/components/ui/`, `AuditLogger` + `system_settings` / `audit_logs`.
- [x] **Módulo 3 (checkpoint oficial cerrado):** Directorio de Clientes, Motor de Contratos, Operaciones de Ciclo de Vida y Bitácora Inmutable (RF-3) — pasos 1–3: `/clientes`, `/contratos`, `/contratos/nuevo`, `/contratos/:id`, RF-2 dinámico, cupón quemado, BCMath y `movements` append-only.
- [x] **Módulo 4 Paso 1:** Bandeja de Solicitudes (`/solicitudes`) — `service_requests` / `service_request_items`, cotizar/aprobar/rechazar/cancelar (`can_delete`), solicitudes mixtas (solo recurring toca MRR), trazabilidad `Origen: SOL-XXXX` en bitácora, badge Sidebar en vivo, RBAC `requests`.
- [ ] **Módulo 4 (resto):** Cobranza, Saldos, Portal Cliente con Magic Links e IntegroPAY (RF-4 a RF-8).

---

## 7. Índice de Archivos Activos del Proyecto

### Raíz

| Archivo | Descripción |
|---------|-------------|
| `.cursorrules` | Reglas senior: BD inglés, Kit Atómico §3.1, RBAC §11, filtros con label, cero mockups, Spinner+Toasts, Liquid Glass, TTS. |
| `.env.example` | Plantilla de variables Vite / MySQL / JWT. |
| `.gitignore` | Excluye `node_modules/`, `vendor/`, `dist/`, `.env`, temporales. |
| `.oxlintrc.json` | Oxlint. |
| `composer.json` / `composer.lock` | Dependencias PHP 8.3 + `phpunit/phpunit` ^11 en require-dev. |
| `phinx.php` / `phpunit.xml` | Phinx y PHPUnit (suite `tests/Unit`). |
| `tests/Unit/FinancialRulesTest.php` | Suite oficial RNF-1 / RF-2 / RNF-3 (Puente MRR, paquetes de 5, reversión, inmutabilidad). |
| `tests/Unit/AuditLoggerTest.php` | Sanitización de secretos y diff de auditoría. |
| `tests/Unit/ClientRulesTest.php` | Normalización de RFC y herencia de días de crédito por segmento. |
| `tests/Unit/ContractPricingEngineTest.php` | Motor BCMath de contrato: RF-2, anual/12, USD→MXN y promo %. |
| `tests/Unit/ContractLifecycleEngineTest.php` | Deltas BCMath de ciclo de vida: expansión, contracción, precio, frecuencia, transfer=0, churn, reactivación. |
| `tests/Unit/BurnedCouponLifecycleTest.php` | Cupón quemado: alta 10% + ajuste/contracción → `discount_amount=0`, delta BCMath exacto, sin subtotales negativos. |
| `tests/Unit/MovementReversalRulesTest.php` | Reversión contable RNF-3: delta inverso BCMath, impacto vs MRR del contrato, bloqueo MRR negativo y doble reversión. |
| `tests/Unit/ServiceRequestRulesTest.php` | Solicitudes M4: one_time sin MRR, expansión con `request_id`, bloqueo por cartera sin excepción, RF-2. |
| `scripts/reset_contracts_only.php` | TRUNCATE solo tablas de contratos + `promotions.current_uses=0`; verifica triggers RNF-3. |
| `index.html` | Entrada Vite + Google Fonts (Ubuntu / DM Sans / DM Mono). |
| `tailwind.config.js` | Referencia `fontFamily` oficial (Tailwind v4 usa `@theme` en CSS). |
| `package.json` / `package-lock.json` | Dependencias npm. |
| `README.md` | Manual de onboarding + índice vivo. |
| `tsconfig*.json` / `vite.config.ts` | TypeScript y Vite. |
| `Api/` | Entrada HTTP del framework. |
| `System/` | Núcleo `operam/servicios`. |
| `backend/` | Eloquent auxiliar + Money + carga `.env`. |
| `database/` | Migraciones y seeders Phinx. |
| `public/` | Assets estáticos. |
| `src/` | Frontend React. |

### `src/` (frontend)

| Archivo | Descripción |
|---------|-------------|
| `main.tsx` / `App.tsx` | Montaje + providers + `GlobalLoadingOverlay` + router. |
| `index.css` | Tokens Liquid Glass + tipografía Operam + rainbow bar. |
| `context/ThemeContext.tsx` | Tema `system` \| `light` \| `dark` + `localStorage`. |
| `context/AuthContext.tsx` | Sesión JWT global (solo API real) + reexport `usePermissions`. |
| `hooks/usePermissions.ts` | Helpers `canView/Create/Edit/Delete` + alcance por `segment_id` / `isSegmentLocked`. |
| `hooks/useSegments.ts` | Hook central TanStack Query → `GET /api/segments` (mapa id→nombre, opciones de filtro). |
| `hooks/useSegmentLock.ts` | Filtro de segmento dinámico + bloqueo JWT (sin nombres quemados). |
| `components/ui/AppFilterBar.tsx` | Estándar homologado de filtros (§3.2): buscador cápsula + selects píldora con labels en mayúsculas + Limpiar filtros. |
| `components/ui/AppFilterField.tsx` | Campo suelto con label (complemento; listados usan `AppFilterBar`). |
| `components/ui/AppRowActionsMenu.tsx` | Menú `⋮` de acciones de fila (§3.3) + encabezado `ACCIONES`; Liquid Glass Radix Dropdown. |
| `context/ScreenPreferencesContext.tsx` | pageSize (10/25/50/100) + densidad + `toastPosition` (6 anclas) en `localStorage`. |
| `context/LoadingLockContext.tsx` | Candado global + `withScreenLock` / start/stop. |
| `context/ToastContext.tsx` | Toasts Liquid Glass apilados (baraja iOS, expand/pause al hover); posición desde ScreenPreferences; `useToast`. |
| `components/ui/GlobalLoadingOverlay.tsx` | Barra arcoíris + velo + Spinner Aura Operam (conic-gradient sincronizado) en mutaciones. |
| `components/ui/ThemeToggleSwitch.tsx` | Switch iOS luna/sol (primitivo del kit). |
| `components/ui/appTokens.ts` | Tokens compartidos de campo/tarjeta/popover (borde, focus, sombra). |
| `components/ui/AppButton.tsx` | Botón atómico (`primary` / `secondary` / `outline` / `ghost` / `danger` + `isLoading`). |
| `components/ui/AppInput.tsx` | Input / moneda / búsqueda / textarea atómicos. |
| `components/ui/AppSelect.tsx` | Select Radix Liquid Glass; buscador si >6 opciones; semáforo por opción. |
| `components/ui/AppCard.tsx` | Tarjeta Liquid Glass (`Header` / `Content` / `Footer`). |
| `components/ui/AppBadge.tsx` | Píldoras semáforo (`success` \| `warning` \| `danger` \| `info` \| `neutral` \| `slate`); siempre `whitespace-nowrap`. |
| `components/ui/AppModal.tsx` | Diálogo accesible sobre `@radix-ui/react-dialog`. |
| `components/ui/AppSwitch.tsx` | Interruptor estilo iOS. |
| `components/ui/AppTableWrapper.tsx` | Contenedor de tabla (toolbar cristal, cuerpo, pie). |
| `components/ui/StatusPill.tsx` / `IosSwitch.tsx` / `button.tsx` / `dialog.tsx` | Aliases de compatibilidad → kit `App*`. |
| `components/ui/skeleton.tsx` | Skeleton de carga. |
| `components/ui/PaginationBar.tsx` | Paginación con `AppSelect` + `AppButton` (total, pageSize, páginas). |
| `components/auth/ProtectedRoute.tsx` | Barrera de login + `requiredModule` RBAC. |
| `components/auth/AccessRestrictedView.tsx` | Vista “Acceso restringido por tu rol”. |
| `components/layout/InternalLayout.tsx` | Sidebar filtrado por `canView` + padre Configuración si hay ≥1 `config_*` + badge de alcance. |
| `components/auth/SessionSecurityBridge.tsx` | Escucha 401 globales → toast + logout + `/login`. |
| `components/dashboard/MrrBridgeChart.tsx` | Puente MRR con colores por tema. |
| `components/dashboard/AgingPortfolioCard.tsx` | Cartera por antigüedad. |
| `components/dashboard/RecentMovementsTable.tsx` | Tabla/tarjetas + status pills. |
| `pages/LoginPage.tsx` | Login 2 columnas Liquid Glass → `POST /api/login`. |
| `pages/DashboardPage.tsx` | Dashboard KPIs + switch Mockup/API (única excepción). |
| `pages/ClientsPage.tsx` | `/clientes` — directorio CRUD (KPIs, filtros dinámicos, tabla paginada, modales crear/editar/detalle) con Kit Atómico (`clients`). |
| `pages/RequestsPage.tsx` | `/solicitudes` — bandeja M4 Paso 1: KPIs, `AppFilterBar`, tabla/tarjetas UI-4, cotizar/aprobar/rechazar (`requests`). |
| `components/requests/NewRequestModal.tsx` | Modal RHF+Zod de alta interna con preview UI-9 (total vs impacto MRR / RF-2). |
| `components/requests/RequestDetailModal.tsx` | Detalle + aprobación (excepción de cartera) + rechazo con caja UI-9. |
| `components/requests/RequestImpactPreview.tsx` | Caja UI-9 Total / Impacto MRR (no suma vs expansión). |
| `types/request.ts` | Tipos de solicitudes, ítems, KPIs y payloads create/quote/approve/reject. |
| `services/requestsService.ts` | HTTP JWT contra `/api/requests` (+ `pending-count`, quote/approve/reject). |
| `hooks/useRequests.ts` | TanStack Query listado/detalle + mutaciones con `useLoadingLock` e invalidación de badge/contratos/dashboard. |
| `pages/ContractsPage.tsx` | `/contratos` — listado paginado servidor, 4 KPIs, `AppFilterBar`, tabla/tarjetas UI-4 (`contracts`). |
| `pages/NewContractPage.tsx` | `/contratos/nuevo` — formulario 2 columnas + panel sticky MRR (UI-9), RF-2 en vivo, promo y alta atómica. |
| `pages/ContractDetailPage.tsx` | `/contratos/:id` — detalle, KPIs, líneas, bitácora movements, ciclo de vida y reversión contable RNF-3 (`contracts`). |
| `components/contracts/LifecycleOperationModal.tsx` | Modales RHF+Zod de operaciones con preview de impacto MRR (UI-9). |
| `components/contracts/ReverseMovementModal.tsx` | Modal RHF+Zod de reversión contable con preview UI-9 (asiento inverso inmutable). |
| `components/contracts/MrrImpactPreview.tsx` | Caja destacada MRR actual → Delta → Nuevo MRR (neutral en transfer). |
| `types/client.ts` | Tipos `Client`, `ClientsSummary`, `ClientWriteInput`. |
| `types/contract.ts` | Tipos listado/detalle/líneas/movimientos + lifecycle + `ContractMovementReversalInput` / `is_reversed`. |
| `services/clientsService.ts` | HTTP JWT contra `/api/clients` (paginación, summary, CRUD, toggle). |
| `services/contractsService.ts` | HTTP JWT contra `/api/contracts` (listado, detalle, create, lifecycle, reverse movement). |
| `hooks/useClients.ts` | TanStack Query + mutaciones con `useLoadingLock` para el directorio. |
| `hooks/useContracts.ts` | TanStack Query + mutación de alta con candado Operam. |
| `hooks/useContractDetail.ts` | Detalle + mutaciones lifecycle/reversión con invalidación de `contract` / `contracts` / `dashboard`. |
| `pages/config/CatalogServicesPage.tsx` | `/configuracion/catalogo` — tabla paginada API + filtros + CRUD (`config_catalog`). |
| `pages/config/PriceListsPage.tsx` | `/configuracion/precios` — directorio → detalle con columna Rango/Escala (RF-2), validación de traslapes y `saveVolumeMatrix` (`config_prices`). |
| `pages/config/PromotionsPage.tsx` | `/configuracion/promociones` — cards verde/ámbar + filtros (`config_promotions`). |
| `pages/config/IssuersPage.tsx` | `/configuracion/emisores` — CRUD exclusivo RF-1 (`issuers`) con filtros, badges IntegroPAY y paginación (`config_issuers`). |
| `pages/config/InstancesPage.tsx` | `/configuracion/instancias` — CRUD exclusivo de instancias/VPS; filtro por emisor; sin modal de emisores (`config_instances`). |
| `pages/config/CompanySettingsPage.tsx` | `/configuracion/empresa` — parámetros comerciales por segmento (`default_credit_days`, `usd_exchange_rate`) vía `config_company`. |
| `pages/config/SystemSettingsPage.tsx` | `/configuracion/sistema` — bandera `audit_logs_enabled` y depuración irreversible de la bitácora (`config_system`). |
| `pages/config/AuditLogsPage.tsx` | `/configuracion/auditoria` — bitácora paginada de solo lectura con diff antes/después (`config_audit_logs`). |
| `pages/config/ScreenPreferencesPage.tsx` | `/configuracion/preferencias` — pageSize, densidad y posición Toast (`config_preferences`). |
| `pages/config/UsersManagementPage.tsx` | `/configuracion/usuarios` — cuentas, rol y segmento (`config_users`). |
| `pages/config/RolesPermissionsPage.tsx` | `/configuracion/roles` — matriz multinivel sin preselección (`config_roles`). |
| `services/sessionSecurity.ts` / `lib/criticalRelogin.ts` | Interceptor 401 + logout diferido ante cambios críticos propios. |
| `pages/SalesPromotionsPage.tsx` | `/promociones-vigentes` — solo lectura + copiar código (ventas). |
| `types/auth.ts` / `types/mrr.ts` / `types/catalog.ts` / `types/client.ts` | Tipos (incluye `ModuleKey`, `PermissionsMap`, alcance de segmento, clientes). |
| `services/authService.ts` | Login HTTP real + JWT con perfil RBAC. |
| `services/rbacService.ts` | CRUD roles/permisos/usuarios contra API MySQL. |
| `services/dashboardService.ts` | Dashboard híbrido (mock solo aquí) + Bearer JWT. |
| `services/catalogService.ts` | CRUD + queries paginadas catálogo/emisores (JWT); `createIssuer` / `updateIssuer`. |
| `services/systemService.ts` | `GET/PUT /api/system-settings`, depuración `purge-audit-logs` y `GET /api/audit-logs` (JWT). |
| `services/mockDashboardData.ts` | Mock sep 2026 (solo Dashboard / demos). |
| `hooks/useDashboardData.ts` | TanStack Query dashboard. |
| `hooks/useConfigCatalog.ts` | TanStack Query + mutaciones Configuración + candado. |
| `hooks/useClientTableState.ts` | page/pageSize anclado a Preferencias de pantalla. |
| `lib/utils.ts` / `lib/mrrCalculator.ts` | Utilidades y motor MRR. |
| `lib/pricingCalculator.ts` | RF-2 `optimizeUserPackages` (centavos). |

### `Api/` / `System/` / `database/` / `backend/`

| Área | Descripción |
|------|-------------|
| `Api/index.php` | Solo `login` libre; resto JWT; CORS; catálogo + `issuers` + `clients` + `contracts` + `requests` + `movements` + `roles` / `users` / `me` + `segments` + `system-settings` + `audit-logs`. |
| `Api/services.routes.inc` | Índice documentado de rutas (incl. `/api/requests` M4 Paso 1). |
| `System/Services/ServiceRequestsService.php` | `/api/requests`: listado+KPIs, pending-count, create, quote, approve (one_time vs expansion), reject/cancel; RBAC `requests` + segmento. |
| `System/Support/ServiceRequestRules.php` | Motor puro BCMath: precios, tipo, gate de cartera, payload expansion con `request_id`. |
| `System/Models/ServiceRequestsModel.php` / `ServiceRequestItemsModel.php` | Eloquent `service_requests` / `service_request_items`. |
| `database/migrations/20261122000001_create_service_requests_tables.php` | Tablas `service_requests` / `service_request_items` + índice `movements.request_id`. |
| `backend/bootstrap/env.php` | Carga `.env` a `getenv` / `$_ENV`. |
| `backend/bootstrap/eloquent.php` | Capsule Eloquent + MySQL UTC. |
| `System/Core/*` | Result, Context, DataValidator, DataFormat. |
| `System/Support/FinancialCalculator.php` | Calculadora BCMath pura: `optimizeUserPackages`, `calculateMrrBridge`, `buildReversalPayload`, `accumulateMonthlyMrrAmount`. Categorías en inglés; ignora `transfer`; `movement_type` = `reversal`. |
| `System/Services/*` | Login, App, RbacAuthHelper, Roles, Users, Segments (RF-1), Issuers (refs enmascaradas), Clients (Módulo 3 Paso 1), Movements (RNF-3 + sync RF-10), SystemSettings, AuditLogs + catálogo. Escrituras llaman `AuditLogger`. |
| `System/Services/ClientsService.php` | CRUD `/api/clients`: paginación servidor, summary KPI, RBAC `clients`, alcance de segmento, herencia de crédito, anti-borrado con contratos. |
| `System/Services/ContractsService.php` | `/api/contracts`: listado, detalle enriquecido, POST alta, `POST /{id}/lifecycle` y `POST /{id}/movements/{mid}/reverse` (RNF-3 + audit). |
| `System/Services/DashboardMetricsService.php` | `GET /api/dashboard` (alias `/api/dashboard/mrr`): Puente MRR + KPIs + movimientos del mes (`month=YYYY-MM`). |
| `System/Support/ContractPricingEngine.php` | Motor puro BCMath: escalones, RF-2, promo, `lineMrrMxn`. |
| `System/Support/ContractLifecycleEngine.php` | Motor puro BCMath de deltas de ciclo de vida (expansion…reactivation, transfer=0.00). |
| `System/Models/ClientsModel.php` | Eloquent `clients` (`alias = cli`, fillable completo Módulo 3). |
| `System/Models/ContractsModel.php` / `ContractLinesModel.php` | Eloquent `contracts` / `contract_lines` (precios congelados RNF-3). |
| `System/Support/ClientRules.php` | Normalización RFC + resolución de `credit_days` (herencia de segmento). |
| `System/Support/AuditLogger.php` | Única puerta a `audit_logs` (redacta secretos, respeta `audit_logs_enabled`). |
| `System/Services/SystemSettingsService.php` | GET/PUT/PATCH `system-settings` + `POST purge-audit-logs` (`config_system`). |
| `System/Services/AuditLogsService.php` | GET paginado `audit-logs` de solo lectura (`config_audit_logs`). |
| `System/Models/SystemSettingsModel.php` / `AuditLogsModel.php` | Eloquent `system_settings` / `audit_logs`. |
| `database/migrations/20260925000010_create_system_settings_and_audit_logs.php` | Tablas `system_settings` / `audit_logs` + triggers inmutabilidad/depuración. |
| `database/seeds/SystemSettingsSeeder.php` | Seed idempotente `audit_logs_enabled = 1`. |
| `System/Models/*` | Users/Sessions + Roles + SegmentsModel + IssuersModel + MovementsModel (inmutable) + SystemSettings + AuditLogs + catálogo. |
| `database/migrations/*` | Esquema inglés consolidado: MRR (000001), auth (000002), catálogo (000003), RBAC (000004); 000005–000007/000009 no-op de secuencia; triggers RNF-3 (000008); settings/audit (000010); clients Módulo 3 (000011). |
| `database/migrations/20260925000011_enrich_clients_directory_module3.php` | `clients`: `segment_id`, contacto, `active`, índices `(segment_id, active)` / `rfc`. |
| `database/migrations/20260925000012_enrich_contracts_module3_paso2.php` | `contracts` enriquecido (`issuer_id`, `price_list_id`, `mrr_mxn`…) + tabla `contract_lines`. |
| `database/migrations/20260925000014_unify_rbac_eighteen_module_keys.php` | Unifica 18 `module_key` canónicos; renombra `segment_utility` → `segment_profitability`; limpia aliases legacy. |
| `database/seeds/ClientsSeeder.php` | ≥8 clientes realistas Operam/Construsync × Piloto/Cibus (idempotente). |
| `database/seeds/ContractsSeeder.php` | 4 contratos demo CT-0601…0604 con líneas, movements y monthly_mrr (idempotente). |
| `database/scripts/reset_english_schema.php` | DROP tablas legacy (español) + actuales + `phinxlog` para remigrar en limpio. |
| `scripts/reset_operational_data.php` | TRUNCATE operativo/comercial (contratos, clientes, catálogo, MRR, audit) conservando auth/RBAC/segmentos/settings; verifica triggers RNF-3. |
| `database/migrations/20260925000009_add_package_link_and_fx_rate.php` | No-op de secuencia. Columnas vivas: `services.package_service_id` + `package_factor`; `segments.usd_exchange_rate`. |
| `database/seeds/*` | Sep 2026 + EnterpriseUsers + CatalogAndPricing + RbacRolesAndPermissions (18 `module_key` en inglés, `segment_profitability`, matriz aplicable por módulo, + Gestor de Promociones). |
| `backend/src/Models/*` | Movement, Client, Contract, Segment, Issuer, MonthlyMrr, CollectionStatus. |
| `backend/src/Services/MovementService.php` | Alta inmutable auxiliar (Operam\Services) + Money. |
| `System/Services/MovementsService.php` | API `operam/servicios`: INSERT + reversión contable (BCMath, `contracts.mrr_mxn`, `monthly_mrr`); bloqueo UPDATE/DELETE. |
| `System/Services/SegmentsService.php` | GET/PATCH `default_credit_days` y `usd_exchange_rate` por segmento (RF-1) con alcance RBAC. |
| `System/Models/MovementsModel.php` | Eloquent `movements` con eventos `updating`/`deleting` (RNF-3). |
| `System/Models/SegmentsModel.php` | Eloquent `segments` (`default_credit_days`, `usd_exchange_rate`). |
| `database/migrations/20260925000008_enforce_movimiento_immutability_rnf3.php` | Triggers MySQL anti-UPDATE/DELETE sobre `movements` + columna `reversed_movement_id`. |
| `backend/tests/MoneyTest.php` | Tests auxiliares de Money (legado; la suite oficial vive en `tests/Unit`). |
