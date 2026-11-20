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
  module_key: ModuleKey | string
  name: string
  group_key: string
  sort_order: number
}

export type RoleRow = {
  id: number
  code: string
  name: string
  description: string | null
  is_system: boolean
  is_active: boolean
  permissions: PermissionsMap
}

export type ManagedUser = {
  id: number
  email: string
  name: string
  role_id: number | null
  role_name: string | null
  role_code: string | null
  segment_id: number | null
  segment_name: string
  is_active: boolean
  last_login_at: string | null
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
  code: string
  name: string
  description?: string
  permissions?: PermissionsMap
}): Promise<RoleRow> {
  return apiFetch<RoleRow>('/roles', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function saveRolePermissions(
  roleId: number,
  permissions: Record<string, ModulePermission>,
): Promise<RoleRow> {
  return apiFetch<RoleRow>(`/roles/${roleId}/permissions`, {
    method: 'PUT',
    body: JSON.stringify({ permissions }),
  })
}

export function fetchManagedUsers(params: {
  page?: number
  per_page?: number
  search?: string
  role_id?: number | string
  segment?: string
  status?: string
}): Promise<UsersPage> {
  const qs = new URLSearchParams()
  if (params.page) qs.set('page', String(params.page))
  if (params.per_page) qs.set('per_page', String(params.per_page))
  if (params.search) qs.set('search', params.search)
  if (params.role_id != null && params.role_id !== '') qs.set('role_id', String(params.role_id))
  if (params.segment) qs.set('segment', params.segment)
  if (params.status) qs.set('status', params.status)
  const suffix = qs.toString() ? `?${qs}` : ''
  return apiFetch<UsersPage>(`/users${suffix}`)
}

export function createManagedUser(input: {
  email: string
  name: string
  password: string
  role_id: number
  segment_id: number | null
  is_active?: boolean
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
    name: string
    password: string
    role_id: number
    segment_id: number | null
    is_active: boolean
  }>,
): Promise<ManagedUser> {
  return apiFetch<ManagedUser>(`/users/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}
