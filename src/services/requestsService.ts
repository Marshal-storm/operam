import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import { notifyUnauthorized } from '@/services/sessionSecurity'
import type {
  ServiceRequestApproveInput,
  ServiceRequestCreateInput,
  ServiceRequestDetail,
  ServiceRequestKpis,
  ServiceRequestListItem,
  ServiceRequestQuoteInput,
  ServiceRequestRejectInput,
  ServiceRequestStatus,
  ServiceRequestType,
} from '@/types/request'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getAuthToken: TokenGetter = () => null

/** Registra el lector de JWT (mismo patrón que contratos / clientes). */
export function configureRequestsAuth(tokenGetter: TokenGetter): void {
  getAuthToken = tokenGetter
}

export class RequestsApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'RequestsApiError'
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
    throw new RequestsApiError('No se pudo conectar con la API.', 0)
  }

  try {
    return await parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized: (had) => notifyUnauthorized(had),
    })
  } catch (error) {
    if (error instanceof ApiClientError) {
      throw new RequestsApiError(error.message, error.status)
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

export type RequestsQueryParams = {
  page?: number
  per_page?: number
  search?: string
  status?: ServiceRequestStatus
  request_type?: ServiceRequestType
  segment_id?: number
}

const EMPTY_KPIS: ServiceRequestKpis = {
  pending_count: 0,
  quoted_count: 0,
  approved_month_count: 0,
  approved_one_time_total_mxn: '0.00',
}

function mapItem(raw: Partial<ServiceRequestListItem['items'] extends (infer I)[] | undefined ? I : never> | Record<string, unknown>): NonNullable<ServiceRequestListItem['items']>[number] {
  const row = raw as Record<string, unknown>
  return {
    id: Number(row.id),
    service_request_id: Number(row.service_request_id),
    service_id: Number(row.service_id),
    service_name: String(row.service_name ?? ''),
    service_code: String(row.service_code ?? ''),
    service_type: (row.service_type ?? 'one_time') as 'recurring' | 'consumption' | 'one_time',
    quantity: Number(row.quantity ?? 1),
    unit_price: String(row.unit_price ?? '0.00'),
    subtotal: String(row.subtotal ?? '0.00'),
    mrr_impact_mxn: String(row.mrr_impact_mxn ?? '0.00'),
    created_at: String(row.created_at ?? ''),
    updated_at: String(row.updated_at ?? ''),
  }
}

function mapRequest(raw: Partial<ServiceRequestListItem> & Record<string, unknown>): ServiceRequestListItem {
  const items = Array.isArray(raw.items) ? raw.items.map((i) => mapItem(i as Record<string, unknown>)) : undefined

  return {
    id: Number(raw.id),
    folio: String(raw.folio ?? ''),
    contract_id: Number(raw.contract_id),
    contract_folio: String(raw.contract_folio ?? ''),
    contract_status: String(raw.contract_status ?? ''),
    contract_mrr_mxn: String(raw.contract_mrr_mxn ?? '0.00'),
    client_id: Number(raw.client_id),
    client_name: String(raw.client_name ?? ''),
    client_rfc: String(raw.client_rfc ?? ''),
    segment_id: Number(raw.segment_id),
    segment_name: String(raw.segment_name ?? ''),
    issuer_id: Number(raw.issuer_id),
    issuer_name: String(raw.issuer_name ?? ''),
    origin: (raw.origin ?? 'internal') as ServiceRequestListItem['origin'],
    request_type: (raw.request_type ?? 'one_time') as ServiceRequestType,
    portal_request_type:
      raw.portal_request_type != null
        ? (String(raw.portal_request_type) as ServiceRequestListItem['portal_request_type'])
        : null,
    status: (raw.status ?? 'pending') as ServiceRequestStatus,
    currency: String(raw.currency ?? 'MXN'),
    subtotal: String(raw.subtotal ?? '0.00'),
    mrr_impact_mxn: String(raw.mrr_impact_mxn ?? '0.00'),
    notes: raw.notes != null ? String(raw.notes) : null,
    resolution_notes: raw.resolution_notes != null ? String(raw.resolution_notes) : null,
    with_exception: Boolean(raw.with_exception),
    exception_policy: raw.exception_policy != null ? String(raw.exception_policy) : null,
    requested_by_user_id: raw.requested_by_user_id != null ? Number(raw.requested_by_user_id) : null,
    requested_by_name: raw.requested_by_name != null ? String(raw.requested_by_name) : null,
    resolved_by_user_id: raw.resolved_by_user_id != null ? Number(raw.resolved_by_user_id) : null,
    resolved_by_name: raw.resolved_by_name != null ? String(raw.resolved_by_name) : null,
    resolved_at: raw.resolved_at != null ? String(raw.resolved_at) : null,
    created_at: String(raw.created_at ?? ''),
    updated_at: String(raw.updated_at ?? ''),
    contract_is_current: Boolean(raw.contract_is_current),
    contract_overdue_balance: String(raw.contract_overdue_balance ?? '0.00'),
    contract_days_overdue: Number(raw.contract_days_overdue ?? 0),
    invoice_id: raw.invoice_id != null ? Number(raw.invoice_id) : null,
    invoice_folio: raw.invoice_folio != null ? String(raw.invoice_folio) : null,
    services_summary: String(raw.services_summary ?? ''),
    items,
    price_list_id: raw.price_list_id != null ? Number(raw.price_list_id) : null,
    contract_frequency: raw.contract_frequency != null ? String(raw.contract_frequency) : undefined,
    contract_exchange_rate: raw.contract_exchange_rate != null ? String(raw.contract_exchange_rate) : undefined,
    contract_currency: raw.contract_currency != null ? String(raw.contract_currency) : undefined,
  }
}

export type RequestsPaginatedResult = {
  items: ServiceRequestListItem[]
  total: number
  page: number
  per_page: number
  total_pages: number
  kpis: ServiceRequestKpis
}

export async function fetchRequestsPaginated(
  params: RequestsQueryParams = {},
): Promise<RequestsPaginatedResult> {
  const raw = await apiRequest<Partial<RequestsPaginatedResult>>(
    `requests${toQuery({
      page: params.page ?? 1,
      per_page: params.per_page ?? 25,
      search: params.search,
      status: params.status,
      request_type: params.request_type,
      segment_id: params.segment_id,
    })}`,
  )

  const items = Array.isArray(raw.items) ? raw.items.map((r) => mapRequest(r as Partial<ServiceRequestListItem> & Record<string, unknown>)) : []
  const kpis = raw.kpis ?? EMPTY_KPIS

  return {
    items,
    total: Number(raw.total ?? items.length),
    page: Number(raw.page ?? params.page ?? 1),
    per_page: Number(raw.per_page ?? params.per_page ?? 25),
    total_pages: Number(raw.total_pages ?? 1),
    kpis: {
      pending_count: Number(kpis.pending_count ?? 0),
      quoted_count: Number(kpis.quoted_count ?? 0),
      approved_month_count: Number(kpis.approved_month_count ?? 0),
      approved_one_time_total_mxn: String(kpis.approved_one_time_total_mxn ?? '0.00'),
    },
  }
}

export async function fetchRequestsPendingCount(): Promise<number> {
  const raw = await apiRequest<{ pending_count?: number }>('requests/pending-count')
  return Number(raw.pending_count ?? 0)
}

export async function fetchRequestDetail(id: number): Promise<ServiceRequestDetail> {
  const mapped = mapRequest(
    await apiRequest<Partial<ServiceRequestListItem> & Record<string, unknown>>(`requests/${id}`),
  )
  return { ...mapped, items: mapped.items ?? [] }
}

export async function createServiceRequest(
  input: ServiceRequestCreateInput,
): Promise<ServiceRequestDetail> {
  const mapped = mapRequest(
    await apiRequest<Partial<ServiceRequestListItem> & Record<string, unknown>>('requests', {
      method: 'POST',
      body: JSON.stringify(input),
    }),
  )
  return { ...mapped, items: mapped.items ?? [] }
}

export async function quoteServiceRequest(
  id: number,
  input: ServiceRequestQuoteInput,
): Promise<ServiceRequestDetail> {
  const mapped = mapRequest(
    await apiRequest<Partial<ServiceRequestListItem> & Record<string, unknown>>(
      `requests/${id}/quote`,
      { method: 'POST', body: JSON.stringify(input) },
    ),
  )
  return { ...mapped, items: mapped.items ?? [] }
}

export async function approveServiceRequest(
  id: number,
  input: ServiceRequestApproveInput = {},
): Promise<ServiceRequestDetail> {
  const mapped = mapRequest(
    await apiRequest<Partial<ServiceRequestListItem> & Record<string, unknown>>(
      `requests/${id}/approve`,
      { method: 'POST', body: JSON.stringify(input) },
    ),
  )
  return { ...mapped, items: mapped.items ?? [] }
}

export async function rejectServiceRequest(
  id: number,
  input: ServiceRequestRejectInput,
): Promise<ServiceRequestDetail> {
  const mapped = mapRequest(
    await apiRequest<Partial<ServiceRequestListItem> & Record<string, unknown>>(
      `requests/${id}/reject`,
      { method: 'POST', body: JSON.stringify(input) },
    ),
  )
  return { ...mapped, items: mapped.items ?? [] }
}

export async function cancelServiceRequest(
  id: number,
  input: { resolution_notes?: string } = {},
): Promise<ServiceRequestDetail> {
  const mapped = mapRequest(
    await apiRequest<Partial<ServiceRequestListItem> & Record<string, unknown>>(
      `requests/${id}/cancel`,
      { method: 'POST', body: JSON.stringify(input) },
    ),
  )
  return { ...mapped, items: mapped.items ?? [] }
}
