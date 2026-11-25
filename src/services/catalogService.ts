import { ApiClientError, parseOperamApiResponse } from '@/lib/apiClient'
import { notifyUnauthorized } from '@/services/sessionSecurity'
import type {
  CatalogService,
  CatalogInstance,
  Issuer,
  PriceList,
  PriceListItem,
  Promotion,
  PromotionMode,
  PromotionScope,
  ServiceType,
  ServiceUnit,
  InstanceStatus,
  DiscountType,
  BillingFrequency,
} from '@/types/catalog'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

export class CatalogApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'CatalogApiError'
    this.status = status
  }
}

type TokenGetter = () => string | null
let getAuthToken: TokenGetter = () => null

export function configureCatalogAuth(tokenGetter: TokenGetter): void {
  getAuthToken = tokenGetter
}

export type SegmentOption = {
  id: number
  name: string
  /** Días de crédito por defecto del segmento (RF-1). */
  default_credit_days: number
  /** Tipo de cambio USD→MXN de referencia (4 decimales). */
  usd_exchange_rate: string
}
/** Alias histórico; preferir `Issuer` completo del catálogo. */
export type EmitterOption = Issuer

async function apiRequest<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
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
    throw new CatalogApiError('No se pudo conectar con la API.', 0)
  }

  try {
    return await parseOperamApiResponse<T>(response, {
      hadBearer,
      onUnauthorized: (had) => notifyUnauthorized(had),
    })
  } catch (error) {
    if (error instanceof ApiClientError) {
      throw new CatalogApiError(error.message, error.status)
    }
    throw error
  }
}

function asArray<T>(value: T[] | unknown): T[] {
  return Array.isArray(value) ? value : []
}

export type PaginatedResult<T> = {
  items: T[]
  total: number
  page: number
  per_page: number
  total_pages: number
}

export type EmitterSummary = {
  issuer_id: number
  issuer: string
  total: number
  operational: number
  maintenance: number
  suspended: number
}

export type InstancesPaginatedResult = PaginatedResult<CatalogInstance> & {
  by_emitter: EmitterSummary[]
}

function toQuery(params: Record<string, string | number | undefined | null>): string {
  const qs = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    qs.set(key, String(value))
  }
  const s = qs.toString()
  return s ? `?${s}` : ''
}

function normalizePaginated<T>(raw: unknown): PaginatedResult<T> {
  if (Array.isArray(raw)) {
    return {
      items: raw as T[],
      total: raw.length,
      page: 1,
      per_page: raw.length || 25,
      total_pages: 1,
    }
  }
  const obj = raw as Partial<PaginatedResult<T>>
  return {
    items: asArray(obj.items),
    total: Number(obj.total ?? 0),
    page: Number(obj.page ?? 1),
    per_page: Number(obj.per_page ?? 25),
    total_pages: Number(obj.total_pages ?? 1),
  }
}

/**
 * Normaliza filas de catálogo para que `segment_name` siempre exista.
 * Acepta el alias legado `segment` por si alguna respuesta antigua no trae el JOIN.
 */
function mapCatalogSegment<T extends { segment_name: string; segment_id: number }>(
  raw: unknown,
): T {
  const row = (
    raw && typeof raw === 'object' ? { ...(raw as Record<string, unknown>) } : {}
  ) as Record<string, unknown>
  const legacyName = row.segment
  delete row.segment
  const segmentId = Number(row.segment_id)
  row.segment_name = String(row.segment_name ?? legacyName ?? '')
  row.segment_id = Number.isFinite(segmentId) ? segmentId : 0
  return row as T
}

