import { createApiFetch } from '@/lib/apiClient'
import type {
  ExternalService,
  ExternalServiceKey,
  ExternalServiceTestResult,
  ExternalServiceWriteInput,
  IncomingEventsPage,
  JobName,
  JobRun,
  JobRunsPage,
  OutboxMessage,
  OutboxPage,
  OutboxProcessResult,
} from '@/types/externalServices'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getToken: TokenGetter = () => null

/** Registra el lector de JWT (lo invoca `AuthContext`). */
export function configureExternalServicesAuth(tokenGetter: TokenGetter): void {
  getToken = tokenGetter
}

const apiFetch = createApiFetch({
  getBaseUrl: () => API_BASE_URL,
  getToken: () => getToken(),
})

export const EXTERNAL_SERVICES_QUERY_KEY = ['external-services'] as const
export const OUTBOX_QUERY_KEY = ['outbox'] as const
export const INCOMING_EVENTS_QUERY_KEY = ['incoming-events'] as const
export const JOB_RUNS_QUERY_KEY = ['job-runs'] as const

function qs(params: Record<string, string | number | undefined | null>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    search.set(key, String(value))
  }
  const raw = search.toString()
  return raw ? `?${raw}` : ''
}

/** GET /api/external-services */
export async function fetchExternalServices(): Promise<ExternalService[]> {
  const data = await apiFetch<{ items: ExternalService[] }>('/external-services')
  return data.items ?? []
}

/** PUT /api/external-services/{serviceKey} */
export function updateExternalService(
  serviceKey: ExternalServiceKey,
  input: ExternalServiceWriteInput,
): Promise<ExternalService> {
  return apiFetch(`/external-services/${serviceKey}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

/** POST /api/external-services/{serviceKey}/test */
export function testExternalService(
  serviceKey: ExternalServiceKey,
): Promise<ExternalServiceTestResult> {
  return apiFetch(`/external-services/${serviceKey}/test`, { method: 'POST' })
}

/** GET /api/outbox (paginado servidor + KPIs) */
export function fetchOutbox(params: {
  page?: number
  per_page?: number
  status?: string
  destination?: string
  search?: string
}): Promise<OutboxPage> {
  return apiFetch(
    `/outbox${qs({
      page: params.page,
      per_page: params.per_page,
      status: params.status,
      destination: params.destination,
      search: params.search,
    })}`,
  )
}

/** POST /api/outbox/{id}/retry */
export function retryOutboxMessage(id: number): Promise<OutboxMessage> {
  return apiFetch(`/outbox/${id}/retry`, { method: 'POST' })
}

/** POST /api/outbox/process-now */
export function processOutboxNow(): Promise<OutboxProcessResult> {
  return apiFetch('/outbox/process-now', { method: 'POST', body: JSON.stringify({}) })
}

/** GET /api/jobs/runs (historial paginado + última corrida por job) */
export function fetchJobRuns(params: {
  page?: number
  per_page?: number
  job_name?: string
  status?: string
}): Promise<JobRunsPage> {
  return apiFetch(
    `/jobs/runs${qs({
      page: params.page,
      per_page: params.per_page,
      job_name: params.job_name,
      status: params.status,
    })}`,
  )
}

/** POST /api/jobs/run/{jobName} — disparo manual desde la UI (trigger_source = manual_ui). */
export function runJobNow(jobName: JobName): Promise<JobRun> {
  return apiFetch(`/jobs/run/${jobName}`, { method: 'POST', body: JSON.stringify({}) })
}

/** GET /api/incoming-events (solo lectura) */
export function fetchIncomingEvents(params: {
  page?: number
  per_page?: number
  origin?: string
  processed?: string
  search?: string
}): Promise<IncomingEventsPage> {
  return apiFetch(
    `/incoming-events${qs({
      page: params.page,
      per_page: params.per_page,
      origin: params.origin,
      processed: params.processed,
      search: params.search,
    })}`,
  )
}
