import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import { notifyUnauthorized } from '@/services/sessionSecurity'
import type {
  CollectionAgingBucket,
  CollectionKpis,
  ContractPortfolioItem,
  InvoiceDetail,
  InvoiceListItem,
  InvoiceStatus,
  IssueInvoiceInput,
  RecordPaymentInput,
  SuspensionCheckResult,
  AgingBucketKey,
} from '@/types/collection'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getAuthToken: TokenGetter = () => null

/** Registra el lector de JWT (mismo patrón que solicitudes / contratos). */
export function configureCollectionsAuth(tokenGetter: TokenGetter): void {
  getAuthToken = tokenGetter
}

export class CollectionsApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'CollectionsApiError'
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
    throw new CollectionsApiError('No se pudo conectar con la API.', 0)
  }

  try {
    return await parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized: (had) => notifyUnauthorized(had),
    })
  } catch (error) {
    if (error instanceof ApiClientError) {
      throw new CollectionsApiError(error.message, error.status)
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

export type CollectionsQueryParams = {
  page?: number
  per_page?: number
  search?: string
  status?: InvoiceStatus
  aging_bucket?: AgingBucketKey
  segment_id?: number
  issuer_id?: number
}

export type InvoicesPaginatedResult = {
  items: InvoiceListItem[]
  total: number
  page: number
  per_page: number
  total_pages: number
  kpis: CollectionKpis
  aging: CollectionAgingBucket[]
}

export type ContractsPortfolioResult = {
  items: ContractPortfolioItem[]
  total: number
  page: number
  per_page: number
  total_pages: number
}

const EMPTY_KPIS: CollectionKpis = {
  upcoming_total_mxn: '0.00',
  overdue_total_mxn: '0.00',
  contracts_over_60_days: 0,
  suspended_contracts_count: 0,
}

export async function fetchInvoicesPaginated(
  params: CollectionsQueryParams = {},
): Promise<InvoicesPaginatedResult> {
  const raw = await apiRequest<InvoicesPaginatedResult>(
    `collections/invoices${toQuery({
      page: params.page ?? 1,
      per_page: params.per_page ?? 25,
      search: params.search,
      status: params.status,
      aging_bucket: params.aging_bucket,
      segment_id: params.segment_id,
      issuer_id: params.issuer_id,
    })}`,
  )

  return {
    items: Array.isArray(raw.items) ? raw.items : [],
    total: Number(raw.total ?? 0),
    page: Number(raw.page ?? 1),
    per_page: Number(raw.per_page ?? 25),
    total_pages: Number(raw.total_pages ?? 1),
    kpis: raw.kpis ?? EMPTY_KPIS,
    aging: Array.isArray(raw.aging) ? raw.aging : [],
  }
}

export async function fetchContractsPortfolio(
  params: Pick<CollectionsQueryParams, 'page' | 'per_page' | 'search' | 'segment_id'> = {},
): Promise<ContractsPortfolioResult> {
  const raw = await apiRequest<ContractsPortfolioResult>(
    `collections/contracts${toQuery({
      page: params.page ?? 1,
      per_page: params.per_page ?? 25,
      search: params.search,
      segment_id: params.segment_id,
    })}`,
  )

  return {
    items: Array.isArray(raw.items) ? raw.items : [],
    total: Number(raw.total ?? 0),
    page: Number(raw.page ?? 1),
    per_page: Number(raw.per_page ?? 25),
    total_pages: Number(raw.total_pages ?? 1),
  }
}

export async function fetchInvoiceDetail(id: number): Promise<InvoiceDetail> {
  return apiRequest<InvoiceDetail>(`collections/invoices/${id}`)
}

export async function issueInvoice(data: IssueInvoiceInput): Promise<InvoiceDetail> {
  return apiRequest<InvoiceDetail>('collections/invoices', {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

export async function recordInvoicePayment(
  id: number,
  data: RecordPaymentInput,
): Promise<InvoiceDetail> {
  return apiRequest<InvoiceDetail>(`collections/invoices/${id}/payments`, {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

export async function cancelInvoice(
  id: number,
  data: { notes?: string } = {},
): Promise<InvoiceDetail> {
  return apiRequest<InvoiceDetail>(`collections/invoices/${id}/cancel`, {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

export async function issueInvoiceFromRequest(requestId: number): Promise<InvoiceDetail> {
  return apiRequest<InvoiceDetail>(`collections/invoices/from-request/${requestId}`, {
    method: 'POST',
    body: JSON.stringify({}),
  })
}

export async function reversePayment(
  paymentId: number,
  data: { reason: string },
): Promise<InvoiceDetail> {
  return apiRequest<InvoiceDetail>(`collections/payments/${paymentId}/reverse`, {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

export async function runSuspensionCheck(): Promise<SuspensionCheckResult> {
  return apiRequest<SuspensionCheckResult>('collections/run-suspension-check', {
    method: 'POST',
    body: JSON.stringify({}),
  })
}
