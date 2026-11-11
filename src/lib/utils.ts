import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

/** Zona horaria oficial de la plataforma (UI-6). */
export const APP_TIME_ZONE = 'America/Mexico_City'

/** Locale base de formato (UI-6 / i18n es-MX). */
export const APP_LOCALE = 'es-MX'

const MONTH_SHORT_ES = [
  'ene',
  'feb',
  'mar',
  'abr',
  'may',
  'jun',
  'jul',
  'ago',
  'sep',
  'oct',
  'nov',
  'dic',
] as const

/**
 * Combina clases de Tailwind de forma segura (shadcn/ui).
 * Usa clsx para condicionales y tailwind-merge para resolver conflictos.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs))
}

export type FormatCurrencyMxOptions = {
  /** Si es true, agrega el sufijo " MXN" (ej. `$1,284.50 MXN`). */
  showCurrencyCode?: boolean
  /**
   * Decimales a mostrar. Si no se indica:
   * - 0 cuando la parte fraccionaria es cero
   * - 2 en caso contrario
   */
  fractionDigits?: number
}

/**
 * Normaliza un monto a partes enteras/fraccionarias sin aritmética float.
 * Acepta string decimal (recomendado) o number solo para presentación.
 */
function splitMoneyAmount(amount: string | number): {
  sign: '-' | ''
  integerPart: string
  fractionPart: string
} {
  const raw = typeof amount === 'number' ? amount.toString() : amount.trim()
  const sign: '-' | '' = raw.startsWith('-') ? '-' : ''
  const unsigned = sign ? raw.slice(1) : raw
  const [integerPart = '0', fractionPart = ''] = unsigned.split('.')

  if (!/^\d+$/.test(integerPart) || (fractionPart !== '' && !/^\d+$/.test(fractionPart))) {
    throw new Error(`Monto inválido para formateo: ${raw}`)
  }

  return { sign, integerPart, fractionPart }
}

/**
 * Formatea montos en es-MX (UI-6 / RNF-1).
 * Ejemplos: `$1,284,500` | `$1,284.50 MXN`
 * No usar float para cálculos financieros; este helper es solo de presentación.
 */
export function formatCurrencyMx(
  amount: string | number,
  options: FormatCurrencyMxOptions = {},
): string {
  const { sign, integerPart, fractionPart } = splitMoneyAmount(amount)
  const hasFraction = fractionPart.replace(/0+$/, '').length > 0
  const fractionDigits =
    options.fractionDigits ?? (hasFraction ? 2 : 0)

  const paddedFraction = (fractionPart + '00').slice(0, Math.max(fractionDigits, 0))
  const normalized =
    fractionDigits > 0
      ? `${sign}${integerPart}.${paddedFraction}`
      : `${sign}${integerPart}`

  // Intl solo para agrupar miles/símbolo; el valor ya viene normalizado como texto decimal.
  const formatted = new Intl.NumberFormat(APP_LOCALE, {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(Number(normalized))

  // es-MX suele devolver "MX$…"; unificamos a "$…" según UI-6.
  const withDollar = formatted.replace(/^MX\$/, '$').replace(/^-\s*MX\$/, '-$')

  if (options.showCurrencyCode) {
    return `${withDollar} MXN`
  }

  return withDollar
}

/**
 * Formatea fechas como `15 sep 2026` en zona America/Mexico_City (UI-6).
 */
export function formatDateEsMx(value: Date | string | number): string {
  // Fechas solo-día (`YYYY-MM-DD`) se interpretan al mediodía UTC para no
  // cruzar de día al convertir a America/Mexico_City.
  let date: Date
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [year, month, day] = value.split('-').map(Number)
    date = new Date(Date.UTC(year, month - 1, day, 12, 0, 0))
  } else {
    date = value instanceof Date ? value : new Date(value)
  }

  if (Number.isNaN(date.getTime())) {
    throw new Error(`Fecha inválida para formateo: ${String(value)}`)
  }

  const parts = new Intl.DateTimeFormat('en-US', {
    day: 'numeric',
    month: 'numeric',
    year: 'numeric',
    timeZone: APP_TIME_ZONE,
  }).formatToParts(date)

  const day = parts.find((part) => part.type === 'day')?.value
  const month = parts.find((part) => part.type === 'month')?.value
  const year = parts.find((part) => part.type === 'year')?.value

  if (!day || !month || !year) {
    throw new Error('No se pudieron extraer partes de fecha en America/Mexico_City')
  }

  const monthLabel = MONTH_SHORT_ES[Number(month) - 1]
  return `${day} ${monthLabel} ${year}`
}
