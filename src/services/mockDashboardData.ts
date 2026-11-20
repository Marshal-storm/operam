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
  start: number
  deltas: Record<string, number>
}

/** Semillas exactas del filtro "Todos" (cierre sep 2026). */
const TODOS_BRIDGE: BridgeInput = {
  start: 1_254_300,
  deltas: {
    new: 21_600,
    expansion: 18_200,
    price_adjustment: 6_100,
    frequency_change: 1_900,
    contraction: -7_400,
    churn: -14_300,
    reactivation: 4_100,
  },
}

/** Operam (~proporción coherente que cuadra Inicio+deltas=Fin). */
const OPERAM_BRIDGE: BridgeInput = {
  start: 780_000,
  deltas: {
    new: 14_000,
    expansion: 11_000,
    price_adjustment: 4_000,
    frequency_change: 1_200,
    contraction: -4_500,
    churn: -9_000,
    reactivation: 2_500,
  },
}

/** Construsync = complemento de Todos − Operam por categoría. */
const CONSTRUSYNC_BRIDGE: BridgeInput = {
  start: 474_300,
  deltas: {
    new: 7_600,
    expansion: 7_200,
    price_adjustment: 2_100,
    frequency_change: 700,
    contraction: -2_900,
    churn: -5_300,
    reactivation: 1_600,
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
    effective_date: '2026-09-24',
    contract_folio: 'CT-0412',
    client_name: '[Cliente A]',
    segment: 'Operam',
    mrr_category: 'expansion',
    description: 'Expansión · +2 usuarios',
    mrr_before: '1512.50',
    mrr_after: '1815.00',
    mrr_delta_mxn: '302.50',
    user: 'ana.lopez',
    reason: 'Alta de puestos adicionales',
    request_origin: 'SOL-118',
  },
  {
    id: 'mov-002',
    effective_date: '2026-09-23',
    contract_folio: 'CT-0598',
    client_name: '[Cliente B]',
    segment: 'Construsync',
    mrr_category: 'new',
    description: 'Nuevo contrato',
    mrr_before: '0',
    mrr_after: '8900',
    mrr_delta_mxn: '8900',
    user: 'carlos.ruiz',
    reason: 'Alta comercial',
    request_origin: 'SOL-121',
  },
  {
    id: 'mov-003',
    effective_date: '2026-09-22',
    contract_folio: 'CT-0217',
    client_name: '[Cliente C]',
    segment: 'Operam',
    mrr_category: 'churn',
    description: 'Churn · suspensión 90 días',
    mrr_before: '2450',
    mrr_after: '0',
    mrr_delta_mxn: '-2450',
    user: 'sistema',
    reason: 'Suspensión automática por mora',
    request_origin: null,
  },
  {
    id: 'mov-004',
    effective_date: '2026-09-20',
    contract_folio: 'CT-0601',
    client_name: '[Cliente D]',
    segment: 'Operam',
    mrr_category: 'new',
    description: 'Nuevo contrato',
    mrr_before: '0',
    mrr_after: '5200',
    mrr_delta_mxn: '5200',
    user: 'ana.lopez',
    reason: 'Cierre ciclo septiembre',
    request_origin: 'SOL-124',
  },
  {
    id: 'mov-005',
    effective_date: '2026-09-19',
    contract_folio: 'CT-0333',
    client_name: '[Cliente E]',
    segment: 'Construsync',
    mrr_category: 'expansion',
    description: 'Expansión · módulo logística',
    mrr_before: '6400',
    mrr_after: '9100',
    mrr_delta_mxn: '2700',
    user: 'maria.soto',
    reason: 'Upsell aprobado',
    request_origin: 'SOL-119',
  },
  {
    id: 'mov-006',
    effective_date: '2026-09-18',
    contract_folio: 'CT-0188',
    client_name: '[Cliente F]',
    segment: 'Operam',
    mrr_category: 'price_adjustment',
    description: 'Ajuste de precio · lista 2026',
    mrr_before: '3100',
    mrr_after: '3400',
    mrr_delta_mxn: '300',
    user: 'finanzas',
    reason: 'Actualización de tarifa',
    request_origin: 'SOL-110',
  },
  {
    id: 'mov-007',
    effective_date: '2026-09-17',
    contract_folio: 'CT-0444',
    client_name: '[Cliente G]',
    segment: 'Construsync',
    mrr_category: 'frequency_change',
    description: 'Cambio de frecuencia · anual a mensual',
    mrr_before: '0',
    mrr_after: '1900',
    mrr_delta_mxn: '1900',
    user: 'carlos.ruiz',
    reason: 'Rebilling mensual',
    request_origin: 'SOL-115',
  },
  {
    id: 'mov-008',
    effective_date: '2026-09-16',
    contract_folio: 'CT-0271',
    client_name: '[Cliente H]',
    segment: 'Operam',
    mrr_category: 'contraction',
    description: 'Contracción · -3 usuarios',
    mrr_before: '4200',
    mrr_after: '2850',
    mrr_delta_mxn: '-1350',
    user: 'ana.lopez',
    reason: 'Reducción de asientos',
    request_origin: 'SOL-112',
  },
  {
    id: 'mov-009',
    effective_date: '2026-09-15',
    contract_folio: 'CT-0510',
    client_name: '[Cliente I]',
    segment: 'Construsync',
    mrr_category: 'churn',
    description: 'Churn · cancelación voluntaria',
    mrr_before: '4800',
    mrr_after: '0',
    mrr_delta_mxn: '-4800',
    user: 'maria.soto',
    reason: 'Cliente no renovó',
    request_origin: 'SOL-108',
  },
  {
    id: 'mov-010',
    effective_date: '2026-09-12',
    contract_folio: 'CT-0099',
    client_name: '[Cliente J]',
    segment: 'Operam',
    mrr_category: 'reactivation',
    description: 'Reactivación · fin de suspensión',
    mrr_before: '0',
    mrr_after: '2100',
    mrr_delta_mxn: '2100',
    user: 'sistema',
    reason: 'Pago de cartera vencida',
    request_origin: null,
  },
  {
    id: 'mov-011',
    effective_date: '2026-09-10',
    contract_folio: 'CT-0612',
    client_name: '[Cliente K]',
    segment: 'Construsync',
    mrr_category: 'new',
    description: 'Nuevo contrato',
    mrr_before: '0',
    mrr_after: '3700',
    mrr_delta_mxn: '3700',
    user: 'carlos.ruiz',
    reason: 'Alta partner',
    request_origin: 'SOL-130',
  },
  {
    id: 'mov-012',
    effective_date: '2026-09-08',
    contract_folio: 'CT-0301',
    client_name: '[Cliente L]',
    segment: 'Operam',
    mrr_category: 'expansion',
    description: 'Expansión · plan profesional',
    mrr_before: '1800',
    mrr_after: '2950',
    mrr_delta_mxn: '1150',
    user: 'ana.lopez',
    reason: 'Upgrade de plan',
    request_origin: 'SOL-127',
  },
]

function filterMovements(
  segment: ProductSegment,
  category?: MrrCategory,
): MrrMovement[] {
  return ALL_MOVEMENTS.filter((movement) => {
    const segmentOk = segment === 'Todos' || movement.segment === segment
    const categoryOk =
      !category ||
      category === 'start' ||
      category === 'end' ||
      movement.mrr_category === category
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
    bridge: buildMrrBridgeSteps(bridgeInput.start, bridgeInput.deltas),
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
