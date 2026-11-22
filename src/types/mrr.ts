/**
 * Tipos del dominio MRR / cobranza alineados al modelo de datos
 * (`monthly_mrr`, `movements`, `collection_statuses`).
 */

/** Categorías del Puente de MRR (bitácora `movements` + totales de periodo). */
export type MrrCategory =
  | 'start'
  | 'new'
  | 'expansion'
  | 'price_adjustment'
  | 'frequency_change'
  | 'contraction'
  | 'churn'
  | 'reactivation'
  | 'end'

/**
 * Filtro de segmento del dashboard.
 * "Todos" = sin filtro. El mock conserva las etiquetas Operam / Construsync;
 * el modo API acepta cualquier `name` real de la tabla `segments`.
 */
export type ProductSegment = string

/** Tipo visual de cada barra del gráfico de cascada. */
export type MrrBridgeBarType = 'total' | 'positive' | 'negative'

/**
 * Paso del Puente de MRR listo para Recharts (cascada).
 * `start` es la base invisible; `size` es la altura visible de la barra.
 * Los montos enteros en MXN evitan float en el mock de cierre.
 */
export interface MrrBridgeStep {
  /** Nombre visible en el eje X (español UI). */
  label: string
  category: MrrCategory
  /** Delta firmado en MXN (0 en totales start/end). */
  deltaMxn: number
  /** Base invisible del stack (waterfall). */
  start: number
  /** Altura absoluta de la barra coloreada. */
  size: number
  /** Valor acumulado al cerrar este paso. */
  accumulated: number
  type: MrrBridgeBarType
  /** Color de la barra según mockup. */
  color: string
  /** Etiqueta superior (ej. `1,254.3k`, `+21.6k`). */
  displayLabel: string
}

/** Registro de la bitácora de movimientos que origina el MRR. */
export interface MrrMovement {
  id: string
  /** ISO date `YYYY-MM-DD` (se formatea a UI-6 en la vista). */
  effective_date: string
  contract_folio: string
  client_name: string
  /** Nombre del segmento del movimiento (el filtro "Todos" no se persiste aquí). */
  segment: string
  mrr_category: Exclude<MrrCategory, 'start' | 'end'>
  description: string
  /** Montos como string decimal para exactitud (RNF-1). */
  mrr_before: string
  mrr_after: string
  mrr_delta_mxn: string
  user: string
  reason: string
  /** Folio SOL-XXXX cuando el movimiento nace de una solicitud (RF-3.7). */
  request_folio?: string | null
  /** Alias histórico; preferir `request_folio`. */
  request_origin: string | null
}

/** Tramos de antigüedad de cartera (`collection_statuses`). */
export type AgingBucketLabel =
  | 'Por vencer'
  | '1-30 días'
  | '31-60 días'
  | '61-90 días'
  | 'Suspendidos (+90)'

export interface AgingBucket {
  label: AgingBucketLabel
  /** Monto entero en MXN. */
  amountMxn: number
  /** Color de la barra de progreso. */
  color: string
}

/** KPIs superiores del Dashboard General Interno. */
export interface DashboardKpis {
  mrrMxn: number
  mrrDeltaVsPrevPercent: string
  activeContracts: number
  newContracts: number
  churnedContracts: number
  nrr12mPercent: string
  grrPercent: string
  overduePortfolioMxn: number
  overdueOver60Count: number
}

/** Payload completo del dashboard por segmento. */
export interface DashboardSnapshot {
  segment: ProductSegment
  periodLabel: string
  kpis: DashboardKpis
  bridge: MrrBridgeStep[]
  aging: AgingBucket[]
  recentMovements: MrrMovement[]
  allMovements: MrrMovement[]
}
