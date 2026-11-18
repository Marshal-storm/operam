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

El **MRR nace exclusivamente de la bitácora `movimiento`**.  
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

Migraciones y seeders:

```bash
vendor/bin/phinx migrate
vendor/bin/phinx seed:run
```

(Alternativa Composer: `composer migrate` / `composer seed`.)

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

---

## 4. Credenciales de Acceso (Desarrollo / Seeders)

Usuarios del `EnterpriseUsersSeeder` + vínculo RBAC (`RbacRolesAndPermissionsSeeder`):

| Email | Password | Rol RBAC | Segmento |
|-------|----------|----------|----------|
| `admin@operam.mx` | `Operam2026*` | Administrador General (`admin_general`) | Todos (`segmento_id = NULL`) |
| `operador@operam.mx` | `Operam2026*` | Operador Operam (`operador_comercial`) | Solo Operam |
| `supervisor@construsync.mx` | `Constru2026*` | Supervisor Construsync (`supervisor_segmento`) | Solo Construsync |

### Flujo JWT empresarial + RBAC

1. `POST /api/login` → `LoginService` valida credenciales contra `usuario`.
2. Firma **JWT HS256** con `JWT_TOKEN_KEY` (`iss` = `JWT_TOKEN_ISSUER`, `exp` = `DEF_EXPIRATION_TIME`).
3. Claim `jti` incluye `rol_id`, `rol_nombre`, `segmento_id`, `segmento_nombre` (`Todos` si NULL) y mapa `permisos` CRUD por `modulo_clave` multinivel (`config_catalogo`, `config_roles`, etc.).
4. Sesión revocable en tabla `sesion_activa` (claim `jti`). Cambiar `rol_id`/`segmento_id`/`activo` o la matriz `rol_permiso` revoca sesiones afectadas → HTTP 401.
5. Rate limiting de fallos en `auth_intento` (bloqueo temporal 429).
6. Rutas protegidas en `AppService::before_get` excepto `$freeuris` (`login`).
7. Perfil: `GET /api/me`. Roles (`config_roles`): `/api/roles`, `/api/roles/modules`, `PUT /api/roles/{id}/permissions`. Usuarios (`config_usuarios`): `/api/users`.

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

- **Código** (variables, funciones, clases, archivos): 100% **inglés**.
- **Comentarios**: español. **UI**: español (`es-MX`).
- Dinero: `DECIMAL(14,2)` / Money — **sin floats**.
- Tipografía: **Ubuntu** (títulos/KPI), **DM Sans** / Ubuntu (UI), **DM Mono** + `tabular-nums` (folios/montos).
- Loading: barra arcoíris Operam + candado de pantalla (sin modal central).
- Tema dual Light crisp / True Dark OLED; shell Sidebar `h-screen` + Header solo columna derecha.
- DB: solo migraciones/seeders Phinx — **prohibido `.sql` sueltos**.
- Auth: JWT del framework — **prohibido Sanctum**.
- **Cero mockups** fuera del Dashboard (Login, Catálogo, Contratos, Clientes, Cobranza, Solicitudes).

Detalle completo: archivo `.cursorrules` en la raíz.

---

## 6. Estado de Módulos (Roadmap)

- [x] **Módulo 0:** Cimientos, Stack, Phinx y Reglas Senior.
- [x] **Módulo 1:** Dashboard General Interno, Puente de MRR, Cartera y Seguridad JWT Nivel Empresa.
- [x] **Módulo 2:** Catálogos Base, Instancias, Listas de Precios, Emisores y RBAC Multinivel (RF-1, RF-2) — pantallas `/configuracion/*`, CRUD API/MySQL real, triggers RNF-3 y suite PHPUnit (BCMath).
- [x] **Parte B — RBAC multinivel:** 15 claves (`principal` + `config_*` incluyendo `config_emisores` e `config_instancias` separados), pantallas separadas Usuarios / Roles, alcance de segmento, revocación de `sesion_activa` ante cambios críticos + auto-logout 401.
- [ ] **Módulo 3:** Motor de Contratos y Bitácora Inmutable (RF-3).
- [ ] **Módulo 4:** Cobranza, Saldos, Portal Cliente con Magic Links e IntegroPAY (RF-4 a RF-8).

