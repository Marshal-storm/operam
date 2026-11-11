# Operam — Plataforma Central de Contratos y Suscripciones

Manual técnico oficial de onboarding para desarrolladores.  
**Frontend:** React + TypeScript + Vite + Tailwind + shadcn/ui · iOS Liquid Glass · tipografía Ubuntu / DM Sans / DM Mono.  
**Backend:** PHP 8.3 + Illuminate 11 + Phinx + `operam/servicios` + JWT HS256.  
**Shell:** Sidebar full-height + Header en columna derecha · barra arcoíris Operam + candado de pantalla.

---

## 1. Resumen y Arquitectura

Operam es la **plataforma central de contratos y suscripciones** para los productos **Operam** y **Construsync** (y segmentos adicionales).

### Modo híbrido Mockup / API Real

- En Login y Dashboard el switch **Mockup | API Real** permite desarrollar UI sin backend y validar contra la API PHP.
- Mockup usa datos determinísticos (cierre sep 2026) y usuarios del seeder.
- API Real consume `VITE_API_URL` / `VITE_API_BASE_URL` con Bearer JWT.

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

Asegura que `.env` esté cargado (Phinx ya lo lee; para el servidor built-in, exporta variables o usa un front controller que cargue `.env`).

Build de verificación frontend:

```bash
npm run build
```

---

## 4. Credenciales de Acceso (Desarrollo / Seeders)

Usuarios del `EnterpriseUsersSeeder` (idempotente):

| Email | Password | Rol |
|-------|----------|-----|
| `admin@operam.mx` | `Operam2026*` | `admin` |
| `operador@operam.mx` | `Operam2026*` | `operador` |
| `supervisor@construsync.mx` | `Constru2026*` | `operador` (segmento Construsync) |

### Flujo JWT empresarial

1. `POST /api/login` → `LoginService` valida credenciales.
2. Firma **JWT HS256** con `JWT_TOKEN_KEY` (`iss` = `JWT_TOKEN_ISSUER`, `exp` = `DEF_EXPIRATION_TIME`).
3. Sesión revocable en tabla `sesion_activa` (claim `jti`).
4. Rate limiting de fallos en `auth_intento` (bloqueo temporal 429).
5. Rutas protegidas en `AppService::before_get` excepto `$freeuris` (`login`).

En UI: botón **Autocompletar credenciales de prueba** en Login (modo Mockup).

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

Detalle completo: archivo `.cursorrules` en la raíz.

---

## 6. Estado de Módulos (Roadmap)

- [x] **Módulo 0:** Cimientos, Stack, Phinx y Reglas Senior.
- [x] **Módulo 1:** Dashboard General Interno, Puente de MRR, Cartera y Seguridad JWT Nivel Empresa.
- [ ] **Módulo 2:** Catálogos Base, Instancias y Listas de Precios (RF-1, RF-2).
- [ ] **Módulo 3:** Motor de Contratos y Bitácora Inmutable (RF-3).
- [ ] **Módulo 4:** Cobranza, Saldos, Portal Cliente con Magic Links e IntegroPAY (RF-4 a RF-8).

---

## 7. Índice de Archivos Activos del Proyecto

### Raíz

| Archivo | Descripción |
|---------|-------------|
| `.cursorrules` | Reglas senior (stack, Phinx, JWT, Liquid Glass, tipografía, loading lock, TTS). |
| `.env.example` | Plantilla de variables Vite / MySQL / JWT. |
| `.gitignore` | Excluye `node_modules/`, `vendor/`, `dist/`, `.env`, temporales. |
| `.oxlintrc.json` | Oxlint. |
| `composer.json` / `composer.lock` | Dependencias PHP 8.3. |
| `phinx.php` / `phpunit.xml` | Phinx y PHPUnit. |
| `index.html` | Entrada Vite + Google Fonts (Ubuntu / DM Sans / DM Mono). |
| `tailwind.config.js` | Referencia `fontFamily` oficial (Tailwind v4 usa `@theme` en CSS). |
| `package.json` / `package-lock.json` | Dependencias npm. |
| `README.md` | Manual de onboarding + índice vivo. |
| `tsconfig*.json` / `vite.config.ts` | TypeScript y Vite. |
| `Api/` | Entrada HTTP del framework. |
| `System/` | Núcleo `operam/servicios`. |
| `backend/` | Eloquent auxiliar + Money. |
| `database/` | Migraciones y seeders Phinx. |
| `public/` | Assets estáticos. |
| `src/` | Frontend React. |

### `src/` (frontend)

| Archivo | Descripción |
|---------|-------------|
| `main.tsx` / `App.tsx` | Montaje + providers + `GlobalLoadingOverlay` + router. |
| `index.css` | Tokens Liquid Glass + tipografía Operam + rainbow bar. |
| `context/ThemeContext.tsx` | Tema `system` \| `light` \| `dark` + `localStorage`. |
| `context/AuthContext.tsx` | Sesión JWT global. |
| `context/LoadingLockContext.tsx` | Candado global + `withScreenLock` / start/stop. |
| `components/ui/GlobalLoadingOverlay.tsx` | Barra arcoíris + velo limpio (sin modal). |
| `components/ui/ThemeToggleSwitch.tsx` | Switch iOS luna/sol. |
| `components/ui/StatusPill.tsx` | Píldoras de estado con punto luminoso. |
| `components/ui/button.tsx` / `dialog.tsx` / `skeleton.tsx` | Primitivos shadcn. |
| `components/auth/ProtectedRoute.tsx` | Barrera de login. |
| `components/layout/InternalLayout.tsx` | Sidebar (colapso + tema al pie); perfil en header. |
| `components/dashboard/MrrBridgeChart.tsx` | Puente MRR con colores por tema. |
| `components/dashboard/AgingPortfolioCard.tsx` | Cartera por antigüedad. |
| `components/dashboard/RecentMovementsTable.tsx` | Tabla/tarjetas + status pills. |
| `pages/LoginPage.tsx` | Login 2 columnas Liquid Glass. |
| `pages/DashboardPage.tsx` | Dashboard KPIs + filtros píldora iOS. |
| `types/auth.ts` / `types/mrr.ts` | Tipos. |
| `services/authService.ts` | Login Mockup/API. |
| `services/dashboardService.ts` | Dashboard + Bearer JWT. |
| `services/mockDashboardData.ts` | Mock sep 2026. |
| `hooks/useDashboardData.ts` | TanStack Query. |
| `lib/utils.ts` / `lib/mrrCalculator.ts` | Utilidades y motor MRR. |

### `Api/` / `System/` / `database/` / `backend/`

| Área | Descripción |
|------|-------------|
| `Api/index.php` | Solo `login` libre; resto JWT. |
| `System/Core/*` | Result, Context, DataValidator, DataFormat. |
| `System/Services/*` | LoginService, AppService, UsersService, ActiveSessionsService. |
| `System/Models/*` | UsersModel, ActiveSessionsModel, AuthAttemptModel. |
| `database/migrations/*` | MRR + auth enterprise. |
| `database/seeds/*` | Sep 2026 + EnterpriseUsers. |
| `backend/src/Models/*` | Movement, Client, Contract, Segment, Issuer, MonthlyMrr, CollectionStatus. |
| `backend/src/Services/MovementService.php` | Alta inmutable de `movimiento` + Money. |
| `backend/bootstrap/*` | Capsule Eloquent + helpers. |
| `backend/tests/MoneyTest.php` | Tests de Money. |
