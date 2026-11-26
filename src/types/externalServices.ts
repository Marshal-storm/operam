/** Servicios globales administrados en Configuración → Servicios externos (RNF-8). */
export type ExternalServiceKey =
  | 'smtp'
  | 'erp_master'
  | 'slack'
  | 'banxico'
  | 'jobs_outbox'
  | 'integropay'

export type ExternalServiceMode = 'sandbox' | 'production'

export type ExternalServiceTestStatus = 'ok' | 'error' | 'never'

/** Referencia al cofre de secretos: solo el nombre de variable + si existe en el entorno. */
export type ExternalServiceSecretRef = {
  field: string
  ref: string
  is_configured: boolean
}

export type ExternalService = {
  id: number
  service_key: ExternalServiceKey
  display_name: string
  enabled: boolean
  mode: ExternalServiceMode
  config: Record<string, string | number | null>
  secret_refs: ExternalServiceSecretRef[]
  last_tested_at: string | null
  last_test_status: ExternalServiceTestStatus
  last_test_message: string | null
  updated_by_name: string | null
  created_at: string | null
  updated_at: string | null
}

export type ExternalServiceWriteInput = {
  display_name: string
  enabled: boolean
  mode: ExternalServiceMode
  config: Record<string, string | number>
  secret_refs: Record<string, string>
}

export type ExternalServiceTestResult = {
  service: ExternalService
  test: {
    status: 'ok' | 'error'
    message: string
    sandbox: boolean
    details: Record<string, unknown>
  }
}

export type OutboxDestination =
  | 'erp_issuer'
  | 'erp_master'
  | 'payment_gateway'
  | 'smtp'
  | 'slack'

export type OutboxStatus = 'pending' | 'processing' | 'sent' | 'failed'

export type OutboxMessage = {
  id: number
  destination: OutboxDestination
  operation: string
  idempotency_key: string
  issuer_id: number | null
  segment_id: number | null
  entity_type: string | null
  entity_id: string | null
  payload: Record<string, unknown>
  status: OutboxStatus
  attempts: number
  max_attempts: number
  next_attempt_at: string | null
  sent_at: string | null
  response: Record<string, unknown> | null
  last_error: string | null
  slack_alerted_at: string | null
  created_at: string | null
  updated_at: string | null
}

export type OutboxKpis = {
  pending: number
  processing: number
  sent: number
  failed: number
  exhausted: number
}

export type OutboxPage = {
  items: OutboxMessage[]
  total: number
  page: number
  per_page: number
  total_pages: number
  kpis: OutboxKpis
}

export type OutboxProcessResult = {
  processed: number
  sent: number
  failed: number
  slack_alerts: number
  kpis: OutboxKpis
}

export type IncomingEventOrigin = 'erp_issuer' | 'integropay' | 'erp_master'

export type IncomingEvent = {
  id: number
  origin: IncomingEventOrigin
  external_id: string
  event_type: string
  payload: Record<string, unknown>
  processed: boolean
  processed_at: string | null
  error: string | null
  created_at: string | null
}

export type IncomingEventsPage = {
  items: IncomingEvent[]
  total: number
  page: number
  per_page: number
  total_pages: number
}

/** Jobs oficiales de Google Cloud (Pág. 24 del PDF). */
export type JobName =
  | 'daily_billing'
  | 'reset_included_balances'
  | 'daily_collections'
  | 'process_outbox'

export type JobTriggerSource = 'cloud_scheduler' | 'cli' | 'manual_ui'

export type JobRunStatus = 'running' | 'completed' | 'failed'

export type JobRun = {
  id: number
  job_name: JobName
  job_label: string
  trigger_source: JobTriggerSource
  status: JobRunStatus
  started_at: string
  finished_at: string | null
  duration_seconds: number | null
  processed_count: number
  summary: Record<string, unknown> | null
  error_message: string | null
  triggered_by_user_id: number | null
  triggered_by_name: string | null
}

export type JobRunsPage = {
  items: JobRun[]
  total: number
  page: number
  per_page: number
  total_pages: number
  latest: Record<JobName, JobRun | null>
  /** El secreto de Cloud Scheduler existe en el entorno (nunca se expone el valor). */
  secret_configured: boolean
}
