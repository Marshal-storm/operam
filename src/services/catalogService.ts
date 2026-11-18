import { notifyUnauthorized } from '@/services/sessionSecurity'
import type {
  CatalogService,
  CatalogInstance,
  Issuer,
  PriceList,
  PriceListItem,
  Promotion,
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
  nombre: string
  /** Días de crédito por defecto del segmento (RF-1). */
  dias_credito_default: number
  /** Tipo de cambio USD→MXN de referencia (4 decimales). */
  tipo_cambio_usd: string
}
/** Alias histórico; preferir `Issuer` completo del catálogo. */
export type EmitterOption = Issuer

async function apiRequest<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const token = getAuthToken()
  const hadBearer = Boolean(token)
  const response = await fetch(`${API_BASE_URL}/${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers ?? {}),
    },
  })

  let body: { success?: boolean; data?: T; message?: string } = {}
  try {
    body = (await response.json()) as typeof body
  } catch {
    /* sin JSON — p. ej. fatal PHP; no confundir con sesión revocada */
  }

  // Solo 401 con Bearer enviado = sesión revocada/expirada. Nunca 404/500/422.
  if (response.status === 401) {
    notifyUnauthorized(hadBearer)
  }

  if (!response.ok || body.success === false) {
    throw new CatalogApiError(
      body.message ?? `Error HTTP ${response.status}`,
      response.status,
    )
  }

  return (body.data ?? body) as T
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
  emisor_id: number
  emisor: string
  total: number
  operativas: number
  mantenimiento: number
  suspendidas: number
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

/* ── Lecturas ─────────────────────────────────────────────── */

export type ServicesQueryParams = {
  page?: number
  per_page?: number
  search?: string
  segmento?: string
  tipo?: string
  estado?: string
}

export async function fetchServices(
  params: ServicesQueryParams = {},
): Promise<PaginatedResult<CatalogService>> {
  const raw = await apiRequest<unknown>(
    `services${toQuery({
      page: params.page,
      per_page: params.per_page,
      search: params.search,
      segmento: params.segmento,
      tipo: params.tipo,
      estado: params.estado,
    })}`,
  )
  return normalizePaginated<CatalogService>(raw)
}

export async function fetchPriceLists(): Promise<PriceList[]> {
  return asArray(await apiRequest<PriceList[]>('price-lists'))
}

export async function fetchPromotions(): Promise<Promotion[]> {
  return asArray(await apiRequest<Promotion[]>('promotions'))
}

export type InstancesQueryParams = {
  page?: number
  per_page?: number
  search?: string
  emisor_id?: number | null
  segmento?: string
  estado?: string
}

export async function fetchInstances(
  params: InstancesQueryParams = {},
): Promise<InstancesPaginatedResult> {
  const raw = await apiRequest<unknown>(
    `instances${toQuery({
      page: params.page,
      per_page: params.per_page,
      search: params.search,
      emisor_id: params.emisor_id ?? undefined,
      segmento: params.segmento,
      estado: params.estado,
    })}`,
  )
  const base = normalizePaginated<CatalogInstance>(raw)
  const byEmitter = Array.isArray((raw as { by_emitter?: EmitterSummary[] })?.by_emitter)
    ? (raw as { by_emitter: EmitterSummary[] }).by_emitter
    : []
  return { ...base, by_emitter: byEmitter }
}

function mapSegmentOption(raw: unknown): SegmentOption {
  const row = (raw ?? {}) as Record<string, unknown>
  const fxRaw = String(row.tipo_cambio_usd ?? '19.5000')
  const fxNum = Number(fxRaw)
  const fx = Number.isFinite(fxNum) ? fxNum.toFixed(4) : '19.5000'
  return {
    id: Number(row.id),
    nombre: String(row.nombre ?? ''),
    dias_credito_default: Math.max(0, Number(row.dias_credito_default ?? 0)),
    tipo_cambio_usd: fx,
  }
}

export async function fetchSegments(): Promise<SegmentOption[]> {
  return asArray(await apiRequest<unknown>('segments')).map(mapSegmentOption)
}

/** Actualiza parámetros comerciales RF-1 de un segmento. */
export async function updateSegmentCommercialParams(
  id: number,
  input: { dias_credito_default?: number; tipo_cambio_usd?: string },
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
    dias_credito_default: diasCreditoDefault,
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
    nombre: String(row.nombre ?? ''),
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
    activo: Boolean(Number(row.activo)),
    instancias_vinculadas:
      row.instancias_vinculadas != null ? Number(row.instancias_vinculadas) : undefined,
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
  estado?: string
}

export async function fetchIssuersPaginated(
  params: IssuersQueryParams = {},
): Promise<PaginatedResult<Issuer>> {
  const raw = await apiRequest<unknown>(
    `issuers${toQuery({
      page: params.page ?? 1,
      per_page: params.per_page ?? 25,
      search: params.search,
      estado: params.estado,
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
  nombre: string
  rfc: string
  api_url?: string | null
  secret_ref?: string | null
  integropay_merchant_token_ref?: string | null
  integropay_device_token_ref?: string | null
  activo?: boolean
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
  activo: boolean,
): Promise<Issuer> {
  return updateIssuer(id, { activo })
}

/* ── Servicios ────────────────────────────────────────────── */

export type ServiceWriteInput = {
  segmento_id: number
  codigo: string
  nombre: string
  descripcion?: string | null
  tipo: ServiceType
  unidad_medida: ServiceUnit
  permite_conversion_paquete?: boolean
  servicio_paquete_id?: number | null
  factor_paquete?: number
  activo?: boolean
  emisor_default_id?: number | null
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
  activo: boolean,
): Promise<void> {
  await updateService(id, { activo })
}

/* ── Listas de precios ────────────────────────────────────── */

export type PriceListWriteInput = {
  segmento_id: number
  nombre: string
  moneda: 'MXN' | 'USD'
  es_default?: boolean
  vigente_desde: string
  vigente_hasta?: string | null
  activa?: boolean
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
  lista_precio_id: number
  servicio_id: number
  frecuencia: BillingFrequency
  cantidad_min?: number
  cantidad_max?: number | null
  precio_unitario: string
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
  input: Partial<Pick<PriceListItem, 'precio_unitario' | 'frecuencia' | 'cantidad_min' | 'cantidad_max'>>,
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
  lista_precio_id: number
  servicio_id: number
}): Promise<void> {
  await apiRequest('price-list-items', {
    method: 'DELETE',
    body: JSON.stringify(input),
  })
}

/** Guarda varios precios en secuencia (matriz mensual/anual). */
export async function savePriceEdits(
  edits: Array<{ id: number; precio_unitario: string }>,
): Promise<void> {
  for (const edit of edits) {
    await updatePriceListItem(edit.id, { precio_unitario: edit.precio_unitario })
  }
}

/** Plan de sincronización RF-2: creates / updates / deletes de escalones. */
export type VolumeMatrixSavePlan = {
  updates: Array<{
    id: number
    precio_unitario?: string
    cantidad_min?: number
    cantidad_max?: number | null
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
  codigo: string
  nombre: string
  segmento_id?: number | null
  tipo_descuento: DiscountType
  valor_descuento: string
  aplica_a?: 'recurrente' | 'unico' | 'ambos'
  vigente_desde: string
  vigente_hasta?: string | null
  usos_maximos?: number | null
  usos_actuales?: number
  activa?: boolean
}

export async function createPromotion(input: PromotionWriteInput): Promise<void> {
  await apiRequest('promotions', { method: 'POST', body: JSON.stringify(input) })
}

export async function updatePromotion(
  id: number,
  input: Partial<PromotionWriteInput>,
): Promise<void> {
  await apiRequest(`promotions/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export async function togglePromotionActive(
  id: number,
  activa: boolean,
): Promise<void> {
  await updatePromotion(id, { activa })
}

/* ── Instancias ───────────────────────────────────────────── */

export type InstanceWriteInput = {
  codigo: string
  nombre: string
  emisor_id: number
  segmento_id: number
  url_acceso?: string | null
  ip_servidor?: string | null
  estado: InstanceStatus
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
