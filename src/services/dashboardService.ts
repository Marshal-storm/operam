import { buildMrrBridgeSteps, MRR_DELTA_CATEGORY_ORDER } from '@/lib/mrrCalculator'
import { getDashboardSnapshot, getMovementsByCategory } from '@/services/mockDashboardData'
import { notifyUnauthorized } from '@/services/sessionSecurity'
import type {
  AgingBucket,
  DashboardKpis,
  DashboardSnapshot,
  MrrCategory,
  MrrMovement,
  ProductSegment,
} from '@/types/mrr'

/** Modo de fuente de datos del Dashboard. */
export type DataSourceMode = 'mock' | 'api'

export type DashboardQueryParams = {
  mode: DataSourceMode
  segment: ProductSegment
  /** Mes en formato `YYYY-MM`. */
  month: string
}

/** Respuesta esperada del backend `operam-servicios` para el dashboard. */
type ApiDashboardResponse = {
  periodLabel?: string
  kpis?: Partial<DashboardKpis> & { mrrMxn?: number }
  mrrInicio?: number
  deltasByCategory?: Record<string, number>
  aging?: AgingBucket[]
  recentMovements?: MrrMovement[]
  allMovements?: MrrMovement[]
  empty?: boolean
}

const API_BASE_URL =
  (import.meta.env.VITE_API_URL ?? import.meta.env.VITE_API_BASE_URL)?.replace(/\/$/, '') ??
  'http://localhost:8080/api'

type TokenGetter = () => string | null
type UnauthorizedHandler = () => void

let getAuthToken: TokenGetter = () => null
let onUnauthorized: UnauthorizedHandler = () => undefined

/** Inyecta el token JWT y el cierre de sesión ante HTTP 401. */
export function configureDashboardAuth(
  tokenGetter: TokenGetter,
  unauthorizedHandler: UnauthorizedHandler,
): void {
  getAuthToken = tokenGetter
  onUnauthorized = unauthorizedHandler
}

export class DashboardApiError extends Error {
  status?: number

  constructor(message: string, status?: number) {
    super(message)
    this.name = 'DashboardApiError'
    this.status = status
  }
}

export class DashboardEmptyError extends Error {
  constructor(message = 'Sin datos para el periodo seleccionado') {
    super(message)
    this.name = 'DashboardEmptyError'
  }
}

function buildPeriodLabel(month: string, mode: DataSourceMode): string {
  const [year, monthNumber] = month.split('-')
  const labels = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
  ]
  const monthLabel = labels[Number(monthNumber) - 1] ?? month
  const suffix = mode === 'mock' ? 'Datos de ejemplo' : 'API real'
  return `Cierre de ${monthLabel} ${year} · ${suffix}`
}

/**
 * Mapea el payload de API al snapshot de UI usando el motor puro del puente.
 */
export function mapApiDashboardToSnapshot(
  payload: ApiDashboardResponse,
  params: DashboardQueryParams,
): DashboardSnapshot {
  if (payload.empty) {
    throw new DashboardEmptyError()
  }

  const hasBridge =
    typeof payload.mrrInicio === 'number' && payload.deltasByCategory != null
  const hasKpis = payload.kpis != null && typeof payload.kpis.mrrMxn === 'number'
  const hasAging = Array.isArray(payload.aging) && payload.aging.length > 0
  const movements = payload.allMovements ?? payload.recentMovements ?? []

  if (!hasBridge && !hasKpis && !hasAging && movements.length === 0) {
    throw new DashboardEmptyError()
  }

  const deltas: Record<string, number> = {}
  for (const category of MRR_DELTA_CATEGORY_ORDER) {
    deltas[category] = payload.deltasByCategory?.[category] ?? 0
  }

  const bridge = buildMrrBridgeSteps(payload.mrrInicio ?? 0, deltas)
  const finStep = bridge.find((step) => step.category === 'fin')

  const kpis: DashboardKpis = {
    mrrMxn: payload.kpis?.mrrMxn ?? finStep?.accumulated ?? 0,
    mrrDeltaVsPrevPercent: payload.kpis?.mrrDeltaVsPrevPercent ?? '—',
    activeContracts: payload.kpis?.activeContracts ?? 0,
    newContracts: payload.kpis?.newContracts ?? 0,
    churnedContracts: payload.kpis?.churnedContracts ?? 0,
    nrr12mPercent: payload.kpis?.nrr12mPercent ?? '—',
    grrPercent: payload.kpis?.grrPercent ?? '—',
    overduePortfolioMxn: payload.kpis?.overduePortfolioMxn ?? 0,
    overdueOver60Count: payload.kpis?.overdueOver60Count ?? 0,
  }

  return {
    segment: params.segment,
    periodLabel: payload.periodLabel ?? buildPeriodLabel(params.month, 'api'),
    kpis,
    bridge,
    aging: payload.aging ?? [],
    recentMovements: (payload.recentMovements ?? movements).slice(0, 3),
    allMovements: movements,
  }
}

async function fetchDashboardFromApi(
  params: DashboardQueryParams,
): Promise<DashboardSnapshot> {
  const token = getAuthToken()
  // Sin Bearer: error de datos, NO logout (evita falso cierre en race de hidratación).
  if (!token) {
    throw new DashboardApiError('Sesión requerida para consultar el dashboard.', 401)
  }

  const url = new URL(`${API_BASE_URL}/dashboard`)
  url.searchParams.set('segment', params.segment)
  url.searchParams.set('month', params.month)

  let response: Response
  try {
    response = await fetch(url.toString(), {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
  } catch {
    throw new DashboardApiError(
      'No se pudo conectar con el backend de operam-servicios.',
    )
  }

  if (response.status === 401) {
    notifyUnauthorized(true)
    onUnauthorized()
    throw new DashboardApiError('Sesión expirada o inválida.', 401)
  }

  if (response.status === 204) {
    throw new DashboardEmptyError()
  }

  if (!response.ok) {
    throw new DashboardApiError(
      `Error al consultar el dashboard (HTTP ${response.status}).`,
      response.status,
    )
  }

  const payload = (await response.json()) as ApiDashboardResponse
  return mapApiDashboardToSnapshot(payload, params)
}

/**
 * Servicio híbrido: Mockup local o API real (`operam-servicios`).
 */
export async function fetchDashboardSnapshot(
  params: DashboardQueryParams,
): Promise<DashboardSnapshot> {
  if (params.mode === 'mock') {
    const snapshot = getDashboardSnapshot(params.segment)
    return {
      ...snapshot,
      periodLabel: buildPeriodLabel(params.month, 'mock'),
    }
  }

  return fetchDashboardFromApi(params)
}

/** Filtra movimientos según la fuente activa (mock local o snapshot API). */
export function filterSnapshotMovements(
  snapshot: DashboardSnapshot,
  category: MrrCategory | null,
  mode: DataSourceMode,
): MrrMovement[] {
  if (!category || category === 'inicio' || category === 'fin') {
    return snapshot.recentMovements
  }

  if (mode === 'mock') {
    return getMovementsByCategory(snapshot.segment, category)
  }

  return snapshot.allMovements.filter(
    (movement) => movement.categoria_mrr === category,
  )
}

export const DATA_SOURCE_OPTIONS: Array<{ value: DataSourceMode; label: string }> = [
  { value: 'mock', label: 'Mockup' },
  { value: 'api', label: 'API Real' },
]
