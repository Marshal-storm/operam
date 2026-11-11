import type { MrrBridgeStep, MrrCategory } from '@/types/mrr'

/** Colores del Puente de MRR (mockup Imagen 1). */
export const MRR_BRIDGE_COLORS = {
  inicio: '#52504C',
  positive: '#0D528C',
  negative: '#9C4206',
  fin: '#1E1E1C',
} as const

/** Orden canónico de deltas del puente (excluye inicio/fin). */
export const MRR_DELTA_CATEGORY_ORDER = [
  'nuevo',
  'expansion',
  'ajuste_precio',
  'cambio_frecuencia',
  'contraccion',
  'churn',
  'reactivacion',
] as const satisfies ReadonlyArray<Exclude<MrrCategory, 'inicio' | 'fin'>>

export type MrrDeltaCategory = (typeof MRR_DELTA_CATEGORY_ORDER)[number]

const CATEGORY_LABELS: Record<MrrCategory, string> = {
  inicio: 'Inicio',
  nuevo: 'Nuevo',
  expansion: 'Expansión',
  ajuste_precio: 'Ajuste de precio',
  cambio_frecuencia: 'Cambio de frecuencia',
  contraccion: 'Contracción',
  churn: 'Churn',
  reactivacion: 'Reactivación',
  fin: 'Fin',
}

/**
 * Convierte un monto a centavos enteros con redondeo half-up (RNF-1).
 * Evita acumular error de float al operar el puente en enteros.
 */
export function toCents(amount: number): number {
  const sign = amount < 0 ? -1 : 1
  const absolute = Math.abs(amount)
  const [integerPart, fractionPart = ''] = absolute.toFixed(8).split('.')
  const wholeCents = Number(integerPart) * 100
  const fractionDigits = (fractionPart + '00').slice(0, 3)
  const twoDecimals = Number(fractionDigits.slice(0, 2))
  const third = Number(fractionDigits.slice(2, 3) || '0')
  const roundedFraction = twoDecimals + (third >= 5 ? 1 : 0)
  return sign * (wholeCents + roundedFraction)
}

/** Centavos enteros → monto con exactamente 2 decimales. */
export function fromCents(cents: number): number {
  const sign = cents < 0 ? -1 : 1
  const abs = Math.abs(cents)
  const whole = Math.trunc(abs / 100)
  const frac = String(abs % 100).padStart(2, '0')
  return Number(`${sign < 0 ? '-' : ''}${whole}.${frac}`)
}

/** Redondeo monetario exacto a 2 decimales (RNF-1). */
export function roundMoney2(amount: number): number {
  return fromCents(toCents(amount))
}

/**
 * Etiquetas compactas del puente (`1,254.3k`, `+21.6k`).
 * Usa truncado entero sobre el valor absoluto en pesos.
 */
export function formatBridgeCompactLabel(value: number, withSign: boolean): string {
  const rounded = roundMoney2(value)
  const sign = rounded < 0 ? '-' : withSign && rounded > 0 ? '+' : ''
  const absCents = Math.abs(toCents(rounded))
  // k = miles de pesos → centavos / 100_000, un decimal
  const tenthsOfThousand = Math.trunc(absCents / 10_000)
  const whole = Math.trunc(tenthsOfThousand / 10)
  const tenth = tenthsOfThousand % 10
  const grouped = whole.toLocaleString('es-MX')
  return `${sign}${grouped}.${tenth}k`
}

export type MrrDeltasByCategory = Partial<Record<MrrDeltaCategory, number>> &
  Record<string, number>

/**
 * Motor puro del Puente de MRR.
 * `Inicio + sum(deltas) = Fin`, con barras start/size listas para Recharts.
 * Compatible con mockup y con agregados de API/`mrr_mensual`.
 */
export function buildMrrBridgeSteps(
  mrrInicio: number,
  deltasByCategory: MrrDeltasByCategory,
): MrrBridgeStep[] {
  const inicio = roundMoney2(mrrInicio)
  let accumulatedCents = toCents(inicio)

  const steps: MrrBridgeStep[] = [
    {
      label: CATEGORY_LABELS.inicio,
      category: 'inicio',
      deltaMxn: 0,
      start: 0,
      size: inicio,
      accumulated: inicio,
      type: 'total',
      color: MRR_BRIDGE_COLORS.inicio,
      displayLabel: formatBridgeCompactLabel(inicio, false),
    },
  ]

  for (const category of MRR_DELTA_CATEGORY_ORDER) {
    const rawDelta = deltasByCategory[category] ?? 0
    const delta = roundMoney2(rawDelta)
    const deltaCents = toCents(delta)
    const isNegative = deltaCents < 0
    const size = roundMoney2(Math.abs(delta))
    const startCents = isNegative ? accumulatedCents + deltaCents : accumulatedCents
    accumulatedCents += deltaCents

    steps.push({
      label: CATEGORY_LABELS[category],
      category,
      deltaMxn: delta,
      start: fromCents(startCents),
      size,
      accumulated: fromCents(accumulatedCents),
      type: isNegative ? 'negative' : 'positive',
      color: isNegative ? MRR_BRIDGE_COLORS.negative : MRR_BRIDGE_COLORS.positive,
      displayLabel: formatBridgeCompactLabel(delta, true),
    })
  }

  const fin = fromCents(accumulatedCents)
  steps.push({
    label: CATEGORY_LABELS.fin,
    category: 'fin',
    deltaMxn: 0,
    start: 0,
    size: fin,
    accumulated: fin,
    type: 'total',
    color: MRR_BRIDGE_COLORS.fin,
    displayLabel: formatBridgeCompactLabel(fin, false),
  })

  return steps
}
