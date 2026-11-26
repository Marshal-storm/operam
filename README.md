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
# Servicios externos (Bloques C/D, 6 servicios en sandbox, incluye IntegroPAY) + worker del outbox:
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe vendor/bin/phinx seed:run -s ExternalServicesSeeder
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe scripts/run_outbox_worker.php --drain
```

### Jobs de Google Cloud (Bloque C Paso 2)

Cuatro jobs oficiales con doble entrada; cada corrida queda en `job_runs` (`trigger_source` = `cloud_scheduler` | `cli` | `manual_ui`). La fecha de negocio usa `jobs_outbox.timezone` (default `America/Mexico_City`).

```bash
# Cloud Run Jobs / cron local (exit 0 = todo completado, 1 = algún fallo o argumento inválido)
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe scripts/jobs/run_job.php daily_billing
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe scripts/jobs/run_job.php reset_included_balances
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe scripts/jobs/run_job.php daily_collections
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe scripts/jobs/run_job.php process_outbox
D:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe scripts/jobs/run_job.php all

# Cloud Scheduler (HTTP): el secreto vive en la variable GCP_JOB_SECRET_REF (.env / Secret Manager)
curl -X POST https://<host>/api/jobs/run/daily_billing -H "X-Job-Secret: <valor de GCP_JOB_SECRET_REF>"
```

| Job | Qué hace |
|-----|----------|
| `daily_billing` | Contratos activos con `next_cutoff_date` ≤ hoy: factura recurrente idempotente por `(contract_id, period_start)` con IVA 16% BCMath, recálculo de `collection_statuses`, avance del corte (29/30/31 → último día del mes) y prefactura `create_preinvoice` al ERP Emisor (`preinvoice:{contract_id}:{period_start}`). |
| `reset_included_balances` | Bolsas `included` vencidas: reotorga unidades con las reglas de beneficio vigentes, `consumed_units = 0`, nuevo periodo y movimiento `reset` en `balance_movements`. |
| `daily_collections` | Recalcula cartera (avisos 0/30/60/90), reactiva automáticamente (RF-6.3) y suspende contratos con licencias a +90 días (respeta `exclude_suspension`): `churn` + `suspend_instance`. |
| `process_outbox` | `OutboxDispatcher::processPending(50)` con reintentos y backoff. |

Sin secreto configurado el endpoint HTTP responde `503` (falla cerrado); un secreto incorrecto responde `401` (`hash_equals`). Desde la UI (`/configuracion/servicios` → **Jobs programados**) el disparo manual exige JWT con `config_external_services.edit` y alcance "Todos".

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
- **Cero mockups** fuera del Dashboard (Login, Catálogo, Contratos, Clientes, Cobranza, Solicitudes, Utilidad por segmento).
- **Kit atómico (Single Source of Truth):** prohibido `<select>` / `<input>` / `<textarea>` / `<button>` nativos en `src/pages/**` o componentes de negocio. Importar solo desde `src/components/ui/` (`AppCard`, `AppInput`, `AppSelect`, `AppButton`, `AppBadge`, `AppModal`, `AppDrawer`, `AppSwitch`, `AppTableWrapper`, `AppFilterBar`, `AppRowActionsMenu`, `AppTabs`). Un cambio visual global = un archivo del kit. **PROHIBIDO** barras de pestañas manuales: usar exclusivamente `<AppTabs />`. Formularios y vistas de detalle siempre en `<AppDrawer />` (`z-50`, bajo el Header `z-[55]`); confirmaciones/alertas críticas siempre en `<AppModal />` (`z-[70]`).
- **Filtros homologados (`AppFilterBar`):** toda barra de búsqueda/filtros de listados usa `<AppFilterBar />` (§3.2) con etiquetas en mayúsculas; catálogos dinámicos desde MySQL.
- **RNF-15 — Validación numérica estricta y errores API:** montos/precios/cantidades con Zod (`src/lib/validationSchemas.ts`: `moneySchema`, `exchangeRateSchema`, `positiveIntSchema`, `nonNegativeIntSchema`) + `AppInput.numericMode` antes de cualquier HTTP; `Api/index.php` con `display_errors=0` y `try/catch` global (`InvalidArgumentException` → HTTP 422 JSON; demás → HTTP 500 JSON con `error_id`); cliente `apiClient` / `parseOperamApiResponse` jamás trata HTML o `success≠true` como éxito.
- **RNF-8 — Cero hardcodeo de servicios externos:** URLs, hosts, API keys y parámetros operativos (SMTP, ERP, Slack, Banxico, PAC, webhooks) viven en Configuración → Servicios externos / MySQL; secretos solo por `secret_ref` hacia `.env` / Secret Manager.
- **RBAC multinivel (19 `module_key`):** operativos (`main`: `dashboard`…`sales_promotions` + `segment_profitability`) y `config_*` (`configuration`, incluye `config_external_services` Ver+Editar); sin permiso monolítico `configuration`.
- **Outbox transaccional (RNF-2 / RNF-12):** toda llamada saliente (ERP Emisor, ERP Master, IntegroPAY, SMTP, Slack) se encola con `OutboxDispatcher::enqueue()` dentro de la transacción de negocio con llave de idempotencia única; nunca se llama HTTP directo desde un servicio. Webhooks entrantes se registran con `IncomingEventRecorder::record()` (dedupe `origin + external_id`).
- **Jobs de GCP:** la lógica batch vive en `JobsRunner` (nunca en scripts sueltos); toda corrida se registra en `job_runs` y se audita. Cloud Scheduler se autentica con `X-Job-Secret` resuelto desde el cofre (`GCP_JOB_SECRET_REF`); jamás agregar `jobs/*` a `$freeuris`.
- **Auditoría automática (`audit_logs`):** toda escritura vía `System/Support/AuditLogger.php`; secretos redactados; módulo Sistema siempre audita; depuración solo con `@allow_audit_purge`.

Detalle completo: archivo `.cursorrules` en la raíz.

---

## 6. Estado de Módulos (Roadmap)

- [x] **Módulo 0:** Cimientos, Stack, Phinx y Reglas Senior.
- [x] **Módulo 1:** Dashboard General Interno, Puente de MRR, Cartera y Seguridad JWT Nivel Empresa.
- [x] **Módulo 2 (checkpoint oficial cerrado):** Catálogos, Listas de Precios con escalas, Promociones, Emisores, Instancias, Empresa, Preferencias, RBAC multinivel, BD 100% inglés, Kit Atómico, segmentos dinámicos, Sistema (`config_system`), Bitácora global `audit_logs` (`config_audit_logs`), Spinner + Toasts, cero mockups fuera del Dashboard.
- [x] **Infra transversal M2:** RBAC por `module_key`, Kit Atómico `src/components/ui/`, `AuditLogger` + `system_settings` / `audit_logs`.
- [x] **Módulo 3 (checkpoint oficial cerrado):** Directorio de Clientes, Motor de Contratos, Operaciones de Ciclo de Vida y Bitácora Inmutable (RF-3) — pasos 1–3: `/clientes`, `/contratos`, `/contratos/nuevo`, `/contratos/:id`, RF-2 dinámico, cupón quemado, BCMath y `movements` append-only.
- [x] **Módulo 4 Pasos 1–2 (checkpoint oficial cerrado):** Solicitudes (`/solicitudes`) + Cobranza (`/cobranza`) — RBAC 6 capas (`requests` / `collections`), IVA 16% BCMath en facturas (MRR sin impuestos), pagos + reversión sin DELETE, suspensión +90d (`exclude_suspension`), puente `from-request`, badge Sidebar, Kit Atómico, suite PHPUnit en verde.
- [x] **Módulo 4 Paso 1:** Bandeja de Solicitudes — `service_requests` / `service_request_items`, cotizar/aprobar/rechazar/cancelar (`can_delete`), solicitudes mixtas (solo recurring toca MRR), trazabilidad `Origen: SOL-XXXX` en bitácora.
- [x] **Módulo 4 Paso 2:** Cobranza — `invoices` / `payments`, emisión FAC-XXXX, antigüedad 5 tramos, CLI `run_collections_suspension.php`, Dashboard aging en vivo.
- [x] **Bloque A — Utilidad por Segmento, Costos Operativos, Ingresos No Recurrentes y Permiso/Reporte de Excepciones (RF-10, RF-11.4, RF-11.5):** checkpoint oficial cerrado — `/utilidad-segmento` (4 pestañas Kit Atómico), KPIs + unit economics sin scroll, CRUD `segment_costs`, ingresos no recurrentes, reporte de excepciones, `can_authorize_exceptions`, RNF-8 servicios externos, RNF-15 validación numérica + manejador JSON global en `Api/index.php`.
- [x] **Bloque B Paso 1 — Motor de Saldos / Bolsas / Consumos + API de Instancias (RF-4.1–RF-4.6):** `balances`, `consumptions` (RNF-3), `balance_movements`, `portal_access_tokens`, tokens SHA-256 en `instances`, `/api/balances/*`, `/api/instance-api/*`, UI en detalle de contrato + rotación de token.
- [x] **Bloque B — Motor de Saldos, Bolsas, Consumos, Reglas de Beneficio, Promociones RF-2.8, Contactos y API para Instancias:** checkpoint oficial cerrado — pulidos visuales de bolsas, `benefit_rules` (RF-2.6) con sync en alta/expansión/contracción, promociones `extra_units` + `promotion_scopes` (RF-2.8), `client_contacts` (RF-1.5), `gifted_units` en utilidad, Kit Atómico (`AppTabs`), PHPUnit + build en verde.
- [x] **Bloque C Paso 1 — Servicios externos, Outbox, Webhooks y Tipo de cambio (RNF-2 / RNF-8 / RNF-12):** `external_service_configs` (5 servicios sandbox, secretos solo por referencia), `outbox` con backoff + alerta Slack única, `incoming_events` con dedupe, `exchange_rates` (Banxico FIX), enganches en Contratos/Solicitudes/Cobranza, worker CLI, `/configuracion/servicios` con `<AppTabs />` (Servicios / Monitor Outbox / Webhooks), RBAC 6 capas `config_external_services`, PHPUnit + build en verde.
- [x] **Bloque C — Servicios Externos sin Hardcodeo, Outbox, Jobs de GCP, Reactivación Automática y Ajuste Masivo de Precios:** checkpoint oficial cerrado — 4 jobs (`daily_billing`, `reset_included_balances`, `daily_collections`, `process_outbox`) vía HTTP `X-Job-Secret` / CLI `scripts/jobs/run_job.php` / UI, bitácora `job_runs`, facturación por aniversario idempotente con fin de mes, reactivación automática RF-6.3 al liquidar licencias >90 días (`reactivation` + `reactivate_instance`), Ajuste Masivo RF-3.6 con vista previa UI-9 y lotes `AJ-YYYY-XXX` en `bulk_price_adjustments`, PHPUnit + build en verde.
- [x] **Bloque D Paso 1 — Portal Cliente (Magic Links), aislamiento por scope y motor de monto exigible:** auth sin contraseña (`POST /api/portal/auth/request-link|exchange`, JWT `client_portal` solo en memoria + cookie HttpOnly), liga generada desde ERP (`POST /api/client-contacts/{id}/generate-portal-link`), guardia dual de scope en `AppService` (403 cruzado portal↔ERP), `ExigibleCalculator` (BCMath, half-up), `/api/portal/*` de solo lectura, datos SPEI por emisor, `services.is_public`, tablas `purchases` / `payment_links` / `payment_link_documents` y UI `/portal/*` (iOS Liquid Glass, chunk lazy independiente), PHPUnit + build en verde.
- [x] **Bloque D — Portal del Cliente iOS (Mobile-First + Escritorio), Magic Links sin Contraseña, Aislamiento Dual de Sesiones, IntegroPAY, Tienda con Promociones RF-2.8 y Solicitudes:** checkpoint oficial cerrado — cliente HTTP aislado `portalApiClient` (token solo en memoria, cookie `operam_portal_session` HttpOnly `Path=/api/portal`, muro 403 bidireccional, `no-store`), adaptador `IntegroPayGateway` (ligas sandbox `SBX-…` 24 h / producción con credenciales por emisor vía `SecretResolver`), webhook `POST /api/webhooks/integropay/{issuerId}` con token `hash_equals`, verificación `verifyTransactionById` (jamás confía en el payload), dedupe `incoming_events` y aplicación atómica (pagos FIFO, recálculo de cartera, reactivación RF-6.3), pago de saldo desde el portal (exigible / total / parcial), Tienda con escalones + IVA 16% + unidades de regalo en movimiento `promotion` separado (MRR intacto), checkout por tarjeta o SPEI, solicitudes del portal (`origin = portal`, candado de cartera RF-8.1 para cambios de plan, acuse por correo vía outbox), PHPUnit + build en verde.

---

## 7. Índice de Archivos Activos del Proyecto

### Raíz

| Archivo | Descripción |
|---------|-------------|
| `.cursorrules` | Reglas senior: BD inglés, Kit Atómico §3.1, RBAC §11, filtros con label, cero mockups, Spinner+Toasts, Liquid Glass, TTS. |
| `.env.example` | Plantilla de variables Vite / MySQL / JWT + referencias del cofre RNF-8 (`SMTP_PASSWORD_REF`, `ERP_MASTER_API_KEY_REF`, `SLACK_OUTBOX_WEBHOOK_REF`, `BANXICO_SIE_TOKEN_REF`, `GCP_JOB_SECRET_REF`; alternativa `<NOMBRE>_FILE`). |
| `.gitignore` | Excluye `node_modules/`, `vendor/`, `dist/`, `.env`, temporales. |
| `.oxlintrc.json` | Oxlint. |
| `composer.json` / `composer.lock` | Dependencias PHP 8.3 + `phpunit/phpunit` ^11 en require-dev. |
| `phinx.php` / `phpunit.xml` | Phinx y PHPUnit (suite `tests/Unit`). |
| `tests/Unit/FinancialRulesTest.php` | Suite oficial RNF-1 / RF-2 / RNF-3 (Puente MRR, paquetes de 5, reversión, inmutabilidad). |
| `tests/Unit/BenefitsAndExtraUnitsPromoTest.php` | Bloque B Paso 2: beneficios RF-2.6 + unidades extra RF-2.8 (floor BCMath, no acumulables). |
| `tests/Unit/NormalizeMoneyValidationTest.php` | RNF-15: `FinancialCalculator::normalizeMoney` rechaza `ddd`/`12a`/`NaN` y acepta deltas con signo. |
| `tests/Unit/AuditLoggerTest.php` | Sanitización de secretos y diff de auditoría. |
| `tests/Unit/ClientRulesTest.php` | Normalización de RFC y herencia de días de crédito por segmento. |
| `tests/Unit/ContractPricingEngineTest.php` | Motor BCMath de contrato: RF-2, anual/12, USD→MXN y promo %. |
| `tests/Unit/ContractLifecycleEngineTest.php` | Deltas BCMath de ciclo de vida: expansión, contracción, precio, frecuencia, transfer=0, churn, reactivación. |
| `tests/Unit/BurnedCouponLifecycleTest.php` | Cupón quemado: alta 10% + ajuste/contracción → `discount_amount=0`, delta BCMath exacto, sin subtotales negativos. |
| `tests/Unit/MovementReversalRulesTest.php` | Reversión contable RNF-3: delta inverso BCMath, impacto vs MRR del contrato, bloqueo MRR negativo y doble reversión. |
| `tests/Unit/ServiceRequestRulesTest.php` | Solicitudes M4: one_time sin MRR, expansión con `request_id`, bloqueo por cartera sin excepción, RF-2. |
| `tests/Unit/CollectionRulesTest.php` | Cobranza M4: emisión+pagos BCMath, bloqueo sobrepago, suspensión +90d vs `exclude_suspension`. |
| `scripts/reset_contracts_only.php` | TRUNCATE solo tablas de contratos + solicitudes/cobranza + `promotions.current_uses=0`; verifica triggers RNF-3. |
| `scripts/reset_transactional_history.php` | Reset pre-IVA: TRUNCATE 9 tablas transaccionales (CT/SOL/FAC → 0001), restaura `promotions.current_uses=0`, recrea/verifica triggers RNF-3; catálogos/clientes intactos. |
| `scripts/run_collections_suspension.php` | CLI nocturno: `runSuspensionCheck` (+90 días, churn append-only). |
| `scripts/run_outbox_worker.php` | Worker CLI/GCP del outbox: `--limit=25`, `--drain` (vacía la cola); exit 2 si hubo fallos. |
| `tests/Unit/OutboxAndExternalServicesTest.php` | Bloque C: idempotencia de `enqueue`, sandbox pending→sent, backoff + alerta Slack única, reintento manual, dedupe de webhooks, rechazo de secretos en texto plano, parseo Banxico. |
| `scripts/jobs/run_job.php` | CLI de Cloud Run Jobs: `daily_billing` / `reset_included_balances` / `daily_collections` / `process_outbox` / `all` (registra en `job_runs`). |
| `tests/Unit/JobsReactivationAndBulkPriceTest.php` | Bloque C Paso 2: facturación diaria (IVA, fin de mes 31→28 feb→31 mar→30 abr, prefactura en outbox, sin duplicados), reinicio de bolsas (`reset`), reactivación RF-6.3 (`reactivation` + `reactivate_instance`), ajuste masivo +10% exacto al centavo en `movements` / `monthly_mrr`, secreto de Cloud Scheduler. |
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
| `components/ui/AppTabs.tsx` | Barras de pestañas cápsula Liquid Glass (§3.1): `items`/`value`/`onChange`/`badge` + `badgeTone` (`neutral`/`warning`/`danger`); única forma de tabs en producto. |
| `components/ui/AppFilterField.tsx` | Campo suelto con label (complemento; listados usan `AppFilterBar`). |
| `components/ui/AppRowActionsMenu.tsx` | Menú `⋮` de acciones de fila (§3.3) + encabezado `ACCIONES`; Liquid Glass Radix Dropdown. |
| `context/ScreenPreferencesContext.tsx` | pageSize (10/25/50/100) + densidad + `toastPosition` (6 anclas) en `localStorage`. |
| `context/LoadingLockContext.tsx` | Candado global + `withScreenLock` / start/stop. |
| `context/ToastContext.tsx` | Toasts Liquid Glass apilados (baraja iOS, expand/pause al hover); posición desde ScreenPreferences; `useToast`. |
| `components/ui/GlobalLoadingOverlay.tsx` | Barra arcoíris + velo + Spinner Aura Operam (conic-gradient sincronizado) en mutaciones. |
| `components/ui/ThemeToggleSwitch.tsx` | Switch iOS luna/sol (primitivo del kit). |
| `components/ui/appTokens.ts` | Tokens compartidos de campo/tarjeta/popover (borde, focus, sombra). |
| `components/ui/AppButton.tsx` | Botón atómico (`primary` / `secondary` / `outline` / `ghost` / `danger` + `isLoading`). |
| `components/ui/AppInput.tsx` | Input / moneda / búsqueda / textarea atómicos + `numericMode` (`decimal` \| `integer` \| `decimal4`) RNF-15. |
| `components/ui/AppSelect.tsx` | Select Radix Liquid Glass; buscador si >6 opciones; semáforo por opción. |
| `components/ui/AppCard.tsx` | Tarjeta Liquid Glass (`Header` / `Content` / `Footer`). |
| `components/ui/AppBadge.tsx` | Píldoras semáforo (`success` \| `warning` \| `danger` \| `info` \| `neutral` \| `slate`); siempre `whitespace-nowrap`. |
| `components/ui/AppModal.tsx` | Diálogo accesible sobre `@radix-ui/react-dialog` (`z-[70]`) exclusivo para confirmaciones y alertas críticas. |
| `components/ui/AppDrawer.tsx` | Panel lateral derecho Liquid Glass sobre `@radix-ui/react-dialog` (`top-14`, `z-50`, tamaños `md`/`lg`/`xl`/`2xl`, 3 zonas fijas) para formularios y vistas de detalle (§3.1). |
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
| `pages/ClientsPage.tsx` | `/clientes` — directorio CRUD (KPIs, filtros dinámicos, tabla paginada, `<AppDrawer />` crear/editar `lg` y detalle `xl` con Contactos, acción directa "Generar liga mágica") con Kit Atómico (`clients`). |
| `components/clients/ClientMagicLinkModal.tsx` | Modal compacto (`AppModal`) de acceso directo desde tabla de Clientes para elegir contacto autorizado, generar/copiar liga mágica o abrir Contactos en Drawer `xl`. |
| `pages/RequestsPage.tsx` | `/solicitudes` — bandeja M4 Paso 1: KPIs, `AppFilterBar`, tabla/tarjetas UI-4, cotizar/aprobar/rechazar (`requests`). |
| `pages/CollectionsPage.tsx` | `/cobranza` — M4 Paso 2: KPIs, aging 5 tramos, `<AppTabs />` Documentos/Cartera, `AppFilterBar`, emisión/pagos/cancelación/suspensión (`collections`). |
| `pages/SegmentProfitabilityPage.tsx` | `/utilidad-segmento` — `<AppTabs />` (Resumen / Costos / No recurrentes / Excepciones) + unit economics; `AppFilterBar` (`segment_profitability`). |
| `components/profitability/SegmentCostModal.tsx` | Modal RHF+Zod de captura/edición de `segment_costs`. |
| `hooks/useProfitability.ts` | TanStack Query summary/costs/non-recurring/exceptions + mutaciones con `useLoadingLock`. |
| `services/profitabilityService.ts` | HTTP JWT contra `/api/segment-profitability/*`. |
| `types/profitability.ts` | Tipos summary, costos, no recurrentes y excepciones. |
| `components/collections/*` | `NewInvoiceModal`, `RecordPaymentModal`, `InvoiceDetailModal` (RHF+Zod + Kit Atómico). |
| `components/requests/NewRequestModal.tsx` | Modal RHF+Zod de alta interna con preview UI-9 (total vs impacto MRR / RF-2). |
| `components/requests/RequestDetailModal.tsx` | Detalle + aprobación (excepción de cartera) + rechazo con caja UI-9. |
| `components/requests/RequestImpactPreview.tsx` | Caja UI-9 Total / Impacto MRR (no suma vs expansión). |
| `types/request.ts` | Tipos de solicitudes, ítems, KPIs y payloads create/quote/approve/reject. |
| `services/requestsService.ts` | HTTP JWT contra `/api/requests` (+ `pending-count`, quote/approve/reject). |
| `services/collectionsService.ts` | HTTP JWT contra `/api/collections/invoices|contracts|payments|cancel|run-suspension-check`. |
| `hooks/useRequests.ts` | TanStack Query listado/detalle + mutaciones con `useLoadingLock` e invalidación de badge/contratos/dashboard. |
| `hooks/useCollections.ts` | TanStack Query cobranza + mutaciones con `useLoadingLock`; invalida `collections`/`contracts`/`requests`/`dashboard`. |
| `types/collection.ts` | Tipos invoices/payments/cartera/aging/suspensión. |
| `pages/ContractsPage.tsx` | `/contratos` — listado paginado servidor, 4 KPIs, `AppFilterBar`, tabla/tarjetas UI-4 (`contracts`); botón "Ajuste masivo de precios" con `canEdit('contracts')`. |
| `components/contracts/BulkPriceAdjustmentModal.tsx` | Ajuste Masivo RF-3.6 en 2 pasos (filtros + % + motivo → vista previa UI-9 con KPIs y tabla → aplicar lote `AJ-YYYY-XXX`); invalida `contracts` y `dashboard`. |
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
| `pages/config/ExternalServicesPage.tsx` | `/configuracion/servicios` — `<AppTabs />` Servicios externos (6 tarjetas, incluye IntegroPAY) / Jobs programados / Monitor Outbox (KPIs + reintentos) / Webhooks; escrituras solo con alcance Todos (`config_external_services`). |
| `components/external-services/ScheduledJobsTab.tsx` / `jobMeta.ts` | Pestaña Jobs programados: 4 tarjetas (última corrida, estado, procesados, "Ejecutar ahora" con confirmación) + historial `job_runs` paginado con detalle JSON. |
| `components/external-services/*` | `ExternalServiceCard`, `ExternalServiceConfigModal` (RHF+Zod, nota RNF-8), `OutboxMonitorTab`, `IncomingEventsTab`, `JsonPayloadModal`, `externalServiceMeta.ts` (campos/etiquetas por servicio). |
| `services/externalServicesService.ts` / `hooks/useExternalServices.ts` / `types/externalServices.ts` | HTTP JWT contra `/api/external-services`, `/api/outbox`, `/api/incoming-events`, `/api/jobs` + TanStack Query con `withScreenLock`. |
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
| `lib/apiClient.ts` | Cliente HTTP RNF-15 del Panel Interno: `parseOperamApiResponse` (nunca éxito falso con HTML/`success≠true`). |
| `src/lib/portalApiClient.ts` | Cliente HTTP aislado exclusivo de `/api/portal/*`: JWT en memoria React + `credentials: 'include'`, jamás lee el token interno. |
| `lib/validationSchemas.ts` | Zod `moneySchema` / `exchangeRateSchema` / `positiveIntSchema` / `nonNegativeIntSchema` / `secretRefNameSchema` / `optionalHttpUrlSchema` / `timeOfDaySchema` / `adjustmentPercentageSchema` / `bulkPriceAdjustmentSchema` / `portalAccessSchema` / `buildPortalPaymentSchema` / `portalStoreQuantitySchema` / `portalRequestSchema`. |

### `Api/` / `System/` / `database/` / `backend/`

| Área | Descripción |
|------|-------------|
| `Api/index.php` | Solo `login` libre; resto JWT; CORS; catálogo + `issuers` + `clients` + `client-contacts` + `benefit-rules` + `contracts` + `requests` + `collections` + `movements` + `segment-profitability` + `roles` / `users` / `me` + `segments` + `system-settings` + `audit-logs` + `balances` + `external-services` + `outbox` + `incoming-events` + `portal/*` (JWT `client_portal`) + `webhooks/integropay/{issuerId}` (token por emisor, antes de la guardia JWT; nunca en `$freeuris`). RNF-15: `display_errors=0` + `try/catch` global JSON (422/500 + `error_id`). |
| `Api/services.routes.inc` | Índice documentado de rutas (incl. `/api/requests`, `/api/collections`, `/api/segment-profitability`, `/api/external-services`, `/api/outbox` e `/api/incoming-events`). |
| `System/Support/Integrations/SecretResolver.php` | RNF-8: resuelve nombres de referencia (`$_ENV` → `getenv` → `$_SERVER` → `<REF>_FILE`); la UI solo recibe `isConfigured()`. |
| `System/Support/Integrations/ExternalServiceRegistry.php` | Esquema y validación por servicio (`smtp`, `erp_master`, `slack`, `banxico`, `jobs_outbox`, `integropay`); `isProduction`, `configValue`, `resolveSecret`. |
| `System/Support/Integrations/OutboxDispatcher.php` | `enqueue` idempotente + worker `processPending` (SKIP LOCKED, sandbox/producción, backoff `retry_backoff_minutes × intentos`, alerta Slack única) + `retry`. |
| `System/Support/Integrations/OutboxPublisher.php` | Arma payloads de negocio: ajuste de usuarios, suspensión/reactivación de instancia, prefactura al ERP Emisor. |
| `System/Support/Integrations/IncomingEventRecorder.php` | Registro deduplicado de webhooks (`origin + external_id`) + `markProcessed`. |
| `System/Support/Integrations/ExternalServiceTester.php` | "Probar conexión" sandbox/producción; Banxico guarda el FIX en `exchange_rates`. |
| `System/Support/Integrations/IntegrationTransport.php` / `DefaultIntegrationTransport.php` | Puerto de salida (Guzzle + Symfony Mailer); sustituible en pruebas. |
| `System/Services/ExternalServicesService.php` / `OutboxService.php` / `IncomingEventsService.php` | REST Bloque C con RBAC `config_external_services`, alcance de segmento y `AuditLogger`. |
| `System/Models/ExternalServiceConfigsModel.php` / `OutboxModel.php` / `IncomingEventsModel.php` / `ExchangeRatesModel.php` | Eloquent de las tablas Bloque C. |
| `database/migrations/20261125000004_create_outbox_incoming_events_and_external_services.php` | Tablas `external_service_configs`, `outbox`, `incoming_events`, `exchange_rates` (únicos de idempotencia/dedupe). |
| `database/migrations/20261125000005_create_job_runs_and_bulk_price_adjustments.php` | Tablas `job_runs` y `bulk_price_adjustments`; `invoices.period_start` / `period_end` + único `(contract_id, period_start)` (idempotencia de facturación). |
| `System/Services/JobsService.php` | `/api/jobs`: `POST run/{job\|all}` (Cloud Scheduler con `X-Job-Secret` + `hash_equals`, o JWT `config_external_services.edit`) y `GET runs` (historial + última corrida). |
| `System/Support/Jobs/JobsRunner.php` | Motor de los 4 jobs + bitácora `job_runs` (bloqueo de corridas simultáneas, recuperación de corridas huérfanas, auditoría). |
| `System/Support/Jobs/BillingCycleRules.php` | Reglas puras de ciclo de facturación: siguiente corte por aniversario con fin de mes, fin de periodo, subtotal del periodo BCMath. |
| `System/Support/BulkPriceAdjustmentRules.php` / `BulkPriceAdjustmentProcessor.php` | Ajuste Masivo RF-3.6: reglas puras (porcentaje, precio half-up, cupón quemado, folio `AJ-YYYY-XXX`) + consulta/aplicación transaccional (`price_adjustment` por contrato). |
| `database/seeds/ExternalServicesSeeder.php` | 6 servicios en sandbox, incluye `integropay` (idempotente, sesión UTC, no pisa ajustes del admin). |
| `System/Services/SegmentProfitabilityService.php` | Summary + CRUD `segment_costs` + no recurrentes + excepciones; RBAC `segment_profitability` + alcance segmento. |
| `System/Support/SegmentProfitabilityCalculator.php` | Motor puro BCMath: utilidad bruta, margen, unit economics, usuarios concurrentes. |
| `System/Support/ExceptionAuthorization.php` | RF-11.4: gate `can_authorize_exceptions` + saldo vencido al momento. |
| `System/Models/SegmentCostsModel.php` | Eloquent `segment_costs` (`alias = scost`). |
| `database/migrations/20261125000001_create_segment_costs_and_exceptions_fields.php` | Tabla `segment_costs` + `users.can_authorize_exceptions` + `overdue_balance_at_exception`. |
| `tests/Unit/SegmentProfitabilityTest.php` | BCMath utilidad/margen/unit economics + bloqueo RF-11.4. |
| `tests/Unit/BalancesAndConsumptionTest.php` | RF-4: orden included→prepaid, tolerancia ≤2, RNF-3 consumptions. |
| `tests/Unit/BenefitsAndExtraUnitsPromoTest.php` | RF-2.6/RF-2.8: 7×50=350, floor 1010×25%=252, no acumulables, alcance cliente, purchase vs promotion. |
| `System/Support/BalanceRules.php` / `BalanceEngine.php` | Motor puro + persistencia de bolsas/consumos (lockForUpdate, idempotencia); crédito prepaid + promo separada. |
| `System/Support/BenefitRulesEngine.php` | Sync de bolsas `included` + preview humano (RF-2.6). |
| `System/Support/PromotionCalculator.php` | Unidades extra % con BCMath floor; pickBest no acumulable (RF-2.8). |
| `System/Services/BalancesService.php` | `/api/balances/contract|consume|grant` (RBAC `contracts`) + `syncContractBenefitBalances`. |
| `System/Services/BenefitRulesService.php` | CRUD `/api/benefit-rules` + preview (RBAC `config_catalog`). |
| `System/Services/ClientContactsService.php` | CRUD `/api/client-contacts` (RBAC `clients` + segmento). |
| `System/Services/InstanceApiService.php` | `/api/instance-api/*` con Bearer de instancia (balance, consumption, collection-status, portal-link). |
| `System/Models/BalancesModel.php` / `ConsumptionsModel.php` / `BalanceMovementsModel.php` | Eloquent de bolsas + bitácora inmutable de consumos. |
| `System/Models/BenefitRulesModel.php` / `ClientContactsModel.php` | Eloquent `benefit_rules` / `client_contacts`. |
| `database/migrations/20261125000002_create_balances_consumptions_and_instance_tokens.php` | Tablas balances/consumptions/balance_movements/portal_access_tokens + tokens de instancia + triggers RNF-3. |
| `database/migrations/20261125000003_create_benefits_promo_scopes_and_contacts.php` | `benefit_rules`, extensión `promotions` (extra_units), `promotion_scopes`, `client_contacts`. |
| `components/contracts/RegisterConsumptionModal.tsx` / `GrantBalanceModal.tsx` / `ContractBalancesPanel.tsx` | UI de saldos/consumos; oculta Incluida 0/0; badges TIPO en español. |
| `components/catalog/BenefitRulesTab.tsx` | Pestaña Reglas de beneficio en Catálogo (tabla + modal Kit Atómico). |
| `components/clients/ClientContactsTab.tsx` | Pestaña Contactos en detalle de cliente (RF-1.5); acción «Generar liga de portal». |
| `components/clients/PortalLinkModal.tsx` | Modal con la liga mágica generada desde el ERP (un solo uso, 15 min). |
| `database/migrations/20261125000006_create_portal_purchases_payment_links_and_spei_fields.php` | Campos `spei_*` y ref. de webhook en `issuers`, `services.is_public`, tablas `purchases`, `payment_links`, `payment_link_documents`. |
| `System/Services/PortalAuthService.php` | `/api/portal/auth/*`: request-link (respuesta genérica + rate limit), exchange (liga de un solo uso → JWT), me, logout. |
| `System/Services/PortalScopedService.php` | Base abstracta de `/api/portal/*`: `client_id` solo del JWT, contrato propio, actor de auditoría `portal`, estados de documento abiertos. |
| `System/Services/PortalService.php` | `/api/portal/*` de lectura (overview con `open_balance_mxn` por contrato, contracts, invoices) + despacho a pagos, tienda y solicitudes. |
| `System/Services/PortalPaymentsService.php` | `POST /api/portal/payments/create-link` (exigible / total / parcial, documentos FIFO) + simulador `GET|POST /api/portal/sandbox-pay/{id}[/confirm]` (mismo flujo del webhook; nunca con `APP_ENV=production`). |
| `System/Services/PortalStoreService.php` | `/api/portal/store` (catálogo público + promos visibles), `store/quote` (escalón + IVA 16% + regalo RF-2.8) y `store/checkout` (tarjeta → liga; SPEI → factura pendiente). |
| `System/Services/PortalRequestsService.php` | `GET|POST /api/portal/requests`: historial con estado amigable y alta `origin = portal` (candado de cartera RF-8.1 en cambios de plan, acuse SMTP vía outbox, auditoría). |
| `System/Services/IntegroPayWebhookService.php` | `POST /api/webhooks/integropay/{issuerId}`: token `hash_equals`, dedupe `incoming_events`, verificación con la pasarela y aplicación atómica (compra o pago FIFO, cartera, reactivación RF-6.3). |
| `System/Support/Integrations/PaymentGatewayInterface.php` / `IntegroPayGateway.php` | Puerto de pasarela + adaptador IntegroPAY (ligas sandbox `SBX-…` 24 h o productivas con credenciales por emisor vía `SecretResolver`; `verifyTransactionById`). |
| `System/Support/PurchaseFulfillment.php` | Cumplimiento de compras de la Tienda: documento pagado + pago (tarjeta) o documento SPEI pendiente; acredita bolsa `prepaid` (`purchase`) y regalo (`promotion`) por separado, sin tocar MRR. |
| `System/Support/PriceListResolver.php` / `ContractExigibleResolver.php` / `IssuerSpeiInstructions.php` | Escalones de precio por lista/frecuencia, exigible por contrato (compartido portal/solicitudes/pagos) e instrucciones SPEI del emisor. |
| `database/migrations/20261125000007_extend_portal_requests_purchases_and_integropay.php` | `service_requests.origin = portal`, `plan_change`, `portal_request_type`; `invoices.purchase_id`; `purchases.payment_method`; `payment_links.transaction_id` / `paid_at`; servicio `integropay`. |
| `database/migrations/20261126000001_add_portal_sessions_and_timeout_settings.php` | Tabla `portal_sessions` (sesión deslizante por inactividad y revocación inmediata) + parámetros `portal_idle_timeout_minutes` (5 min) y `portal_magic_link_ttl_minutes` (15 min) en `system_settings`. |
| `tests/Unit/PortalStoreAndIntegroPayTest.php` | Bloque D Paso 2: compra 1,000 + 25% = 1,250 con movimientos separados y MRR intacto, webhook con token e idempotente, reactivación al pagar el exigible, `add_users` 422 con adeudo y `origin = portal` al corriente. |
| `System/Support/ExigibleCalculator.php` | Motor BCMath del monto exigible devengado (adeudo previo + mensualidad × meses transcurridos − pagado). |
| `System/Support/PortalSession.php` / `PortalMagicLinkIssuer.php` | Emisión/validación del JWT `client_portal` + cookie, y emisión de ligas mágicas (solo hash SHA-256 persistido). |
| `tests/Unit/PortalAuthAndExigibleTest.php` | Exigible (ejemplo anual, half-up, sobrepago), ligas de un solo uso, scopes cruzados 403, aislamiento de contratos ajenos. |
| `src/pages/portal/*` | `PortalApp` (lazy, `/portal/*`), acceso, inicio, contratos, pagos (botón "Pagar con tarjeta"), tienda Bento con cotización en vivo, solicitudes y simulador `PortalSandboxPayPage` (`/portal/sandbox-pay/:externalLinkId`). |
| `src/components/portal/*` | `PortalLayout` (píldora flotante en escritorio / dock iOS en móvil), tarjeta héroe, hoja de pago `PortalPaymentModal`, `PortalRequestModal`, modal SPEI, saldos, plan, facturas y tokens de estilo (hoja inferior iOS). |
| `src/context/PortalAuthContext.tsx` / `services/portalService.ts` / `hooks/usePortalData.ts` / `lib/portalLabels.ts` / `types/portal.ts` | Sesión del portal (JWT solo en memoria), HTTP (pagos, tienda, sandbox, solicitudes), TanStack Query + mutaciones con `withScreenLock`, etiquetas es-MX y tipos. |
| `lib/moneyCents.ts` | Aritmética exacta de dinero en centavos `bigint` (suma, comparación, mínimo) para la UI — cero `float`. |
| `hooks/useDebouncedValue.ts` | Valor con retardo (cotización en vivo de la Tienda mientras se teclea). |
| `lib/requestLabels.ts` | Etiquetas compartidas de tipo de solicitud (incluye `plan_change`) e intención del portal para la Bandeja interna. |
| `types/balance.ts` / `services/balancesService.ts` / `hooks/useBalances.ts` | Tipos + HTTP JWT + TanStack Query de bolsas. |
| `services/benefitRulesService.ts` / `services/clientContactsService.ts` / `hooks/useClientContacts.ts` | HTTP + hooks de reglas de beneficio y contactos. |
| `System/Services/ServiceRequestsService.php` | `/api/requests`: listado+KPIs, pending-count, create, quote, approve (one_time vs expansion + puente factura), reject/cancel; RBAC `requests` + segmento. |
| `System/Services/CollectionsService.php` | `/api/collections`: invoices, payments, cartera, recalculo `collection_statuses`, suspensión +90d (solo deuda de licencias), reactivación automática RF-6.3 al pagar; ciclo diario del job `daily_collections`; RBAC `collections`. |
| `System/Support/ServiceRequestRules.php` | Motor puro BCMath: precios, tipo, gate de cartera, payload expansion con `request_id`. |
| `System/Support/CollectionRules.php` | Motor puro BCMath: pagos, notice_level, aging, elegibilidad suspensión, due_date, días vencidos de licencias, elegibilidad de reactivación, nivel de aviso 0/30/60/90. |
| `System/Models/ServiceRequestsModel.php` / `ServiceRequestItemsModel.php` | Eloquent `service_requests` / `service_request_items`. |
| `System/Models/InvoicesModel.php` / `PaymentsModel.php` | Eloquent `invoices` / `payments`. |
| `database/migrations/20261122000001_create_service_requests_tables.php` | Tablas `service_requests` / `service_request_items` + índice `movements.request_id`. |
| `database/migrations/20261122000002_create_collections_invoices_and_payments.php` | Tablas `invoices` / `payments` (DECIMAL 14,2, FKs, índices de cobranza). |
| `backend/bootstrap/env.php` | Carga `.env` a `getenv` / `$_ENV`. |
| `backend/bootstrap/eloquent.php` | Capsule Eloquent + MySQL UTC. |
| `System/Core/*` | Result, Context, DataValidator, DataFormat. |
| `System/Support/FinancialCalculator.php` | Calculadora BCMath pura: `optimizeUserPackages`, `calculateMrrBridge`, `buildReversalPayload`, `accumulateMonthlyMrrAmount`. Categorías en inglés; ignora `transfer`; `movement_type` = `reversal`. |
| `System/Services/*` | Login, App, RbacAuthHelper, Roles, Users, Segments (RF-1), Issuers (refs enmascaradas), Clients (Módulo 3 Paso 1), Movements (RNF-3 + sync RF-10), SystemSettings, AuditLogs + catálogo. Escrituras llaman `AuditLogger`. |
| `System/Services/ClientsService.php` | CRUD `/api/clients`: paginación servidor, summary KPI, RBAC `clients`, alcance de segmento, herencia de crédito, anti-borrado con contratos. |
| `System/Services/ContractsService.php` | `/api/contracts`: listado, detalle enriquecido, POST alta, `POST /{id}/lifecycle`, `POST /{id}/movements/{mid}/reverse` (RNF-3 + audit) y `bulk-price-adjustment/{options\|preview\|apply}` (RF-3.6, `contracts.edit`). |
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
| `database/seeds/*` | Sep 2026 + EnterpriseUsers + CatalogAndPricing + RbacRolesAndPermissions (19 `module_key` en inglés, `segment_profitability`, `config_external_services`, matriz aplicable por módulo, + Gestor de Promociones). |
| `backend/src/Models/*` | Movement, Client, Contract, Segment, Issuer, MonthlyMrr, CollectionStatus. |
| `backend/src/Services/MovementService.php` | Alta inmutable auxiliar (Operam\Services) + Money. |
| `System/Services/MovementsService.php` | API `operam/servicios`: INSERT + reversión contable (BCMath, `contracts.mrr_mxn`, `monthly_mrr`); bloqueo UPDATE/DELETE. |
| `System/Services/SegmentsService.php` | GET/PATCH `default_credit_days` y `usd_exchange_rate` por segmento (RF-1) con alcance RBAC. |
| `System/Models/MovementsModel.php` | Eloquent `movements` con eventos `updating`/`deleting` (RNF-3). |
| `System/Models/SegmentsModel.php` | Eloquent `segments` (`default_credit_days`, `usd_exchange_rate`). |
| `database/migrations/20260925000008_enforce_movimiento_immutability_rnf3.php` | Triggers MySQL anti-UPDATE/DELETE sobre `movements` + columna `reversed_movement_id`. |
| `backend/tests/MoneyTest.php` | Tests auxiliares de Money (legado; la suite oficial vive en `tests/Unit`). |
