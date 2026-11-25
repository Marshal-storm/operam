import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import { notifyUnauthorized } from '@/services/sessionSecurity'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getAuthToken: TokenGetter = () => null

/** Registra el lector de JWT para contactos del cliente (RF-1.5). */
export function configureClientContactsAuth(tokenGetter: TokenGetter): void {
  getAuthToken = tokenGetter
}

export class ClientContactsApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'ClientContactsApiError'
    this.status = status
  }
}

export type ClientContactRole = 'billing' | 'admin' | 'technical'

export type ClientContact = {
  id: number
  client_id: number
  erp_contact_id: string | null
  name: string
  email: string
  phone: string | null
  role: ClientContactRole
  receives_portal: boolean
  active: boolean
  created_at: string
  updated_at: string
}

export type ClientContactWriteInput = {
  client_id: number
  erp_contact_id?: string | null
  name: string
  email: string
  phone?: string | null
  role: ClientContactRole
  receives_portal: boolean
  active?: boolean
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
    throw new ClientContactsApiError('No se pudo conectar con la API.', 0)
  }

  try {
    return await parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized: (had) => notifyUnauthorized(had),
    })
  } catch (error) {
    if (error instanceof ApiClientError) {
      throw new ClientContactsApiError(error.message, error.status)
    }
    throw error
  }
}

function mapContact(raw: unknown): ClientContact {
  const row = (raw ?? {}) as Record<string, unknown>
  const rawRole = String(row.role ?? 'admin')
  const role: ClientContactRole =
    rawRole === 'billing' || rawRole === 'technical' ? rawRole : 'admin'
  return {
    id: Number(row.id ?? 0),
    client_id: Number(row.client_id ?? 0),
    erp_contact_id:
      row.erp_contact_id != null && row.erp_contact_id !== ''
        ? String(row.erp_contact_id)
        : null,
    name: String(row.name ?? ''),
    email: String(row.email ?? ''),
    phone: row.phone != null && row.phone !== '' ? String(row.phone) : null,
    role,
    receives_portal: Boolean(Number(row.receives_portal ?? 0)),
    active: Boolean(Number(row.active ?? 0)),
    created_at: String(row.created_at ?? ''),
    updated_at: String(row.updated_at ?? ''),
  }
}

export async function fetchClientContacts(clientId: number): Promise<ClientContact[]> {
  const raw = await apiRequest<unknown>(`client-contacts?client_id=${clientId}`)
  return Array.isArray(raw) ? raw.map(mapContact) : []
}

export async function createClientContact(
  input: ClientContactWriteInput,
): Promise<ClientContact> {
  const raw = await apiRequest<unknown>('client-contacts', {
    method: 'POST',
    body: JSON.stringify(input),
  })
  return mapContact(raw)
}

export async function updateClientContact(
  id: number,
  input: Partial<ClientContactWriteInput>,
): Promise<ClientContact> {
  const raw = await apiRequest<unknown>(`client-contacts/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
  return mapContact(raw)
}

export async function patchClientContact(
  id: number,
  input: Partial<ClientContactWriteInput>,
): Promise<ClientContact> {
  const raw = await apiRequest<unknown>(`client-contacts/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
  return mapContact(raw)
}

export async function deleteClientContact(id: number): Promise<void> {
  await apiRequest<unknown>(`client-contacts/${id}`, {
    method: 'DELETE',
  })
}
