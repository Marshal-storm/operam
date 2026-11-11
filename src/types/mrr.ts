/**
 * Tipos del dominio MRR / cobranza alineados al modelo de datos
 * (`mrr_mensual`, `movimiento`, `cobranza_estado`).
 */

/** Categorías del Puente de MRR (bitácora `movimiento` + totales de periodo). */
export type MrrCategory =
  | 'inicio'
  | 'nuevo'
  | 'expansion'
  | 'ajuste_precio'
  | 'cambio_frecuencia'
  | 'contraccion'
  | 'churn'
  | 'reactivacion'
  | 'fin'

/** Segmentos de producto para filtros del dashboard. */
export type ProductSegment = 'Todos' | 'Operam' | 'Construsync'

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
  /** Delta firmado en MXN (0 en totales inicio/fin). */
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
  fecha_efectiva: string
  contrato_folio: string
  cliente_nombre: string
  segmento: Exclude<ProductSegment, 'Todos'>
  categoria_mrr: Exclude<MrrCategory, 'inicio' | 'fin'>
  descripcion: string
  /** Montos como string decimal para exactitud (RNF-1). */
  mrr_antes: string
  mrr_despues: string
  mrr_delta_mxn: string
  usuario: string
  motivo: string
  solicitud_origen: string | null
}

/** Tramos de antigüedad de cartera (`cobranza_estado`). */
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