---

## 7. Índice de Archivos Activos del Proyecto

### Raíz

| Archivo | Descripción |
|---------|-------------|
| `.cursorrules` | Reglas senior (stack, Phinx, JWT, RBAC §11, Liquid Glass, tipografía, loading lock, cero mockups fuera del Dashboard, TTS). |
| `.env.example` | Plantilla de variables Vite / MySQL / JWT. |
| `.gitignore` | Excluye `node_modules/`, `vendor/`, `dist/`, `.env`, temporales. |
| `.oxlintrc.json` | Oxlint. |
| `composer.json` / `composer.lock` | Dependencias PHP 8.3 + `phpunit/phpunit` ^11 en require-dev. |
| `phinx.php` / `phpunit.xml` | Phinx y PHPUnit (suite `tests/Unit`). |
| `tests/Unit/FinancialRulesTest.php` | Suite oficial RNF-1 / RF-2 / RNF-3 (Puente MRR, paquetes de 5, reversión, inmutabilidad). |
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
| `hooks/usePermissions.ts` | Helpers `canView/Create/Edit/Delete` + `allowedSegment` / `isSegmentLocked`. |
| `hooks/useSegmentLock.ts` | Fija filtro de segmento cuando el JWT trae alcance exclusivo. |
| `context/ScreenPreferencesContext.tsx` | pageSize (10/25/50/100) + densidad de tablas (`localStorage`). |
| `context/LoadingLockContext.tsx` | Candado global + `withScreenLock` / start/stop. |
| `context/ToastContext.tsx` | Toasts globales iOS Liquid Glass (`useToast`: success/error/warning/info/apiError). |
| `components/ui/GlobalLoadingOverlay.tsx` | Barra arcoíris + velo + spinner central limpio en mutaciones. |
| `components/ui/ThemeToggleSwitch.tsx` | Switch iOS luna/sol. |
| `components/ui/StatusPill.tsx` | Píldoras semáforo (verde/ámbar/rojo) con punto luminoso. |
| `components/ui/button.tsx` / `dialog.tsx` / `skeleton.tsx` | Primitivos shadcn. |
| `components/ui/PaginationBar.tsx` | Paginación (total, pageSize, páginas) para tablas del ERP. |
| `components/auth/ProtectedRoute.tsx` | Barrera de login + `requiredModule` RBAC. |
| `components/auth/AccessRestrictedView.tsx` | Vista “Acceso restringido por tu rol”. |
| `components/ui/IosSwitch.tsx` | Switch iOS para matriz CRUD de permisos. |
| `components/layout/InternalLayout.tsx` | Sidebar filtrado por `canView` + padre Configuración si hay ≥1 `config_*` + badge de alcance. |
| `components/auth/SessionSecurityBridge.tsx` | Escucha 401 globales → toast + logout + `/login`. |
| `components/dashboard/MrrBridgeChart.tsx` | Puente MRR con colores por tema. |
| `components/dashboard/AgingPortfolioCard.tsx` | Cartera por antigüedad. |
| `components/dashboard/RecentMovementsTable.tsx` | Tabla/tarjetas + status pills. |
| `pages/LoginPage.tsx` | Login 2 columnas Liquid Glass → `POST /api/login`. |
| `pages/DashboardPage.tsx` | Dashboard KPIs + switch Mockup/API (única excepción). |
| `pages/config/CatalogServicesPage.tsx` | `/configuracion/catalogo` — tabla paginada API + filtros + CRUD. |
| `pages/config/PriceListsPage.tsx` | `/configuracion/precios` — directorio → detalle con columna Rango/Escala (RF-2), validación de traslapes y `saveVolumeMatrix`. |
| `pages/config/PromotionsPage.tsx` | `/configuracion/promociones` — cards verde/ámbar + filtros. |
| `pages/config/IssuersPage.tsx` | `/configuracion/emisores` — CRUD exclusivo RF-1 (`emisor`) con filtros, badges IntegroPAY y paginación (`config_emisores`). |
| `pages/config/InstancesPage.tsx` | `/configuracion/instancias` — CRUD exclusivo de instancias/VPS; filtro por emisor; sin modal de emisores (`config_instancias`). |
| `pages/config/ScreenPreferencesPage.tsx` | `/configuracion/preferencias` — pageSize y densidad (`config_preferencias`). |
| `pages/config/UsersManagementPage.tsx` | `/configuracion/usuarios` — cuentas, rol y segmento (`config_usuarios`). |
| `pages/config/RolesPermissionsPage.tsx` | `/configuracion/roles` — matriz multinivel sin preselección (`config_roles`). |
| `services/sessionSecurity.ts` / `lib/criticalRelogin.ts` | Interceptor 401 + logout diferido ante cambios críticos propios. |
| `pages/SalesPromotionsPage.tsx` | `/promociones-vigentes` — solo lectura + copiar código (ventas). |
| `types/auth.ts` / `types/mrr.ts` / `types/catalog.ts` | Tipos (incluye `ModuleKey`, `PermissionsMap`, alcance de segmento). |
| `services/authService.ts` | Login HTTP real + JWT con perfil RBAC. |
| `services/rbacService.ts` | CRUD roles/permisos/usuarios contra API MySQL. |
| `services/dashboardService.ts` | Dashboard híbrido (mock solo aquí) + Bearer JWT. |
| `services/catalogService.ts` | CRUD + queries paginadas catálogo/emisores (JWT); `createIssuer` / `updateIssuer`. |
| `services/mockDashboardData.ts` | Mock sep 2026 (solo Dashboard / demos). |
| `hooks/useDashboardData.ts` | TanStack Query dashboard. |
| `hooks/useConfigCatalog.ts` | TanStack Query + mutaciones Configuración + candado. |
| `hooks/useClientTableState.ts` | page/pageSize anclado a Preferencias de pantalla. |
| `lib/utils.ts` / `lib/mrrCalculator.ts` | Utilidades y motor MRR. |
| `lib/pricingCalculator.ts` | RF-2 `optimizeUserPackages` (centavos). |

