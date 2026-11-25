import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import { notifyUnauthorized } from '@/services/sessionSecurity'
import type {
  ContractCreateInput,
  ContractDetail,
  ContractLifecycleInput,
  ContractLine,
  ContractListItem,
  ContractMovement,
  ContractMovementReversalInput,
  ContractSummary,
} from '@/types/contract'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getAuthToken: TokenGetter = () => null

/** Registra el lector de JWT (mismo patrón que clientes / catálogo). */
export function configureContractsAuth(tokenGetter: TokenGetter): void {
  getAuthToken = tokenGetter
}

export class ContractsApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'ContractsApiError'
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
    throw new ContractsApiError('No se pudo conectar con la API.', 0)
  }

  try {
    return await parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized: (had) => notifyUnauthorized(had),
    })
  } catch (error) {
    if (error instanceof ApiClientError) {
      throw new ContractsApiError(error.message, error.status)
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

export type ContractsQueryParams = {
  page?: number
  per_page?: number
  search?: string
  segment_id?: number
  issuer_id?: number
  billing_frequency?: string
  status?: string
}

export type ContractsPaginatedResult = {
  items: ContractListItem[]
  total: number
  page: number
  per_page: number
  total_pages: number
  summary: ContractSummary
}

const EMPTY_SUMMARY: ContractSummary = {
  total_contracts: 0,
  active_contracts: 0,
  total_mrr_mxn: '0.00',
  suspended_contracts: 0,
}

function mapContract(raw: Partial<ContractListItem>): ContractListItem {
  const frequency = String(raw.billing_frequency ?? raw.frequency ?? 'monthly') as ContractListItem['frequency']
  return {
    id: Number(raw.id ?? 0),
    folio: String(raw.folio ?? ''),
    client_id: Number(raw.client_id ?? 0),
    client_name: String(raw.client_name ?? ''),
    client_legal_name: String(raw.client_legal_name ?? ''),
    client_rfc: String(raw.client_rfc ?? ''),
    segment_id: Number(raw.segment_id ?? 0),
    segment_name: String(raw.segment_name ?? ''),
    issuer_id: Number(raw.issuer_id ?? 0),
    issuer_name: String(raw.issuer_name ?? ''),
    instance_id: raw.instance_id != null ? Number(raw.instance_id) : null,
    instance_name: raw.instance_name ?? null,
    price_list_id: raw.price_list_id != null ? Number(raw.price_list_id) : null,
    price_list_name: raw.price_list_name ?? null,
    promotion_id: raw.promotion_id != null ? Number(raw.promotion_id) : null,
    promotion_code: raw.promotion_code ?? null,
    billing_frequency: frequency,
    frequency,
    anniversary_date: String(raw.anniversary_date ?? ''),
    currency: String(raw.currency ?? 'MXN'),
    exchange_rate: String(raw.exchange_rate ?? '1.0000'),
    credit_days: Number(raw.credit_days ?? 0),
    stamp_on_issue: Boolean(raw.stamp_on_issue),
    status: (raw.status ?? 'active') as ContractListItem['status'],
    mrr_mxn: String(raw.mrr_mxn ?? '0.00'),
    next_billing_date: raw.next_billing_date ?? raw.next_cutoff_date ?? null,
    next_cutoff_date: raw.next_cutoff_date ?? raw.next_billing_date ?? null,
    origin_contract_id: raw.origin_contract_id != null ? Number(raw.origin_contract_id) : null,
    termination_reason: raw.termination_reason ?? null,
    notes: raw.notes ?? null,
    lines_count: Number(raw.lines_count ?? 0),
    created_by: raw.created_by != null ? Number(raw.created_by) : null,
    created_at: raw.created_at ?? null,
    updated_at: raw.updated_at ?? null,
    contract_is_current:
      raw.contract_is_current !== undefined
        ? Boolean(raw.contract_is_current)
        : Number(raw.contract_overdue_balance ?? 0) <= 0 &&
          Number(raw.contract_days_overdue ?? 0) <= 0,
    contract_overdue_balance: String(raw.contract_overdue_balance ?? '0.00'),
    contract_days_overdue: Number(raw.contract_days_overdue ?? 0),
  }
}

function mapLine(raw: Partial<ContractLine>): ContractLine {
  return {
    id: Number(raw.id ?? 0),
    contract_id: Number(raw.contract_id ?? 0),
    service_id: Number(raw.service_id ?? 0),
    description: String(raw.description ?? ''),
    service_type: String(raw.service_type ?? 'recurring'),
    billing_frequency: String(raw.billing_frequency ?? 'monthly'),
    quantity: Number(raw.quantity ?? 0),
    unit_price: String(raw.unit_price ?? '0.00'),
    discount_amount: String(raw.discount_amount ?? '0.00'),
    subtotal: String(raw.subtotal ?? '0.00'),
    mrr_mxn: String(raw.mrr_mxn ?? '0.00'),
    is_package_converted: Boolean(raw.is_package_converted),
    active: Boolean(raw.active),
    created_at: raw.created_at,
    updated_at: raw.updated_at,
  }
}

function mapMovement(raw: Partial<ContractMovement>): ContractMovement {
  return {
    id: Number(raw.id ?? 0),
    contract_id: Number(raw.contract_id ?? 0),
    contract_line_id: raw.contract_line_id != null ? Number(raw.contract_line_id) : null,
    service_id: raw.service_id != null ? Number(raw.service_id) : null,
    movement_type: String(raw.movement_type ?? ''),
    mrr_category: String(raw.mrr_category ?? ''),
    effective_date: String(raw.effective_date ?? ''),
    quantity_before: String(raw.quantity_before ?? '0.00'),
    quantity_after: String(raw.quantity_after ?? '0.00'),
    mrr_before: String(raw.mrr_before ?? '0.00'),
    mrr_after: String(raw.mrr_after ?? '0.00'),
    mrr_delta_mxn: String(raw.mrr_delta_mxn ?? '0.00'),
    exchange_rate: String(raw.exchange_rate ?? '1.000000'),
    user_id: raw.user_id != null ? Number(raw.user_id) : null,
    user_name: raw.user_name ?? null,
    request_id: raw.request_id != null ? Number(raw.request_id) : null,
    request_folio: raw.request_folio != null ? String(raw.request_folio) : null,
    reason: raw.reason ?? null,
    has_exception: Boolean(raw.has_exception),
    reversed_movement_id:
      raw.reversed_movement_id != null ? Number(raw.reversed_movement_id) : null,
    is_reversed: Boolean(raw.is_reversed),
    created_at: String(raw.created_at ?? ''),
  }
}

function mapContractDetail(
  raw: Partial<ContractListItem> & {
    lines?: Partial<ContractLine>[]
    movements?: Partial<ContractMovement>[]
    active_lines_count?: number
    active_recurring_count?: number
    active_one_time_count?: number
  },
): ContractDetail {
  const base = mapContract(raw)
  const lines = Array.isArray(raw.lines) ? raw.lines.map(mapLine) : []
  const movements = Array.isArray(raw.movements) ? raw.movements.map(mapMovement) : []

  return {
    ...base,
    lines,
    movements,
    active_lines_count: Number(raw.active_lines_count ?? lines.filter((l) => l.active).length),
    active_recurring_count: Number(
      raw.active_recurring_count ??
        lines.filter((l) => l.active && l.service_type === 'recurring').length,
    ),
    active_one_time_count: Number(
      raw.active_one_time_count ??
        lines.filter((l) => l.active && l.service_type !== 'recurring').length,
    ),
  }
}

export async function fetchContractsPaginated(
  params: ContractsQueryParams = {},
): Promise<ContractsPaginatedResult> {
  const raw = await apiRequest<Partial<ContractsPaginatedResult>>(
    `contracts${toQuery({
      page: params.page ?? 1,
      per_page: params.per_page ?? 25,
      search: params.search,
      segment_id: params.segment_id,
      issuer_id: params.issuer_id,
      billing_frequency: params.billing_frequency,
      status: params.status,
    })}`,
  )

  const items = Array.isArray(raw.items) ? raw.items.map(mapContract) : []
  const summary = raw.summary ?? EMPTY_SUMMARY

  return {
    items,
    total: Number(raw.total ?? items.length),
    page: Number(raw.page ?? params.page ?? 1),
    per_page: Number(raw.per_page ?? params.per_page ?? 25),
    total_pages: Number(raw.total_pages ?? 1),
    summary: {
      total_contracts: Number(summary.total_contracts ?? 0),
      active_contracts: Number(summary.active_contracts ?? 0),
      total_mrr_mxn: String(summary.total_mrr_mxn ?? '0.00'),
      suspended_contracts: Number(summary.suspended_contracts ?? 0),
    },
  }
}

export async function createContract(input: ContractCreateInput): Promise<ContractListItem> {
  return mapContract(
    await apiRequest<Partial<ContractListItem>>('contracts', {
      method: 'POST',
      body: JSON.stringify(input),
    }),
  )
}

export async function fetchContractDetail(id: number): Promise<ContractDetail> {
  return mapContractDetail(
    await apiRequest(`contracts/${id}`),
  )
}

export async function runContractLifecycle(
  id: number,
  input: ContractLifecycleInput,
): Promise<ContractDetail> {
  return mapContractDetail(
    await apiRequest(`contracts/${id}/lifecycle`, {
      method: 'POST',
      body: JSON.stringify(input),
    }),
  )
}

/** POST /api/contracts/{id}/movements/{movementId}/reverse — RNF-3. */
export async function reverseContractMovement(
  contractId: number,
  movementId: number,
  input: ContractMovementReversalInput,
): Promise<ContractDetail> {
  return mapContractDetail(
    await apiRequest(`contracts/${contractId}/movements/${movementId}/reverse`, {
      method: 'POST',
      body: JSON.stringify(input),
    }),
  )
}
