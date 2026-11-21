import type { BillingFrequency, PackageOptimizationResult, PriceListItem, ServiceType } from '@/types/catalog'

/** Convierte monto a centavos enteros (evita float — RNF-1). */
function toCents(amount: number | string): number {
  const raw =
    typeof amount === 'number'
      ? amount.toFixed(2)
      : String(amount).trim().replace(/,/g, '')
  const match = raw.match(/^(-?\d+)(?:\.(\d{0,2}))?$/)
  if (!match) {
    throw new Error(`Monto inválido: ${amount}`)
  }
  const whole = Number(match[1])
  const frac = (match[2] ?? '00').padEnd(2, '0').slice(0, 2)
  const sign = whole < 0 || raw.startsWith('-') ? -1 : 1
  return sign * (Math.abs(whole) * 100 + Number(frac))
}

function fromCents(cents: number): string {
  const sign = cents < 0 ? '-' : ''
  const abs = Math.abs(cents)
  const whole = Math.trunc(abs / 100)
  const frac = String(abs % 100).padStart(2, '0')
  return `${sign}${whole}.${frac}`
}

/**
 * RF-2: descompone usuarios en paquetes de N (`packageFactor`) + sueltos.
 * Usa aritmética de centavos (sin float) y redondeo exacto a 2 decimales.
 */
export function optimizeUserPackages(
  userCount: number,
  singleUserPrice: number | string,
  packPrice: number | string,
  packageFactor = 5,
): PackageOptimizationResult {
  const safeCount = Math.max(0, Math.trunc(userCount))
  const factor = Math.max(2, Math.trunc(packageFactor) || 5)
  const packsCount = Math.floor(safeCount / factor)
  const leftoverUsers = safeCount % factor

  const singleCents = toCents(singleUserPrice)
  const packCents = toCents(packPrice)

  const naiveTotalCents = singleCents * safeCount
  const optimizedTotalCents = packCents * packsCount + singleCents * leftoverUsers
  const savingsCents = naiveTotalCents - optimizedTotalCents

  return {
    userCount: safeCount,
    packageFactor: factor,
    packsCount,
    /** @deprecated Alias de packsCount (compat RF-2 histórico de factor 5). */
    packsOf5: packsCount,
    leftoverUsers,
    singleUserPrice: fromCents(singleCents),
    packPrice: fromCents(packCents),
    /** @deprecated Alias de packPrice. */
    pack5Price: fromCents(packCents),
    naiveTotal: fromCents(naiveTotalCents),
    optimizedTotal: fromCents(optimizedTotalCents),
    savings: fromCents(Math.max(0, savingsCents)),
  }
}

export type TierPriceResolveOk = {
  ok: true
  unitPrice: string
  frequencyUsed: BillingFrequency
}

export type TierPriceResolveErr = {
  ok: false
  reason: 'no_tiers' | 'out_of_range'
  /** Máximo cubierto por los escalones (null si hay ∞). */
  maxQuantity: number | null
  unitPrice: '0.00'
}

export type TierPriceResolveResult = TierPriceResolveOk | TierPriceResolveErr

function isOneTimeOrConsumption(serviceType?: ServiceType | string): boolean {
  return (
    serviceType === 'single' ||
    serviceType === 'consumption' ||
    serviceType === 'one_time'
  )
}

/**
 * Frecuencias a consultar en price_list_items.
 * one_time/consumo: prioriza `single` (alias one_time) y cae a `monthly` por compatibilidad.
 */
function candidateFrequencies(
  contractFrequency: 'monthly' | 'annual',
  serviceType?: ServiceType | string,
): BillingFrequency[] {
  if (isOneTimeOrConsumption(serviceType)) {
    return ['single', 'monthly']
  }
  return [contractFrequency]
}

function maxCoveredQuantity(
  tiers: Array<{ min_quantity: number; max_quantity: number | null }>,
): number | null {
  let max: number | null = 0
  for (const t of tiers) {
    if (t.max_quantity === null) return null
    if (max === null || t.max_quantity > max) max = t.max_quantity
  }
  return max
}

/**
 * Resuelve precio unitario por escalón sin devolver $0.00 silencioso.
 * Si la cantidad no cae en ningún rango → out_of_range (no usa el último escalón).
 */
export function resolveTierPriceDetailed(
  items: PriceListItem[],
  serviceId: number,
  contractFrequency: 'monthly' | 'annual',
  quantity: number,
  serviceType?: ServiceType | string,
): TierPriceResolveResult {
  const freqs = candidateFrequencies(contractFrequency, serviceType)
  let tiers: PriceListItem[] = []
  let frequencyUsed: BillingFrequency = freqs[0]

  for (const freq of freqs) {
    const matched = items
      .filter((i) => i.service_id === serviceId && i.frequency === freq)
      .sort((a, b) => a.min_quantity - b.min_quantity)
    if (matched.length > 0) {
      tiers = matched
      frequencyUsed = freq
      break
    }
  }

  // Último recurso: cualquier frecuencia del servicio en la lista.
  if (tiers.length === 0) {
    tiers = items
      .filter((i) => i.service_id === serviceId)
      .sort((a, b) => a.min_quantity - b.min_quantity)
    if (tiers.length > 0) {
      frequencyUsed = tiers[0].frequency
    }
  }

  if (tiers.length === 0) {
    return { ok: false, reason: 'no_tiers', maxQuantity: null, unitPrice: '0.00' }
  }

  const match = tiers.find(
    (t) =>
      quantity >= t.min_quantity &&
      (t.max_quantity === null || quantity <= t.max_quantity),
  )

  if (!match) {
    return {
      ok: false,
      reason: 'out_of_range',
      maxQuantity: maxCoveredQuantity(tiers),
      unitPrice: '0.00',
    }
  }

  return { ok: true, unitPrice: match.unit_price, frequencyUsed }
}

/** Atajo: precio o '0.00' (preferir resolveTierPriceDetailed en UI). */
export function resolveTierPrice(
  items: PriceListItem[],
  serviceId: number,
  contractFrequency: 'monthly' | 'annual',
  quantity: number,
  serviceType?: ServiceType | string,
): string {
  const result = resolveTierPriceDetailed(
    items,
    serviceId,
    contractFrequency,
    quantity,
    serviceType,
  )
  return result.unitPrice
}

/** Etiqueta de lista sin duplicar "(MXN)" si el nombre ya incluye la moneda. */
export function formatPriceListLabel(name: string, currency: string): string {
  const trimmed = name.trim()
  const currencyToken = currency.trim().toUpperCase()
  if (!currencyToken) return trimmed
  const alreadyHas =
    new RegExp(`\\(\\s*${currencyToken}\\s*\\)`, 'i').test(trimmed) ||
    new RegExp(`\\b${currencyToken}\\b`, 'i').test(trimmed)
  return alreadyHas ? trimmed : `${trimmed} (${currencyToken})`
}