/** Promoción sin segmento (null) aplica a todos los segmentos. */
function mapPromotionSegment(raw: unknown): Promotion {
  const row = (
    raw && typeof raw === 'object' ? { ...(raw as Record<string, unknown>) } : {}
  ) as Record<string, unknown>
  const legacyName = row.segment
  delete row.segment
  const name = String(row.segment_name ?? legacyName ?? '')
  const segmentId = Number(row.segment_id)
  row.segment_name = name === '' ? null : name
  row.segment_id = Number.isFinite(segmentId) && segmentId > 0 ? segmentId : null

  const promoMode: PromotionMode =
    String(row.promo_mode ?? 'price_discount') === 'extra_units'
      ? 'extra_units'
      : 'price_discount'
  row.promo_mode = promoMode

  const serviceId = Number(row.service_id)
  row.service_id = Number.isFinite(serviceId) && serviceId > 0 ? serviceId : null
  row.service_name =
    row.service_name != null && row.service_name !== '' ? String(row.service_name) : null
  row.service_code =
    row.service_code != null && row.service_code !== '' ? String(row.service_code) : null

  const minQty = Number(row.min_quantity)
  row.min_quantity = Number.isFinite(minQty) && minQty > 0 ? minQty : null
  row.extra_units_pct =
    row.extra_units_pct != null && row.extra_units_pct !== ''
      ? String(row.extra_units_pct)
      : null
  row.is_active = Boolean(Number(row.is_active ?? 0))
  row.visible_in_portal =
    row.visible_in_portal != null ? Boolean(Number(row.visible_in_portal)) : true

  const rawScopes = Array.isArray(row.scopes) ? row.scopes : []
  row.scopes = rawScopes
    .filter((s): s is Record<string, unknown> => Boolean(s && typeof s === 'object'))
    .map(
      (s): PromotionScope => ({
        id: s.id != null ? Number(s.id) : undefined,
        segment_id:
          s.segment_id != null && Number(s.segment_id) > 0 ? Number(s.segment_id) : null,
        segment_name:
          s.segment_name != null && s.segment_name !== '' ? String(s.segment_name) : null,
        client_id:
          s.client_id != null && Number(s.client_id) > 0 ? Number(s.client_id) : null,
        client_name:
          s.client_name != null && s.client_name !== '' ? String(s.client_name) : null,
      }),
    )

  if (typeof row.benefit_label !== 'string' || row.benefit_label.trim() === '') {
    if (promoMode === 'extra_units') {
      const pct = String(row.extra_units_pct ?? '0').replace(/\.00$/, '')
      const min = Number(row.min_quantity ?? 0).toLocaleString('es-MX')
      row.benefit_label = `+${pct}% extra desde ${min} u.`
    } else if (row.discount_type === 'percentage') {
      row.benefit_label = `${String(row.discount_value ?? '0').replace(/\.00$/, '')}% descuento`
    } else if (row.discount_type === 'free_months') {
      row.benefit_label = `${Number(row.discount_value ?? 0)} mes(es) gratis`
    } else {
      row.benefit_label = `$${String(row.discount_value ?? '0.00')} fijo`
    }
  }

  return row as Promotion
}

/* ── Lecturas ─────────────────────────────────────────────── */

export type ServicesQueryParams = {
  page?: number
  per_page?: number
  search?: string
  segment?: string
  service_type?: string
  status?: string
}

export async function fetchServices(
  params: ServicesQueryParams = {},
): Promise<PaginatedResult<CatalogService>> {
  const raw = await apiRequest<unknown>(
    `services${toQuery({
      page: params.page,
      per_page: params.per_page,
      search: params.search,
      segment: params.segment,
      service_type: params.service_type,
      status: params.status,
    })}`,
  )
  const page = normalizePaginated<CatalogService>(raw)
  return {
    ...page,
    items: page.items.map((row) => mapCatalogSegment<CatalogService>(row)),
  }
}

export async function fetchPriceLists(): Promise<PriceList[]> {
  const raw = await apiRequest<unknown>('price-lists')
  return asArray(raw).map((row) => mapCatalogSegment<PriceList>(row))
}

export async function fetchPromotions(): Promise<Promotion[]> {
  const raw = await apiRequest<unknown>('promotions')
  return asArray(raw).map((row) => mapPromotionSegment(row))
}

export type InstancesQueryParams = {
  page?: number
  per_page?: number
  search?: string
  issuer_id?: number | null
  segment?: string
  status?: string
}

