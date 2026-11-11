import { buildMrrBridgeSteps } from '@/lib/mrrCalculator'
import type {
  AgingBucket,
  DashboardKpis,
  DashboardSnapshot,
  MrrCategory,
  MrrMovement,
  ProductSegment,
} from '@/types/mrr'

type BridgeInput = {
  inicio: number
  deltas: Record<string, number>
}

/** Semillas exactas del filtro "Todos" (cierre sep 2026). */
const TODOS_BRIDGE: BridgeInput = {
  inicio: 1_254_300,
  deltas: {
    nuevo: 21_600,
    expansion: 18_200,
    ajuste_precio: 6_100,
    cambio_frecuencia: 1_900,
    contraccion: -7_400,
    churn: -14_300,
    reactivacion: 4_100,
  },
}

/** Operam (~proporción coherente que cuadra Inicio+deltas=Fin). */
const OPERAM_BRIDGE: BridgeInput = {
  inicio: 780_000,
  deltas: {
    nuevo: 14_000,
    expansion: 11_000,
    ajuste_precio: 4_000,
    cambio_frecuencia: 1_200,
    contraccion: -4_500,
    churn: -9_000,
    reactivacion: 2_500,
  },
}

/** Construsync = complemento de Todos − Operam por categoría. */
const CONSTRUSYNC_BRIDGE: BridgeInput = {
  inicio: 474_300,
  deltas: {
    nuevo: 7_600,
    expansion: 7_200,
    ajuste_precio: 2_100,
    cambio_frecuencia: 700,
    contraccion: -2_900,
    churn: -5_300,
    reactivacion: 1_600,
  },
}

const AGING_TODOS: AgingBucket[] = [
  { label: 'Por vencer', amountMxn: 286_400, color: '#0D528C' },
  { label: '1-30 días', amountMxn: 198_200, color: '#5B8DB8' },
  { label: '31-60 días', amountMxn: 121_900, color: '#D97706' },
  { label: '61-90 días', amountMxn: 68_300, color: '#C2410C' },
  { label: 'Suspendidos (+90)', amountMxn: 24_400, color: '#7F1D1D' },
]

function scaleAging(factorNumer: number, factorDenom: number): AgingBucket[] {
  return AGING_TODOS.map((bucket) => ({
    ...bucket,
    amountMxn: Math.trunc((bucket.amountMxn * factorNumer) / factorDenom),
  }))
}

const KPIS_TODOS: DashboardKpis = {
  mrrMxn: 1_284_500,
  mrrDeltaVsPrevPercent: '+2.4%',
  activeContracts: 598,
  newContracts: 9,
  churnedContracts: 6,
  nrr12mPercent: '98.1%',
  grrPercent: '91.3%',
  overduePortfolioMxn: 412_800,
  overdueOver60Count: 7,
}

