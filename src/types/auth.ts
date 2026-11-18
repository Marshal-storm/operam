/** Roles legacy (columna enum) — compatibilidad. */
export type UserRole = 'admin' | 'operador' | 'lectura'

/** Claves multinivel del catálogo RBAC. */
export type ModuleKey =
  | 'dashboard'
  | 'contratos'
  | 'clientes'
  | 'solicitudes'
  | 'cobranza'
  | 'utilidad_segmento'
  | 'promociones_ventas'
  | 'config_catalogo'
  | 'config_precios'
  | 'config_promociones'
  | 'config_emisores'
  | 'config_instancias'
  | 'config_usuarios'
  | 'config_roles'
  | 'config_preferencias'

export type ModulePermission = {
  ver: boolean
  crear: boolean
  editar: boolean
  eliminar: boolean
}

export type PermissionsMap = Partial<Record<ModuleKey, ModulePermission>>

export type AllowedSegment = 'Todos' | 'Operam' | 'Construsync'

/** Usuario autenticado expuesto al frontend (sin password_hash). */
export type AuthUser = {
  id: number
  email: string
  nombre: string
  rol: UserRole
  rol_id: number | null
  rol_nombre: string | null
  rol_codigo: string | null
  segmento_id: number | null
  segmento_nombre: AllowedSegment | string
  permisos: PermissionsMap
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
  'contratos',
  'clientes',
  'solicitudes',
  'cobranza',
  'utilidad_segmento',
  'promociones_ventas',
]

export const CONFIG_MODULE_KEYS: ModuleKey[] = [
  'config_catalogo',
  'config_precios',
  'config_promociones',
  'config_emisores',
  'config_instancias',
  'config_usuarios',
  'config_roles',
  'config_preferencias',
]

export const MODULE_KEYS: ModuleKey[] = [
  ...PRINCIPAL_MODULE_KEYS,
  ...CONFIG_MODULE_KEYS,
]
