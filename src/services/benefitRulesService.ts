import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import { notifyUnauthorized } from '@/services/sessionSecurity'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getAuthToken: TokenGetter = () => null

/** Registra el lector de JWT para reglas de beneficio (mismo patrón que catálogo). */
export function configureBenefitRulesAuth(tokenGetter: TokenGetter): void {
  getAuthToken = tokenGetter
}

export class BenefitRulesApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'BenefitRulesApiError'
    this.status = status
  }
}

export type BenefitResetPeriod = 'monthly' | 'anniversary'

export type BenefitRule = {
  id: number
  source_service_id: number
  source_service_code: string
  source_service_name: string
  benefit_service_id: number
  benefit_service_code: string
  benefit_service_name: string
  units_per_unit: number
  reset_period: BenefitResetPeriod
  is_cumulative: boolean
  active: boolean
  created_at: string
  updated_at: string
}

export type BenefitRuleWriteInput = {
  source_service_id: number
  benefit_service_id: number
  units_per_unit: number
  reset_period: BenefitResetPeriod
  is_cumulative: boolean
  active?: boolean
}

export type BenefitPreviewLine = {
  service_id: number
  quantity: number
  package_factor?: number
}

export type BenefitPreviewGrant = {
  benefit_service_id: number
  benefit_service_name: string
  units: number
  units_per_unit: number
  equivalent_units: number
  is_cumulative: boolean
  reset_period: BenefitResetPeriod
}

export type BenefitPreviewResponse = {
  grants: BenefitPreviewGrant[]
  message: string
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
    throw new BenefitRulesApiError('No se pudo conectar con la API.', 0)
  }

  try {
    return await parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized: (had) => notifyUnauthorized(had),
    })
  } catch (error) {
    if (error instanceof ApiClientError) {
      throw new BenefitRulesApiError(error.message, error.status)
    }
    throw error
  }
}

function mapBenefitRule(raw: unknown): BenefitRule {
  const row = (raw ?? {}) as Record<string, unknown>
  const reset = String(row.reset_period ?? 'monthly') === 'anniversary'
    ? 'anniversary'
    : 'monthly'
  return {
    id: Number(row.id ?? 0),
    source_service_id: Number(row.source_service_id ?? 0),
    source_service_code: String(row.source_service_code ?? ''),
    source_service_name: String(row.source_service_name ?? ''),
    benefit_service_id: Number(row.benefit_service_id ?? 0),
    benefit_service_code: String(row.benefit_service_code ?? ''),
    benefit_service_name: String(row.benefit_service_name ?? ''),
    units_per_unit: Number(row.units_per_unit ?? 0),
    reset_period: reset,
    is_cumulative: Boolean(Number(row.is_cumulative ?? 0)),
    active: Boolean(Number(row.active ?? 0)),
    created_at: String(row.created_at ?? ''),
    updated_at: String(row.updated_at ?? ''),
  }
}

export async function fetchBenefitRules(
  params: { active?: boolean | 'all' } = {},
): Promise<BenefitRule[]> {
  const qs = new URLSearchParams()
  if (params.active !== undefined && params.active !== 'all') {
    qs.set('active', params.active ? '1' : '0')
  }
  const suffix = qs.toString() ? `?${qs.toString()}` : ''
  const raw = await apiRequest<unknown>(`benefit-rules${suffix}`)
  return Array.isArray(raw) ? raw.map(mapBenefitRule) : []
}

export async function createBenefitRule(input: BenefitRuleWriteInput): Promise<BenefitRule> {
  const raw = await apiRequest<unknown>('benefit-rules', {
    method: 'POST',
    body: JSON.stringify(input),
  })
  return mapBenefitRule(raw)
}

export async function updateBenefitRule(
  id: number,
  input: BenefitRuleWriteInput,
): Promise<BenefitRule> {
  const raw = await apiRequest<unknown>(`benefit-rules/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
  return mapBenefitRule(raw)
}

export async function patchBenefitRule(
  id: number,
  input: Partial<BenefitRuleWriteInput>,
): Promise<BenefitRule> {
  const raw = await apiRequest<unknown>(`benefit-rules/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
  return mapBenefitRule(raw)
}

/** Vista previa sin persistir de beneficios incluidos para las líneas de contrato. */
export async function previewBenefitRules(payload: {
  lines: BenefitPreviewLine[]
}): Promise<BenefitPreviewResponse> {
  const raw = await apiRequest<Partial<BenefitPreviewResponse>>('benefit-rules/preview', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const grants = Array.isArray(raw?.grants) ? raw.grants : []
  return {
    grants,
    message: typeof raw?.message === 'string' ? raw.message : '',
  }
}