/** Movimientos del mockup + extras para drill-down RF-3.7. */
const ALL_MOVEMENTS: MrrMovement[] = [
  {
    id: 'mov-001',
    fecha_efectiva: '2026-09-24',
    contrato_folio: 'CT-0412',
    cliente_nombre: '[Cliente A]',
    segmento: 'Operam',
    categoria_mrr: 'expansion',
    descripcion: 'Expansión · +2 usuarios',
    mrr_antes: '1512.50',
    mrr_despues: '1815.00',
    mrr_delta_mxn: '302.50',
    usuario: 'ana.lopez',
    motivo: 'Alta de puestos adicionales',
    solicitud_origen: 'SOL-118',
  },
  {
    id: 'mov-002',
    fecha_efectiva: '2026-09-23',
    contrato_folio: 'CT-0598',
    cliente_nombre: '[Cliente B]',
    segmento: 'Construsync',
    categoria_mrr: 'nuevo',
    descripcion: 'Nuevo contrato',
    mrr_antes: '0',
    mrr_despues: '8900',
    mrr_delta_mxn: '8900',
    usuario: 'carlos.ruiz',
    motivo: 'Alta comercial',
    solicitud_origen: 'SOL-121',
  },
  {
    id: 'mov-003',
    fecha_efectiva: '2026-09-22',
    contrato_folio: 'CT-0217',
    cliente_nombre: '[Cliente C]',
    segmento: 'Operam',
    categoria_mrr: 'churn',
    descripcion: 'Churn · suspensión 90 días',
    mrr_antes: '2450',
    mrr_despues: '0',
    mrr_delta_mxn: '-2450',
    usuario: 'sistema',
    motivo: 'Suspensión automática por mora',
    solicitud_origen: null,
  },
  {
    id: 'mov-004',
    fecha_efectiva: '2026-09-20',
    contrato_folio: 'CT-0601',
    cliente_nombre: '[Cliente D]',
    segmento: 'Operam',
    categoria_mrr: 'nuevo',
    descripcion: 'Nuevo contrato',
    mrr_antes: '0',
    mrr_despues: '5200',
    mrr_delta_mxn: '5200',
    usuario: 'ana.lopez',
    motivo: 'Cierre ciclo septiembre',
    solicitud_origen: 'SOL-124',
  },
  {
    id: 'mov-005',
    fecha_efectiva: '2026-09-19',
    contrato_folio: 'CT-0333',
    cliente_nombre: '[Cliente E]',
    segmento: 'Construsync',
    categoria_mrr: 'expansion',
    descripcion: 'Expansión · módulo logística',
    mrr_antes: '6400',
    mrr_despues: '9100',
    mrr_delta_mxn: '2700',
    usuario: 'maria.soto',
    motivo: 'Upsell aprobado',
    solicitud_origen: 'SOL-119',
  },
  {
    id: 'mov-006',
    fecha_efectiva: '2026-09-18',
    contrato_folio: 'CT-0188',
    cliente_nombre: '[Cliente F]',
    segmento: 'Operam',
    categoria_mrr: 'ajuste_precio',
    descripcion: 'Ajuste de precio · lista 2026',
    mrr_antes: '3100',
    mrr_despues: '3400',
    mrr_delta_mxn: '300',
    usuario: 'finanzas',
    motivo: 'Actualización de tarifa',
    solicitud_origen: 'SOL-110',
  },
  {
    id: 'mov-007',
    fecha_efectiva: '2026-09-17',
    contrato_folio: 'CT-0444',
    cliente_nombre: '[Cliente G]',
    segmento: 'Construsync',
    categoria_mrr: 'cambio_frecuencia',
    descripcion: 'Cambio de frecuencia · anual a mensual',
    mrr_antes: '0',
    mrr_despues: '1900',
    mrr_delta_mxn: '1900',
    usuario: 'carlos.ruiz',
    motivo: 'Rebilling mensual',
    solicitud_origen: 'SOL-115',
  },
  {
    id: 'mov-008',
    fecha_efectiva: '2026-09-16',
    contrato_folio: 'CT-0271',
    cliente_nombre: '[Cliente H]',
    segmento: 'Operam',
    categoria_mrr: 'contraccion',
    descripcion: 'Contracción · -3 usuarios',
    mrr_antes: '4200',
    mrr_despues: '2850',
    mrr_delta_mxn: '-1350',
    usuario: 'ana.lopez',
    motivo: 'Reducción de asientos',
    solicitud_origen: 'SOL-112',
  },
  {
    id: 'mov-009',
    fecha_efectiva: '2026-09-15',
    contrato_folio: 'CT-0510',
    cliente_nombre: '[Cliente I]',
    segmento: 'Construsync',
    categoria_mrr: 'churn',
    descripcion: 'Churn · cancelación voluntaria',
    mrr_antes: '4800',
    mrr_despues: '0',
    mrr_delta_mxn: '-4800',
    usuario: 'maria.soto',
    motivo: 'Cliente no renovó',
    solicitud_origen: 'SOL-108',
  },
  {
    id: 'mov-010',
    fecha_efectiva: '2026-09-12',
    contrato_folio: 'CT-0099',
    cliente_nombre: '[Cliente J]',
    segmento: 'Operam',
    categoria_mrr: 'reactivacion',
    descripcion: 'Reactivación · fin de suspensión',
    mrr_antes: '0',
    mrr_despues: '2100',
    mrr_delta_mxn: '2100',
    usuario: 'sistema',
    motivo: 'Pago de cartera vencida',
    solicitud_origen: null,
  },
  {
    id: 'mov-011',
    fecha_efectiva: '2026-09-10',
    contrato_folio: 'CT-0612',
    cliente_nombre: '[Cliente K]',
    segmento: 'Construsync',
    categoria_mrr: 'nuevo',
    descripcion: 'Nuevo contrato',
    mrr_antes: '0',
    mrr_despues: '3700',
    mrr_delta_mxn: '3700',
    usuario: 'carlos.ruiz',
    motivo: 'Alta partner',
    solicitud_origen: 'SOL-130',
  },
  {
    id: 'mov-012',
    fecha_efectiva: '2026-09-08',
    contrato_folio: 'CT-0301',
    cliente_nombre: '[Cliente L]',
    segmento: 'Operam',
    categoria_mrr: 'expansion',
    descripcion: 'Expansión · plan profesional',
    mrr_antes: '1800',
    mrr_despues: '2950',
    mrr_delta_mxn: '1150',
    usuario: 'ana.lopez',
    motivo: 'Upgrade de plan',
    solicitud_origen: 'SOL-127',
  },
]

