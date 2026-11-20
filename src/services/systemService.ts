import { notifyUnauthorized } from '@/services/sessionSecurity'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getAuthToken: TokenGetter = () => null

/** Registra el lector de JWT, igual que `configureCatalogAuth`. */
export function configureSystemAuth(tokenGetter: TokenGetter): void {
  getAuthToken = tokenGetter
}

export class SystemApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'SystemApiError'
    this.status = status
  }
}

export const SYSTEM_SETTINGS_QUERY_KEY = ['system-settings'] as const
export const AUDIT_LOGS_QUERY_KEY = ['audit-logs'] as const

/** Acciones persistidas en `audit_logs.action`. */
export type AuditLogAction =
  | 'create'
  | 'update'
  | 'delete'
  | 'status_change'
  | 'login'
  | 'logout'
  | 'revoke_session'
  | 'purge_logs'

export type SystemSettingsSnapshot = {
  audit_logs_enabled: boolean
  audit_logs_total: number
  audit_logs_oldest_at: string | null
}

export type AuditPurgeResult = {
  deleted_count: number
  up_to_date: string
  cutoff_utc: string
}

export type AuditLogItem = {
  id: number
  user_id: number | null
  user_name: string | null
  user_email: string | null
  segment_id: number | null
  module_key: string
  entity_type: string
  entity_id: string | null
  action: AuditLogAction | string
  description: string
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string
  user_agent: string | null
  created_at: string
}

export type AuditLogsQuery = {
  page?: number
  per_page?: number
  search?: string
  user_id?: number | string
  module_key?: string
  action?: string
  date_from?: string
  date_to?: string
}

export type AuditLogsPage = {
  items: AuditLogItem[]
  total: number
  page: number
  per_page: number
  total_pages: number
  audit_logs_enabled: boolean
}

async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getAuthToken()
  const hadBearer = Boolean(token)
  const response = await fetch(`${API_BASE_URL}/${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers ?? {}),
    },
  })

  let body: { success?: boolean; data?: T; message?: string } = {}
  let parsed = false
  try {
    body = (await response.json()) as typeof body
    parsed = true
  } catch {
    /* HTML de un fatal PHP no es una sesión revocada */
  }

  if (response.status === 401) {
    notifyUnauthorized(hadBearer)
  }

  if (!parsed || !response.ok || body.success === false) {
    throw new SystemApiError(
      body.message ??
        (parsed ? `Error HTTP ${response.status}` : 'La API no devolvió JSON.'),
      response.status,
    )
  }

  return (body.data ?? body) as T
}

function toQuery(params: Record<string, string | number | undefined | null>): string {
  const qs = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    qs.set(key, String(value))
  }
  const serialized = qs.toString()
  return serialized ? `?${serialized}` : ''
}

function asBool(value: unknown): boolean {
  if (typeof value === 'boolean') return value
  if (typeof value === 'number') return value === 1
  if (typeof value === 'string') {
    return ['1', 'true', 'yes', 'on'].includes(value.toLowerCase())
  }
  return false
}

function asRecord(value: unknown): Record<string, unknown> | null {
  if (value == null || value === '') return null
  if (Array.isArray(value)) return { items: value }
  if (typeof value === 'object') return value as Record<string, unknown>
  return { value }
}

function normalizeSettings(raw: unknown): SystemSettingsSnapshot {
  const row = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
  const oldest = row.audit_logs_oldest_at
  return {
    audit_logs_enabled: asBool(row.audit_logs_enabled),
    audit_logs_total: Number(row.audit_logs_total ?? 0),
    audit_logs_oldest_at:
      oldest == null || oldest === '' ? null : String(oldest),
  }
}

function normalizeLog(raw: unknown): AuditLogItem {
  const row = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
  return {
    id: Number(row.id ?? 0),
    user_id: row.user_id == null || row.user_id === '' ? null : Number(row.user_id),
    user_name: row.user_name == null ? null : String(row.user_name),
    user_email: row.user_email == null ? null : String(row.user_email),
    segment_id:
      row.segment_id == null || row.segment_id === '' ? null : Number(row.segment_id),
    module_key: String(row.module_key ?? ''),
    entity_type: String(row.entity_type ?? ''),
    entity_id: row.entity_id == null ? null : String(row.entity_id),
    action: String(row.action ?? ''),
    description: String(row.description ?? ''),
    old_values: asRecord(row.old_values),
    new_values: asRecord(row.new_values),
    ip_address: String(row.ip_address ?? ''),
    user_agent: row.user_agent == null ? null : String(row.user_agent),
    created_at: String(row.created_at ?? ''),
  }
}

/** GET /api/system-settings */
export async function fetchSystemSettings(): Promise<SystemSettingsSnapshot> {
  const raw = await apiRequest<unknown>('system-settings')
  return normalizeSettings(raw)
}

/** PUT /api/system-settings */
export async function updateSystemSettings(input: {
  audit_logs_enabled: boolean
}): Promise<SystemSettingsSnapshot> {
  const raw = await apiRequest<unknown>('system-settings', {
    method: 'PUT',
    body: JSON.stringify({ audit_logs_enabled: input.audit_logs_enabled }),
  })
  return normalizeSettings(raw)
}

/** POST /api/system-settings/purge-audit-logs */
export async function purgeAuditLogs(input: {
  up_to_date: string
}): Promise<AuditPurgeResult> {
  const raw = await apiRequest<unknown>('system-settings/purge-audit-logs', {
    method: 'POST',
    body: JSON.stringify({ up_to_date: input.up_to_date }),
  })
  const row = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
  return {
    deleted_count: Number(row.deleted_count ?? 0),
    up_to_date: String(row.up_to_date ?? input.up_to_date),
    cutoff_utc: String(row.cutoff_utc ?? ''),
  }
}

/** GET /api/audit-logs (paginado + bandera audit_logs_enabled). */
export async function fetchAuditLogs(params: AuditLogsQuery = {}): Promise<AuditLogsPage> {
  const raw = await apiRequest<unknown>(
    `audit-logs${toQuery({
      page: params.page,
      per_page: params.per_page,
      search: params.search,
      user_id: params.user_id,
      module_key: params.module_key,
      action: params.action,
      date_from: params.date_from,
      date_to: params.date_to,
    })}`,
  )
  const row = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
  const items = Array.isArray(row.items) ? row.items.map(normalizeLog) : []
  return {
    items,
    total: Number(row.total ?? items.length),
    page: Number(row.page ?? params.page ?? 1),
    per_page: Number(row.per_page ?? params.per_page ?? 25),
    total_pages: Number(row.total_pages ?? 1),
    audit_logs_enabled: asBool(row.audit_logs_enabled),
  }
}
