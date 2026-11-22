import type { MrrBridgeStep, MrrCategory } from '@/types/mrr'

/** Colores del Puente de MRR (mockup Imagen 1). */
export const MRR_BRIDGE_COLORS = {
  start: '#52504C',
  positive: '#0D528C',
  negative: '#9C4206',
  end: '#1E1E1C',
} as const

/** Orden canónico de deltas del puente (excluye start/end). */
export const MRR_DELTA_CATEGORY_ORDER = [
  'new',
  'expansion',
  'price_adjustment',
  'frequency_change',
  'contraction',
  'churn',
  'reactivation',
] as const satisfies ReadonlyArray<Exclude<MrrCategory, 'start' | 'end'>>

export type MrrDeltaCategory = (typeof MRR_DELTA_CATEGORY_ORDER)[number]

/** Etiquetas visibles en español; las claves del puente son ENUMs en inglés. */
const CATEGORY_LABELS: Record<MrrCategory, string> = {
  start: 'Inicio',
  new: 'Nuevo',
  expansion: 'Expansión',
  price_adjustment: 'Ajuste de precio',
  frequency_change: 'Cambio de frecuencia',
  contraction: 'Contracción',
  churn: 'Churn',
  reactivation: 'Reactivación',
  end: 'Fin',
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
 * Etiquetas compactas del puente (`+3.9k`, `4.2k`).
 * Redondea a 1 decimal en miles (no trunca): 3887.10 → +3.9k, 4154.10 → 4.2k.
 */
export function formatBridgeCompactLabel(value: number, withSign: boolean): string {
  const rounded = roundMoney2(value)
  const sign = rounded < 0 ? '-' : withSign && rounded > 0 ? '+' : ''
  const abs = Math.abs(rounded)
  // Miles con 1 decimal, redondeo half-up vía toFixed.
  const inThousands = Number((abs / 1000).toFixed(1))
  const [wholePart, tenthPart = '0'] = inThousands.toFixed(1).split('.')
  const grouped = Number(wholePart).toLocaleString('es-MX')
  return `${sign}${grouped}.${tenthPart}k`
}

export type MrrDeltasByCategory = Partial<Record<MrrDeltaCategory, number>> &
  Record<string, number>

/**
 * Motor puro del Puente de MRR.
 * `Inicio + sum(deltas) = Fin`, con barras start/size listas para Recharts.
 * Compatible con mockup y con agregados de API/`mrr_mensual`.
 */
export function buildMrrBridgeSteps(
  mrrStart: number,
  deltasByCategory: MrrDeltasByCategory,
): MrrBridgeStep[] {
  const opening = roundMoney2(mrrStart)
  let accumulatedCents = toCents(opening)

  const steps: MrrBridgeStep[] = [
    {
      label: CATEGORY_LABELS.start,
      category: 'start',
      deltaMxn: 0,
      start: 0,
      size: opening,
      accumulated: opening,
      type: 'total',
      color: MRR_BRIDGE_COLORS.start,
      displayLabel: formatBridgeCompactLabel(opening, false),
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

  const closing = fromCents(accumulatedCents)
  steps.push({
    label: CATEGORY_LABELS.end,
    category: 'end',
    deltaMxn: 0,
    start: 0,
    size: closing,
    accumulated: closing,
    type: 'total',
    color: MRR_BRIDGE_COLORS.end,
    displayLabel: formatBridgeCompactLabel(closing, false),
  })

  return steps
}