function filterMovements(
  segment: ProductSegment,
  category?: MrrCategory,
): MrrMovement[] {
  return ALL_MOVEMENTS.filter((movement) => {
    const segmentOk = segment === 'Todos' || movement.segmento === segment
    const categoryOk =
      !category ||
      category === 'inicio' ||
      category === 'fin' ||
      movement.categoria_mrr === category
    return segmentOk && categoryOk
  })
}

function getKpis(segment: ProductSegment): DashboardKpis {
  if (segment === 'Operam') {
    return {
      mrrMxn: 799_200,
      mrrDeltaVsPrevPercent: '+2.1%',
      activeContracts: 372,
      newContracts: 6,
      churnedContracts: 4,
      nrr12mPercent: '97.6%',
      grrPercent: '90.8%',
      overduePortfolioMxn: 256_000,
      overdueOver60Count: 4,
    }
  }

  if (segment === 'Construsync') {
    return {
      mrrMxn: 485_300,
      mrrDeltaVsPrevPercent: '+2.9%',
      activeContracts: 226,
      newContracts: 3,
      churnedContracts: 2,
      nrr12mPercent: '98.8%',
      grrPercent: '92.1%',
      overduePortfolioMxn: 156_800,
      overdueOver60Count: 3,
    }
  }

  return KPIS_TODOS
}

function getBridgeInput(segment: ProductSegment): BridgeInput {
  if (segment === 'Operam') return OPERAM_BRIDGE
  if (segment === 'Construsync') return CONSTRUSYNC_BRIDGE
  return TODOS_BRIDGE
}

function getAging(segment: ProductSegment): AgingBucket[] {
  if (segment === 'Operam') return scaleAging(62, 100)
  if (segment === 'Construsync') return scaleAging(38, 100)
  return AGING_TODOS
}

/**
 * Devuelve el snapshot del dashboard para el segmento seleccionado.
 * Fuente mock del cierre de septiembre 2026 (documento de diseño).
 */
export function getDashboardSnapshot(segment: ProductSegment): DashboardSnapshot {
  const movements = filterMovements(segment)
  const bridgeInput = getBridgeInput(segment)

  return {
    segment,
    periodLabel: 'Cierre de septiembre 2026 · Datos de ejemplo',
    kpis: getKpis(segment),
    bridge: buildMrrBridgeSteps(bridgeInput.inicio, bridgeInput.deltas),
    aging: getAging(segment),
    recentMovements: movements.slice(0, 3),
    allMovements: movements,
  }
}

/** Filtra la bitácora por categoría del puente (RF-3.7). */
export function getMovementsByCategory(
  segment: ProductSegment,
  category: MrrCategory,
): MrrMovement[] {
  return filterMovements(segment, category)
}

export const SEGMENT_OPTIONS: ProductSegment[] = ['Todos', 'Operam', 'Construsync']
