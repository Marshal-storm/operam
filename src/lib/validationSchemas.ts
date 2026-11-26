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
 * RNF-8 — nombre de variable del cofre de secretos (`SMTP_PASSWORD_REF`), nunca el secreto.
 * Solo MAYÚSCULAS, dígitos y guion bajo; vacío permitido si `optional`.
 */
export function secretRefNameSchema(options?: { optional?: boolean }) {
  const message =
    'Usa el nombre de la variable (A-Z, 0-9 y _), nunca la contraseña o token en texto plano'
  return z
    .string()
    .trim()
    .superRefine((v, ctx) => {
      if (v === '') {
        if (!options?.optional) ctx.addIssue({ code: 'custom', message: 'La referencia es obligatoria' })
        return
      }
      if (!/^[A-Z][A-Z0-9_]{2,99}$/.test(v)) {
        ctx.addIssue({ code: 'custom', message })
      }
    })
}

/** URL http(s) opcional (vacío = sin configurar). */
export function optionalHttpUrlSchema(options?: { message?: string }) {
  const message = options?.message ?? 'Ingresa una URL válida que inicie con http:// o https://'
  return z
    .string()
    .trim()
    .superRefine((v, ctx) => {
      if (v === '') return
      try {
        const parsed = new URL(v)
        if (!['http:', 'https:'].includes(parsed.protocol)) {
          ctx.addIssue({ code: 'custom', message })
        }
      } catch {
        ctx.addIssue({ code: 'custom', message })
      }
    })
}

/** Hora del día en formato 24 h `HH:MM`. */
export function timeOfDaySchema(options?: { message?: string }) {
  return z
    .string()
    .trim()
    .regex(/^([01]\d|2[0-3]):[0-5]\d$/, options?.message ?? 'Usa formato HH:MM (24 h)')
}

const PERCENTAGE_MSG = 'Ingresa un porcentaje válido con hasta 2 decimales (ej. 4.50)'

/**
 * Porcentaje positivo con hasta 2 decimales, > 0 y ≤ `max` (el signo se elige aparte).
 */
export function adjustmentPercentageSchema(options?: { max?: number; message?: string }) {
  const message = options?.message ?? PERCENTAGE_MSG
  const max = options?.max ?? 999.99

  return z
    .string({ error: message })
    .transform((v) => cleanNumericInput(v).replace(/%/g, ''))
    .superRefine((v, ctx) => {
      if (!/^\d{1,3}(\.\d{1,2})?$/.test(v)) {
        ctx.addIssue({ code: 'custom', message })
        return
      }
      const n = Number(v)
      if (!(n > 0)) {
        ctx.addIssue({ code: 'custom', message: 'El porcentaje debe ser mayor a 0' })
        return
      }
      if (n > max) {
        ctx.addIssue({ code: 'custom', message: `El porcentaje máximo es ${max}%` })
      }
    })
}

/** Máximo decremento permitido: -100% dejaría precios en cero (backend exige > -100). */
export const BULK_PRICE_MAX_DECREASE = 99.99

/**
 * Ajuste Masivo de Precios (RF-3.6 / UI-9). Filtros opcionales ('all' = sin filtro),
 * dirección + porcentaje positivo y motivo obligatorio (≥ 5 caracteres) para aplicar.
 */
export const bulkPriceAdjustmentSchema = z
  .object({
    segment_id: z.string(),
    issuer_id: z.string(),
    service_id: z.string(),
    anniversary_from: z.string(),
    anniversary_to: z.string(),
    direction: z.enum(['increase', 'decrease']),
    percentage: adjustmentPercentageSchema(),
    reason: z
      .string()
      .trim()
      .min(5, 'El motivo es obligatorio (mínimo 5 caracteres)')
      .max(200, 'Máximo 200 caracteres'),
  })
  .superRefine((values, ctx) => {
    if (values.direction === 'decrease' && Number(values.percentage) > BULK_PRICE_MAX_DECREASE) {
      ctx.addIssue({
        code: 'custom',
        path: ['percentage'],
        message: `El decremento máximo es ${BULK_PRICE_MAX_DECREASE}%`,
      })
    }
    if (values.anniversary_from && values.anniversary_to && values.anniversary_from > values.anniversary_to) {
      ctx.addIssue({
        code: 'custom',
        path: ['anniversary_to'],
        message: 'La fecha final debe ser posterior a la inicial',
      })
    }
  })

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
