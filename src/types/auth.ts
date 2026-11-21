/** Claves multinivel del catálogo RBAC (18 módulos, inglés, alineadas al API). */
export type ModuleKey =
  | 'dashboard'
  | 'contracts'
  | 'clients'
  | 'requests'
  | 'collections'
  | 'segment_profitability'
  | 'sales_promotions'
  | 'config_catalog'
  | 'config_prices'
  | 'config_promotions'
  | 'config_issuers'
  | 'config_instances'
  | 'config_users'
  | 'config_roles'
  | 'config_company'
  | 'config_system'
  | 'config_audit_logs'
  | 'config_preferences'

export type ModulePermission = {
  view: boolean
  create: boolean
  edit: boolean
  delete: boolean
}

export type PermissionsMap = Partial<Record<ModuleKey, ModulePermission>>

/** Nombre de segmento desde MySQL, o "Todos" cuando no hay alcance fijo. */
export type AllowedSegment = string

/** Usuario autenticado expuesto al frontend (sin password_hash). */
export type AuthUser = {
  id: number
  email: string
  name: string
  role_id: number | null
  role_name: string | null
  role_code: string | null
  segment_id: number | null
  segment_name: AllowedSegment | string
  permissions: PermissionsMap
  scope: 'enterprise'
}

export type AuthSession = {
  token: string
  tokenType: 'Bearer'
  expiresIn: number
  user: AuthUser
}

export type LoginCredentials = {
  email: string
  password: string
}

/** Nivel 1 — operativos (`group_key = 'main'`). */
export const PRINCIPAL_MODULE_KEYS: ModuleKey[] = [
  'dashboard',
  'contracts',
  'clients',
  'requests',
  'collections',
  'segment_profitability',
  'sales_promotions',
]

/** Nivel 2 — configuración (`group_key = 'configuration'`). */
export const CONFIG_MODULE_KEYS: ModuleKey[] = [
  'config_catalog',
  'config_prices',
  'config_promotions',
  'config_issuers',
  'config_instances',
  'config_users',
  'config_roles',
  'config_company',
  'config_system',
  'config_audit_logs',
  'config_preferences',
]

export const MODULE_KEYS: ModuleKey[] = [
  ...PRINCIPAL_MODULE_KEYS,
  ...CONFIG_MODULE_KEYS,
]

/** Módulos de solo lectura: Crear/Editar/Eliminar no aplican en la matriz UI. */
export const READ_ONLY_MODULE_KEYS: ReadonlySet<ModuleKey> = new Set([
  'dashboard',
  'sales_promotions',
  'segment_profitability',
  'config_audit_logs',
])

/** Solo Ver + Editar. */
export const VIEW_EDIT_MODULE_KEYS: ReadonlySet<ModuleKey> = new Set([
  'config_company',
  'config_preferences',
])

/** Ver + Editar + Eliminar (sin Crear). */
export const VIEW_EDIT_DELETE_MODULE_KEYS: ReadonlySet<ModuleKey> = new Set([
  'config_system',
])

/** Indica si una columna CRUD aplica al module_key en la matriz de roles. */
export function isCrudActionApplicable(
  moduleKey: string,
  action: keyof ModulePermission,
): boolean {
  if (action === 'view') return true
  if (READ_ONLY_MODULE_KEYS.has(moduleKey as ModuleKey)) return false
  if (VIEW_EDIT_MODULE_KEYS.has(moduleKey as ModuleKey)) return action === 'edit'
  if (VIEW_EDIT_DELETE_MODULE_KEYS.has(moduleKey as ModuleKey)) {
    return action === 'edit' || action === 'delete'
  }
  return true
}
