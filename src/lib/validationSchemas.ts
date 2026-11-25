import { z } from 'zod'

/**
 * Esquemas Zod reutilizables para montos, tipos de cambio y enteros (RNF-15).
 * Usar en TODOS los formularios antes de disparar peticiones HTTP.
 */

const MONEY_MSG = 'Ingresa un monto numérico válido (ej. 1500.00)'
const FX_MSG = 'Ingresa un tipo de cambio válido (ej. 17.2500)'
const INT_MSG = 'Ingresa un número entero válido'
const POSITIVE_INT_MSG = 'Ingresa un entero mayor a 0'

/** Limpia espacios y comas de miles; no convierte letras. */
export function cleanNumericInput(raw: string): string {
  return String(raw ?? '')
    .trim()
    .replace(/,/g, '')
    .replace(/\s+/g, '')
}

/**
 * Monto DECIMAL(14,2): solo dígitos + opcional punto con 1–2 decimales.
 * Rechaza "ddd", "12a", "NaN", negativos y vacíos.
 */
export function moneySchema(options?: {
  allowZero?: boolean
  message?: string
}) {
  const allowZero = options?.allowZero ?? false
  const message = options?.message ?? MONEY_MSG

  return z
    .string({ error: message })
    .transform((v) => cleanNumericInput(v))
    .superRefine((v, ctx) => {
      if (!/^\d+(\.\d{1,2})?$/.test(v)) {
        ctx.addIssue({ code: 'custom', message })
        return
      }
      const n = Number(v)
      if (!Number.isFinite(n)) {
        ctx.addIssue({ code: 'custom', message })
        return
      }
      if (allowZero ? n < 0 : n <= 0) {
        ctx.addIssue({
          code: 'custom',
          message: allowZero
            ? 'El monto no puede ser negativo'
            : 'El monto debe ser mayor a 0',
        })
      }
    })
}

/** Tipo de cambio USD→MXN: hasta 4 decimales, estrictamente > 0. */
export function exchangeRateSchema(options?: { message?: string }) {
  const message = options?.message ?? FX_MSG

  return z
    .string({ error: message })
    .transform((v) => cleanNumericInput(v))
    .superRefine((v, ctx) => {
      if (!/^\d+(\.\d{1,4})?$/.test(v)) {
        ctx.addIssue({ code: 'custom', message })
        return
      }
      if (!(Number(v) > 0)) {
        ctx.addIssue({ code: 'custom', message: 'El tipo de cambio debe ser mayor a 0' })
      }
    })
}

/** Entero ≥ 1 (cantidades, package_factor, usos). */
export function positiveIntSchema(options?: {
  message?: string
  max?: number
  min?: number
}) {
  const message = options?.message ?? POSITIVE_INT_MSG
  const min = options?.min ?? 1
  const max = options?.max

  return z
    .string({ error: message })
    .transform((v) => cleanNumericInput(v))
    .superRefine((v, ctx) => {
      if (!/^\d+$/.test(v)) {
        ctx.addIssue({ code: 'custom', message })
        return
      }
      const n = Number(v)
      if (n < min) {
        ctx.addIssue({ code: 'custom', message })
        return
      }
      if (max != null && n > max) {
        ctx.addIssue({
          code: 'custom',
          message: `El valor no puede ser mayor a ${max}`,
        })
      }
    })
}

/** Entero ≥ 0 (días de crédito, máximos opcionales). */
export function nonNegativeIntSchema(options?: {
  message?: string
  max?: number
}) {
  const message = options?.message ?? INT_MSG
  const max = options?.max

  return z
    .string({ error: message })
    .transform((v) => cleanNumericInput(v))
    .superRefine((v, ctx) => {
      if (!/^\d+$/.test(v)) {
        ctx.addIssue({ code: 'custom', message })
        return
      }
      const n = Number(v)
      if (n < 0) {
        ctx.addIssue({ code: 'custom', message })
        return
      }
      if (max != null && n > max) {
        ctx.addIssue({
          code: 'custom',
          message: `El valor no puede ser mayor a ${max}`,
        })
      }
    })
}

/**
 * Sanitiza texto al teclear según modo numérico (AppInput.numericMode).
 * Permite un solo punto decimal y hasta `maxDecimals` dígitos fraccionarios.
 */
export function sanitizeNumericTyping(
  raw: string,
  mode: 'decimal' | 'integer' | 'decimal4',
): string {
  const maxDecimals = mode === 'integer' ? 0 : mode === 'decimal4' ? 4 : 2
  let cleaned = raw.replace(/[^\d.]/g, '')

  if (maxDecimals === 0) {
    return cleaned.replace(/\./g, '')
  }

  const firstDot = cleaned.indexOf('.')
  if (firstDot === -1) {
    return cleaned
  }

  const intPart = cleaned.slice(0, firstDot).replace(/\./g, '')
  const fracPart = cleaned
    .slice(firstDot + 1)
    .replace(/\./g, '')
    .slice(0, maxDecimals)

  return `${intPart}.${fracPart}`
}
