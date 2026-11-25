import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import { notifyUnauthorized } from '@/services/sessionSecurity'
import type {
  ExceptionReportRow,
  NonRecurringRow,
  Paginated,
  SegmentCost,
  SegmentCostWriteInput,
  SegmentProfitabilitySummary,
} from '@/types/profitability'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getToken: TokenGetter = () => null

export function configureProfitabilityAuth(tokenGetter: TokenGetter): void {
  getToken = tokenGetter
}

export class ProfitabilityApiError extends Error {
  status?: number
  constructor(message: string, status?: number) {
    super(message)
    this.name = 'ProfitabilityApiError'
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
    throw new ProfitabilityApiError('No se pudo conectar con la API.', 0)
  }

  try {
    return await parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized: (had) => notifyUnauthorized(had),
    })
  } catch (error) {
    if (error instanceof ApiClientError) {
      throw new ProfitabilityApiError(error.message, error.status)
    }
    throw error
  }
}

function qs(params: Record<string, string | number | undefined | null>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    search.set(key, String(value))
  }
  const raw = search.toString()
  return raw ? `?${raw}` : ''
}

export function fetchProfitabilitySummary(params: {
  month?: string
  segment_id?: number | null
}): Promise<SegmentProfitabilitySummary> {
  return apiFetch(
    `/segment-profitability/summary${qs({
      month: params.month,
      segment_id: params.segment_id ?? undefined,
    })}`,
  )
}

export function fetchSegmentCosts(params: {
  page?: number
  per_page?: number
  month?: string
  segment_id?: number | null
  category?: string
  search?: string
}): Promise<Paginated<SegmentCost>> {
  return apiFetch(
    `/segment-profitability/costs${qs({
      page: params.page,
      per_page: params.per_page,
      month: params.month,
      segment_id: params.segment_id ?? undefined,
      category: params.category,
      search: params.search,
    })}`,
  )
}

export function createSegmentCost(input: SegmentCostWriteInput): Promise<SegmentCost> {
  return apiFetch('/segment-profitability/costs', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updateSegmentCost(
  id: number,
  input: Partial<SegmentCostWriteInput>,
): Promise<SegmentCost> {
  return apiFetch(`/segment-profitability/costs/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export function deleteSegmentCost(id: number): Promise<{ id: number }> {
  return apiFetch(`/segment-profitability/costs/${id}`, { method: 'DELETE' })
}

export function fetchNonRecurringRevenue(params: {
  page?: number
  per_page?: number
  month?: string
  segment_id?: number | null
}): Promise<Paginated<NonRecurringRow>> {
  return apiFetch(
    `/segment-profitability/non-recurring${qs({
      page: params.page,
      per_page: params.per_page,
      month: params.month,
      segment_id: params.segment_id ?? undefined,
    })}`,
  )
}

export function fetchExceptionsReport(params: {
  page?: number
  per_page?: number
  from?: string
  to?: string
  segment_id?: number | null
}): Promise<Paginated<ExceptionReportRow>> {
  return apiFetch(
    `/segment-profitability/exceptions${qs({
      page: params.page,
      per_page: params.per_page,
      from: params.from,
      to: params.to,
      segment_id: params.segment_id ?? undefined,
    })}`,
  )
}
