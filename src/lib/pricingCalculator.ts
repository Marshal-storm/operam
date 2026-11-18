import type { PackageOptimizationResult } from '@/types/catalog'

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
 * RF-2: descompone usuarios en paquetes de 5 + sueltos.
 * Usa aritmética de centavos (sin float) y redondeo exacto a 2 decimales.
 */
export function optimizeUserPackages(
  userCount: number,
  singleUserPrice: number | string,
  pack5Price: number | string,
): PackageOptimizationResult {
  const safeCount = Math.max(0, Math.trunc(userCount))
  const packsOf5 = Math.floor(safeCount / 5)
  const leftoverUsers = safeCount % 5

  const singleCents = toCents(singleUserPrice)
  const packCents = toCents(pack5Price)

  const naiveTotalCents = singleCents * safeCount
  const optimizedTotalCents = packCents * packsOf5 + singleCents * leftoverUsers
  const savingsCents = naiveTotalCents - optimizedTotalCents

  return {
    userCount: safeCount,
    packsOf5,
    leftoverUsers,
    singleUserPrice: fromCents(singleCents),
    pack5Price: fromCents(packCents),
    naiveTotal: fromCents(naiveTotalCents),
    optimizedTotal: fromCents(optimizedTotalCents),
    savings: fromCents(Math.max(0, savingsCents)),
  }
}
