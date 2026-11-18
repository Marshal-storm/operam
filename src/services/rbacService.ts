import { notifyUnauthorized } from '@/services/sessionSecurity'
import type { ModuleKey, ModulePermission, PermissionsMap } from '@/types/auth'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getToken: TokenGetter = () => null

export function configureRbacAuth(tokenGetter: TokenGetter): void {
  getToken = tokenGetter
}

export class RbacApiError extends Error {
  status?: number
  constructor(message: string, status?: number) {
    super(message)
    this.name = 'RbacApiError'
    this.status = status
  }
}

async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const token = getToken()
  const hadBearer = Boolean(token)
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(init?.headers as Record<string, string> | undefined),
  }
  if (token) headers.Authorization = `Bearer ${token}`
  if (init?.body) headers['Content-Type'] = 'application/json'

  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}${path}`, { ...init, headers })
  } catch {
    throw new RbacApiError('No se pudo conectar con la API.', 0)
  }

  let payload: {
    success?: boolean
    message?: string
    data?: T
  } = {}
  try {
    payload = (await response.json()) as typeof payload
  } catch {
    /* sin JSON */
  }

  if (response.status === 401) {
    notifyUnauthorized(hadBearer)
  }

  if (!response.ok || payload.success === false) {
    throw new RbacApiError(payload.message || 'Error en la operación.', response.status)
  }

  return payload.data as T
}

export type SystemModule = {
  id: number
  clave: ModuleKey | string
  nombre: string
  grupo: string
  orden: number
}

export type RoleRow = {
  id: number
  codigo: string
  nombre: string
  descripcion: string | null
  es_sistema: boolean
  activo: boolean
  permisos: PermissionsMap
}

export type ManagedUser = {
  id: number
  email: string
  nombre: string
  rol: string
  rol_id: number | null
  rol_nombre: string | null
  rol_codigo: string | null
  segmento_id: number | null
  segmento_nombre: string
  activo: boolean
  ultimo_acceso: string | null
  created_at?: string
}

export type UsersPage = {
  items: ManagedUser[]
  total: number
  page: number
  per_page: number
  total_pages: number
}

export function fetchRoles(): Promise<RoleRow[]> {
  return apiFetch<RoleRow[]>('/roles')
}

export function fetchSystemModules(): Promise<SystemModule[]> {
  return apiFetch<SystemModule[]>('/roles/modules')
}

export function createRole(input: {
  codigo: string
  nombre: string
  descripcion?: string
  permisos?: PermissionsMap
}): Promise<RoleRow> {
  return apiFetch<RoleRow>('/roles', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function saveRolePermissions(
  roleId: number,
  permisos: Record<string, ModulePermission>,
): Promise<RoleRow> {
  return apiFetch<RoleRow>(`/roles/${roleId}/permissions`, {
    method: 'PUT',
    body: JSON.stringify({ permisos }),
  })
}

export function fetchManagedUsers(params: {
  page?: number
  per_page?: number
  search?: string
  rol_id?: number | string
  segmento?: string
  estado?: string
}): Promise<UsersPage> {
  const qs = new URLSearchParams()
  if (params.page) qs.set('page', String(params.page))
  if (params.per_page) qs.set('per_page', String(params.per_page))
  if (params.search) qs.set('search', params.search)
  if (params.rol_id != null && params.rol_id !== '') qs.set('rol_id', String(params.rol_id))
  if (params.segmento) qs.set('segmento', params.segmento)
  if (params.estado) qs.set('estado', params.estado)
  const suffix = qs.toString() ? `?${qs}` : ''
  return apiFetch<UsersPage>(`/users${suffix}`)
}

export function createManagedUser(input: {
  email: string
  nombre: string
  password: string
  rol_id: number
  segmento_id: number | null
  activo?: boolean
}): Promise<ManagedUser> {
  return apiFetch<ManagedUser>('/users', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updateManagedUser(
  id: number,
  input: Partial<{
    email: string
    nombre: string
    password: string
    rol_id: number
    segmento_id: number | null
    activo: boolean
  }>,
): Promise<ManagedUser> {
  return apiFetch<ManagedUser>(`/users/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}
