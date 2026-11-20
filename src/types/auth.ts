/** Claves multinivel del catálogo RBAC (inglés, alineadas al API). */
export type ModuleKey =
  | 'dashboard'
  | 'contracts'
  | 'clients'
  | 'requests'
  | 'collections'
  | 'segment_utility'
  | 'sales_promotions'
  | 'config_catalog'
  | 'config_prices'
  | 'config_promotions'
  | 'config_issuers'
  | 'config_instances'
  | 'config_users'
  | 'config_roles'
  | 'config_company'
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

export const PRINCIPAL_MODULE_KEYS: ModuleKey[] = [
  'dashboard',
  'contracts',
  'clients',
  'requests',
  'collections',
  'segment_utility',
  'sales_promotions',
]

export const CONFIG_MODULE_KEYS: ModuleKey[] = [
  'config_catalog',
  'config_prices',
  'config_promotions',
  'config_issuers',
  'config_instances',
  'config_users',
  'config_roles',
  'config_company',
  'config_preferences',
]

export const MODULE_KEYS: ModuleKey[] = [
  ...PRINCIPAL_MODULE_KEYS,
  ...CONFIG_MODULE_KEYS,
]
