import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import { notifyUnauthorized } from '@/services/sessionSecurity'
import type { Client, ClientsSummary, ClientWriteInput } from '@/types/client'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getAuthToken: TokenGetter = () => null

/** Registra el lector de JWT (mismo patrón que catálogo / RBAC). */
export function configureClientsAuth(tokenGetter: TokenGetter): void {
  getAuthToken = tokenGetter
}

export class ClientsApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'ClientsApiError'
    this.status = status
  }
}

async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getAuthToken()
  const hadBearer = Boolean(token)
  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}/${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(options.headers ?? {}),
      },
    })
  } catch {
    throw new ClientsApiError('No se pudo conectar con la API.', 0)
  }

  try {
    return await parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized: (had) => notifyUnauthorized(had),
    })
  } catch (error) {
    if (error instanceof ApiClientError) {
      throw new ClientsApiError(error.message, error.status)
    }
    throw error
  }
}

function toQuery(params: Record<string, string | number | boolean | undefined | null>): string {
  const qs = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    qs.set(key, String(value))
  }
  const s = qs.toString()
  return s ? `?${s}` : ''
}

export type ClientsQueryParams = {
  page?: number
  per_page?: number
  search?: string
  segment_id?: number
  issuer_id?: number
  active?: boolean
  exclude_suspension?: boolean
}

export type ClientsPaginatedResult = {
  items: Client[]
  total: number
  page: number
  per_page: number
  total_pages: number
  summary: ClientsSummary
}

const EMPTY_SUMMARY: ClientsSummary = {
  total: 0,
  active_count: 0,
  auto_stamp_count: 0,
  excluded_suspension_count: 0,
}

function mapClient(raw: Partial<Client>): Client {
  return {
    id: Number(raw.id ?? 0),
    segment_id: Number(raw.segment_id ?? 0),
    issuer_id: Number(raw.issuer_id ?? 0),
    erp_client_id: raw.erp_client_id ?? null,
    name: String(raw.name ?? ''),
    legal_name: String(raw.legal_name ?? ''),
    rfc: String(raw.rfc ?? ''),
    contact_name: raw.contact_name ?? null,
    contact_email: raw.contact_email ?? null,
    contact_phone: raw.contact_phone ?? null,
    credit_days: Number(raw.credit_days ?? 0),
    stamp_on_issue: Boolean(raw.stamp_on_issue),
    exclude_suspension: Boolean(raw.exclude_suspension),
    active: Boolean(raw.active),
    segment_name: raw.segment_name ?? null,
    issuer_name: raw.issuer_name ?? null,
    active_contracts_count: Number(raw.active_contracts_count ?? 0),
    created_at: raw.created_at ?? null,
    updated_at: raw.updated_at ?? null,
  }
}

export async function fetchClientsPaginated(
  params: ClientsQueryParams = {},
): Promise<ClientsPaginatedResult> {
  const raw = await apiRequest<Partial<ClientsPaginatedResult>>(
    `clients${toQuery({
      page: params.page ?? 1,
      per_page: params.per_page ?? 25,
      search: params.search,
      segment_id: params.segment_id,
      issuer_id: params.issuer_id,
      active: params.active === undefined ? undefined : params.active ? 1 : 0,
      exclude_suspension:
        params.exclude_suspension === undefined
          ? undefined
          : params.exclude_suspension
            ? 1
            : 0,
    })}`,
  )

  const items = Array.isArray(raw.items) ? raw.items.map(mapClient) : []
  const summary = raw.summary ?? EMPTY_SUMMARY

  return {
    items,
    total: Number(raw.total ?? items.length),
    page: Number(raw.page ?? params.page ?? 1),
    per_page: Number(raw.per_page ?? params.per_page ?? 25),
    total_pages: Number(raw.total_pages ?? 1),
    summary: {
      total: Number(summary.total ?? 0),
      active_count: Number(summary.active_count ?? 0),
      auto_stamp_count: Number(summary.auto_stamp_count ?? 0),
      excluded_suspension_count: Number(summary.excluded_suspension_count ?? 0),
    },
  }
}

export async function fetchClient(id: number): Promise<Client> {
  return mapClient(await apiRequest<Partial<Client>>(`clients/${id}`))
}

export async function createClient(input: ClientWriteInput): Promise<Client> {
  return mapClient(
    await apiRequest<Partial<Client>>('clients', {
      method: 'POST',
      body: JSON.stringify(input),
    }),
  )
}

export async function updateClient(
  id: number,
  input: Partial<ClientWriteInput>,
): Promise<Client> {
  return mapClient(
    await apiRequest<Partial<Client>>(`clients/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(input),
    }),
  )
}

export async function toggleClientActive(id: number, active: boolean): Promise<Client> {
  return updateClient(id, { active })
}