export async function fetchInstances(
  params: InstancesQueryParams = {},
): Promise<InstancesPaginatedResult> {
  const raw = await apiRequest<unknown>(
    `instances${toQuery({
      page: params.page,
      per_page: params.per_page,
      search: params.search,
      issuer_id: params.issuer_id ?? undefined,
      segment: params.segment,
      status: params.status,
    })}`,
  )
  const base = normalizePaginated<CatalogInstance>(raw)
  const byEmitter = Array.isArray((raw as { by_emitter?: EmitterSummary[] })?.by_emitter)
    ? (raw as { by_emitter: EmitterSummary[] }).by_emitter
    : []
  return {
    ...base,
    items: base.items.map((row) => mapCatalogSegment<CatalogInstance>(row)),
    by_emitter: byEmitter,
  }
}

function mapSegmentOption(raw: unknown): SegmentOption {
  const row = (raw ?? {}) as Record<string, unknown>
  const fxRaw = String(row.usd_exchange_rate ?? '19.5000')
  const fxNum = Number(fxRaw)
  const fx = Number.isFinite(fxNum) ? fxNum.toFixed(4) : '19.5000'
  return {
    id: Number(row.id),
    name: String(row.name ?? ''),
    default_credit_days: Math.max(0, Number(row.default_credit_days ?? 0)),
    usd_exchange_rate: fx,
  }
}

export async function fetchSegments(): Promise<SegmentOption[]> {
  return asArray(await apiRequest<unknown>('segments')).map(mapSegmentOption)
}

