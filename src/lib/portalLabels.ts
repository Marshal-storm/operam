import type { AppBadgeTone } from '@/components/ui/AppBadge'
import { formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import type {
  PortalInvoiceStatus,
  PortalRequest,
  PortalRequestStatus,
  PortalRequestType,
} from '@/types/portal'

/** Etiquetas es-MX del Portal Cliente (valores de BD en inglés → texto visible). */

type LabeledTone = { label: string; tone: AppBadgeTone }

const INVOICE_STATUS: Record<PortalInvoiceStatus, LabeledTone> = {
  pending: { label: 'Pendiente', tone: 'warning' },
  partial: { label: 'Pago parcial', tone: 'warning' },
  paid: { label: 'Pagada', tone: 'success' },
  overdue: { label: 'Vencida', tone: 'danger' },
  cancelled: { label: 'Cancelada', tone: 'neutral' },
}

export function invoiceStatusBadge(status: string): LabeledTone {
  return INVOICE_STATUS[status as PortalInvoiceStatus] ?? { label: status, tone: 'neutral' }
}

const CONTRACT_STATUS: Record<string, LabeledTone> = {
  active: { label: 'Activo', tone: 'success' },
  suspended: { label: 'Suspendido', tone: 'warning' },
  cancelled: { label: 'Cancelado', tone: 'danger' },
  expired: { label: 'Vencido', tone: 'neutral' },
}

export function contractStatusBadge(status: string): LabeledTone {
  return CONTRACT_STATUS[status] ?? { label: status, tone: 'neutral' }
}

const FREQUENCY: Record<string, string> = {
  monthly: 'Mensual',
  bimonthly: 'Bimestral',
  quarterly: 'Trimestral',
  semiannual: 'Semestral',
  annual: 'Anual',
  single: 'Pago único',
}

export function frequencyLabel(frequency: string): string {
  return FREQUENCY[frequency] ?? frequency
}

const REQUEST_TYPE: Record<string, string> = {
  one_time: 'Compra única',
  consumption: 'Bolsa de consumo',
  recurring_expansion: 'Ampliación de plan',
  plan_change: 'Cambio de plan',
}

export function requestTypeLabel(type: string): string {
  return REQUEST_TYPE[type] ?? type
}

/** Tipos que el cliente elige en "Nueva solicitud" (RF-8.1). */
export const PORTAL_REQUEST_TYPE_LABEL: Record<PortalRequestType, string> = {
  add_users: 'Agregar usuarios',
  remove_users: 'Dar de baja usuarios',
  change_frequency: 'Cambiar frecuencia de pago',
  cancel_service: 'Cancelar un servicio',
  extra_service: 'Servicio extra',
}

/** Título de la solicitud: la intención del portal o, si es interna, su tipo. */
export function portalRequestTitle(request: Pick<PortalRequest, 'portal_request_type' | 'request_type'>): string {
  return request.portal_request_type
    ? PORTAL_REQUEST_TYPE_LABEL[request.portal_request_type]
    : requestTypeLabel(request.request_type)
}

const PORTAL_REQUEST_STATUS: Record<PortalRequestStatus, LabeledTone> = {
  received: { label: 'Recibida', tone: 'info' },
  approved: { label: 'Aprobada', tone: 'success' },
  executed: { label: 'Ejecutada', tone: 'success' },
  rejected: { label: 'Rechazada', tone: 'danger' },
  cancelled: { label: 'Cancelada', tone: 'neutral' },
}

export function portalRequestStatusBadge(status: PortalRequestStatus): LabeledTone {
  return PORTAL_REQUEST_STATUS[status] ?? { label: status, tone: 'neutral' }
}

const PAYMENT_METHOD: Record<string, string> = {
  transfer: 'Transferencia SPEI',
  card_integropay: 'Tarjeta',
  cash: 'Efectivo',
  check: 'Cheque',
  other: 'Otro',
}

export function paymentMethodLabel(method: string): string {
  return PAYMENT_METHOD[method] ?? method
}

const BUCKET: Record<string, string> = {
  included: 'Incluidas en tu plan',
  prepaid: 'Prepagadas',
}

export function bucketLabel(bucket: string): string {
  return BUCKET[bucket] ?? bucket
}

const SERVICE_UNIT: Record<string, string> = {
  user: 'Usuario',
  pack_5_users: 'Paquete de 5 usuarios',
  stamp: 'Timbre',
  hour: 'Hora',
  license: 'Licencia',
  instance: 'Instancia',
}

export function serviceUnitLabel(unit: string | null): string {
  if (!unit) return ''
  return SERVICE_UNIT[unit] ?? unit
}

const INTEGER_FORMAT = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 })

/** Cantidades enteras de unidades con separador de miles `es-MX` (1,250). */
export function portalUnits(quantity: number): string {
  return INTEGER_FORMAT.format(quantity)
}

/** Porcentaje decimal de la API sin ceros sobrantes: `"25.00"` → `"25"`, `"12.50"` → `"12.5"`. */
export function portalPercent(value: string): string {
  return value.includes('.') ? value.replace(/\.?0+$/, '') : value
}

/** Monto es-MX con 2 decimales; ante un valor inválido devuelve "—" en vez de romper la UI. */
export function portalMoney(amount: string | number | null | undefined): string {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return formatCurrencyMx(amount, { fractionDigits: 2 })
  } catch {
    return '—'
  }
}

/** Fecha `15 sep 2026` (America/Mexico_City); ante un valor inválido devuelve "—". */
export function portalDate(value: string | null | undefined): string {
  if (!value) return '—'
  try {
    return formatDateEsMx(value)
  } catch {
    return '—'
  }
}

/** Iniciales (máx. 2) para el avatar del encabezado. */
export function initialsOf(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  const first = parts[0]?.[0] ?? ''
  const second = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : ''
  return (first + second).toUpperCase()
}

/** Primer nombre para el saludo ("Hola, Ana"). */
export function firstNameOf(name: string): string {
  return name.trim().split(/\s+/)[0] ?? name
}