### `Api/` / `System/` / `database/` / `backend/`

| Área | Descripción |
|------|-------------|
| `Api/index.php` | Solo `login` libre; resto JWT; CORS; catálogo + `issuers` + `movements` + `roles` / `users` / `me` + `segments`. |
| `backend/bootstrap/env.php` | Carga `.env` a `getenv` / `$_ENV`. |
| `backend/bootstrap/eloquent.php` | Capsule Eloquent + MySQL UTC. |
| `System/Core/*` | Result, Context, DataValidator, DataFormat. |
| `System/Support/FinancialCalculator.php` | Calculadora BCMath pura: `optimizeUserPackages`, `calculateMrrBridge`, `buildReversalPayload`. |
| `System/Services/*` | Login, App, RbacAuthHelper, Roles, Users, Issuers, Movements (RNF-3 + reversión) + catálogo. |
| `System/Models/*` | Users/Sessions + Roles + IssuersModel + MovementsModel (inmutable) + catálogo. |
| `database/migrations/*` | MRR + auth + catálogo + RBAC + emisores (000006) + split (000007) + triggers inmutabilidad movimiento RNF-3 (000008). |
| `database/seeds/*` | Sep 2026 + EnterpriseUsers + CatalogAndPricing + RbacRolesAndPermissions (15 módulos + Gestor de Promociones). |
| `backend/src/Models/*` | Movement, Client, Contract, Segment, Issuer, MonthlyMrr, CollectionStatus. |
| `backend/src/Services/MovementService.php` | Alta inmutable auxiliar (Operam\Services) + Money. |
| `System/Services/MovementsService.php` | API `operam/servicios`: INSERT + `createReversalMovement` (bcmath); bloqueo UPDATE/DELETE. |
| `System/Models/MovementsModel.php` | Eloquent `movimiento` con eventos `updating`/`deleting` (RNF-3). |
| `database/migrations/20260925000008_enforce_movimiento_immutability_rnf3.php` | Triggers MySQL anti-UPDATE/DELETE + columna `movimiento_revertido_id`. |
| `backend/tests/MoneyTest.php` | Tests auxiliares de Money (legado; la suite oficial vive en `tests/Unit`). |