/** Actualiza parámetros comerciales RF-1 de un segmento. */
export async function updateSegmentCommercialParams(
  id: number,
  input: { default_credit_days?: number; usd_exchange_rate?: string },
): Promise<SegmentOption> {
  const raw = await apiRequest<unknown>(`segments/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
  return mapSegmentOption(raw)
}

/** @deprecated Preferir updateSegmentCommercialParams. */
export async function updateSegmentCreditDays(
  id: number,
  diasCreditoDefault: number,
): Promise<SegmentOption> {
  return updateSegmentCommercialParams(id, {
    default_credit_days: diasCreditoDefault,
  })
}

/** Lista plana para selects (Instancias, Catálogo). */
function coerceIssuer(raw: unknown): Issuer | null {
  if (!raw || typeof raw !== 'object') return null
  const row = raw as Record<string, unknown>
  const id = Number(row.id)
  if (!Number.isFinite(id) || id <= 0) return null
  const secretRef =
    row.secret_ref != null && row.secret_ref !== '' ? String(row.secret_ref) : null
  const merchantRef =
    row.integropay_merchant_token_ref != null && row.integropay_merchant_token_ref !== ''
      ? String(row.integropay_merchant_token_ref)
      : null
  const deviceRef =
    row.integropay_device_token_ref != null && row.integropay_device_token_ref !== ''
      ? String(row.integropay_device_token_ref)
      : null

  return {
    id,
    name: String(row.name ?? ''),
    rfc: String(row.rfc ?? ''),
    api_url: row.api_url != null && row.api_url !== '' ? String(row.api_url) : null,
    has_erp_secret:
      row.has_erp_secret != null
        ? Boolean(row.has_erp_secret)
        : Boolean(secretRef && secretRef !== '••••••••'),
    has_merchant_token:
      row.has_merchant_token != null
        ? Boolean(row.has_merchant_token)
        : Boolean(merchantRef && merchantRef !== '••••••••'),
    has_device_token:
      row.has_device_token != null
        ? Boolean(row.has_device_token)
        : Boolean(deviceRef && deviceRef !== '••••••••'),
    secret_ref: secretRef,
    integropay_merchant_token_ref: merchantRef,
    integropay_device_token_ref: deviceRef,
    // MySQL tinyint 0/1 — nunca comparar === true estricto sin coerción.
    is_active: Boolean(Number(row.is_active)),
    linked_instances_count:
      row.linked_instances_count != null ? Number(row.linked_instances_count) : undefined,
  }
}

function coerceIssuerList(raw: unknown): Issuer[] {
  return asArray(raw)
    .map(coerceIssuer)
    .filter((row): row is Issuer => row !== null)
}

/** Lista plana para selects (Instancias, Catálogo). Misma fuente MySQL `emisor`. */
export async function fetchEmitters(): Promise<Issuer[]> {
  return coerceIssuerList(await apiRequest<unknown>('issuers'))
}

export async function fetchIssuers(): Promise<Issuer[]> {
  return fetchEmitters()
}

/* ── Emisores (RF-1) ──────────────────────────────────────── */

export type IssuersQueryParams = {
  page?: number
  per_page?: number
  search?: string
  status?: string
}

export async function fetchIssuersPaginated(
  params: IssuersQueryParams = {},
): Promise<PaginatedResult<Issuer>> {
  const raw = await apiRequest<unknown>(
    `issuers${toQuery({
      page: params.page ?? 1,
      per_page: params.per_page ?? 25,
      search: params.search,
      status: params.status,
    })}`,
  )
  // Si el backend devolvió lista plana por error, normalizar a paginado.
  if (Array.isArray(raw)) {
    const items = coerceIssuerList(raw)
    return {
      items,
      total: items.length,
      page: 1,
      per_page: items.length || 25,
      total_pages: 1,
    }
  }
  const base = normalizePaginated<Issuer>(raw)
  return {
    ...base,
    items: coerceIssuerList(base.items),
  }
}

export type IssuerWriteInput = {
  name: string
  rfc: string
  api_url?: string | null
  secret_ref?: string | null
  integropay_merchant_token_ref?: string | null
  integropay_device_token_ref?: string | null
  is_active?: boolean
}

export async function createIssuer(input: IssuerWriteInput): Promise<Issuer> {
  return apiRequest<Issuer>('issuers', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export async function updateIssuer(
  id: number,
  input: Partial<IssuerWriteInput>,
): Promise<Issuer> {
  return apiRequest<Issuer>(`issuers/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export async function toggleIssuerActive(
  id: number,
  isActive: boolean,
): Promise<Issuer> {
  return updateIssuer(id, { is_active: isActive })
}

/* ── Servicios ────────────────────────────────────────────── */

export type ServiceWriteInput = {
  segment_id: number
  code: string
  name: string
  description?: string | null
  service_type: ServiceType
  unit_of_measure: ServiceUnit
  allow_package_conversion?: boolean
  package_service_id?: number | null
  package_factor?: number
  is_active?: boolean
  default_issuer_id?: number | null
}

export async function createService(input: ServiceWriteInput): Promise<void> {
  await apiRequest('services', { method: 'POST', body: JSON.stringify(input) })
}

export async function updateService(
  id: number,
  input: Partial<ServiceWriteInput>,
): Promise<void> {
  await apiRequest(`services/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export async function toggleServiceActive(
  id: number,
  isActive: boolean,
): Promise<void> {
  await updateService(id, { is_active: isActive })
}

/* ── Listas de precios ────────────────────────────────────── */

export type PriceListWriteInput = {
  segment_id: number
  name: string
  currency: 'MXN' | 'USD'
  is_default?: boolean
  valid_from: string
  valid_until?: string | null
  is_active?: boolean
}

export async function createPriceList(input: PriceListWriteInput): Promise<void> {
  await apiRequest('price-lists', { method: 'POST', body: JSON.stringify(input) })
}

export async function updatePriceList(
  id: number,
  input: Partial<PriceListWriteInput>,
): Promise<void> {
  await apiRequest(`price-lists/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export type PriceListItemWriteInput = {
  price_list_id: number
  service_id: number
  frequency: BillingFrequency
  min_quantity?: number
  max_quantity?: number | null
  unit_price: string
}

export async function createPriceListItem(
  input: PriceListItemWriteInput,
): Promise<void> {
  await apiRequest('price-list-items', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export async function updatePriceListItem(
  id: number,
  input: Partial<Pick<PriceListItem, 'unit_price' | 'frequency' | 'min_quantity' | 'max_quantity'>>,
): Promise<void> {
  await apiRequest(`price-list-items/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export async function deletePriceListItem(id: number): Promise<void> {
  await apiRequest(`price-list-items/${id}`, { method: 'DELETE' })
}

/**
 * Quita un producto de la lista (todas las frecuencias/escalas en MySQL).
 * Los contratos activos conservan el precio ya pactado.
 */
export async function removeProductFromPriceList(input: {
  price_list_id: number
  service_id: number
}): Promise<void> {
  await apiRequest('price-list-items', {
    method: 'DELETE',
    body: JSON.stringify(input),
  })
}

/** Guarda varios precios en secuencia (matriz mensual/anual). */
export async function savePriceEdits(
  edits: Array<{ id: number; unit_price: string }>,
): Promise<void> {
  for (const edit of edits) {
    await updatePriceListItem(edit.id, { unit_price: edit.unit_price })
  }
}

/** Plan de sincronización RF-2: creates / updates / deletes de escalones. */
export type VolumeMatrixSavePlan = {
  updates: Array<{
    id: number
    unit_price?: string
    min_quantity?: number
    max_quantity?: number | null
  }>
  creates: PriceListItemWriteInput[]
  deletes: number[]
}

/** Aplica el plan de escalones en orden: borrar → actualizar → crear. */
export async function saveVolumeMatrix(plan: VolumeMatrixSavePlan): Promise<void> {
  for (const id of plan.deletes) {
    await deletePriceListItem(id)
  }
  for (const edit of plan.updates) {
    const { id, ...patch } = edit
    await updatePriceListItem(id, patch)
  }
  for (const row of plan.creates) {
    await createPriceListItem(row)
  }
}

/* ── Promociones ──────────────────────────────────────────── */

export type PromotionWriteInput = {
  code: string
  name: string
  promo_mode?: PromotionMode
  segment_id?: number | null
  service_id?: number | null
  min_quantity?: number | null
  extra_units_pct?: string | null
  discount_type?: DiscountType
  discount_value?: string
  applies_to?: 'recurring' | 'single' | 'both'
  valid_from: string
  valid_until?: string | null
  max_uses?: number | null
  current_uses?: number
  is_active?: boolean
  visible_in_portal?: boolean
  scopes?: Array<{ segment_id?: number | null; client_id?: number | null }>
  segment_ids?: number[]
  client_ids?: number[]
}

export async function createPromotion(input: PromotionWriteInput): Promise<void> {
  await apiRequest('promotions', { method: 'POST', body: JSON.stringify(input) })
}

export async function updatePromotion(
  id: number,
  input: Partial<PromotionWriteInput>,
): Promise<void> {
  await apiRequest(`promotions/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export async function togglePromotionActive(
  id: number,
  isActive: boolean,
): Promise<void> {
  await apiRequest(`promotions/${id}`, {
    method: 'PATCH',
    body: JSON.stringify({ is_active: isActive }),
  })
}

/* ── Instancias ───────────────────────────────────────────── */

export type InstanceWriteInput = {
  code: string
  name: string
  issuer_id: number
  segment_id: number
  access_url?: string | null
  server_ip?: string | null
  status: InstanceStatus
}

export async function createInstance(input: InstanceWriteInput): Promise<void> {
  await apiRequest('instances', { method: 'POST', body: JSON.stringify(input) })
}

export async function updateInstance(
  id: number,
  input: Partial<InstanceWriteInput>,
): Promise<void> {
  await apiRequest(`instances/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

/** Rota el Bearer de instancia; el plain solo se muestra una vez (RNF-8). */
export async function rotateInstanceApiToken(id: number): Promise<{
  id: number
  api_token: string
  api_token_last4: string
  warning?: string
}> {
  return apiRequest(`instances/${id}/rotate-token`, { method: 'POST', body: '{}' })
}
