import { Clock, CreditCard, Landmark, Mail, MessageSquareWarning, Server, type LucideIcon } from 'lucide-react'
import { z } from 'zod'
import type { AppSelectTone } from '@/components/ui/AppSelect'
import {
  optionalHttpUrlSchema,
  positiveIntSchema,
  secretRefNameSchema,
  timeOfDaySchema,
} from '@/lib/validationSchemas'
import type {
  ExternalServiceKey,
  IncomingEventOrigin,
  OutboxDestination,
  OutboxStatus,
} from '@/types/externalServices'

/** Tipos de control del formulario de configuración. */
export type ConfigFieldKind = 'text' | 'email' | 'url' | 'int' | 'time' | 'select'

export type ConfigFieldDef = {
  key: string
  label: string
  kind: ConfigFieldKind
  placeholder?: string
  min?: number
  max?: number
  options?: Array<{ value: string; label: string }>
  /** Ocupa las dos columnas del grid del modal. */
  wide?: boolean
}

export type SecretFieldDef = {
  key: string
  label: string
  placeholder: string
}

export type ExternalServiceMeta = {
  shortName: string
  description: string
  icon: LucideIcon
  fields: ConfigFieldDef[]
  secrets: SecretFieldDef[]
}

/**
 * Metadata visual + esquema de captura por servicio.
 * Las llaves coinciden 1:1 con `ExternalServiceRegistry::DEFINITIONS` del backend.
 */
export const EXTERNAL_SERVICE_META: Record<ExternalServiceKey, ExternalServiceMeta> = {
  smtp: {
    shortName: 'Correo SMTP',
    description: 'Envío de avisos de cobranza, Magic Links y notificaciones por correo.',
    icon: Mail,
    fields: [
      { key: 'host', label: 'Servidor (host)', kind: 'text', placeholder: 'smtp.proveedor.com', wide: true },
      { key: 'port', label: 'Puerto', kind: 'int', min: 1, max: 65535, placeholder: '587' },
      {
        key: 'encryption',
        label: 'Cifrado',
        kind: 'select',
        options: [
          { value: 'tls', label: 'STARTTLS (587)' },
          { value: 'ssl', label: 'SSL/TLS implícito (465)' },
          { value: 'none', label: 'Sin cifrado' },
        ],
      },
      { key: 'username', label: 'Usuario SMTP', kind: 'text', placeholder: 'usuario@dominio.com' },
      { key: 'provider', label: 'Proveedor', kind: 'text', placeholder: 'smtp' },
      { key: 'from_email', label: 'Remitente (From)', kind: 'email', placeholder: 'cobranza@operam.mx' },
      { key: 'from_name', label: 'Nombre del remitente', kind: 'text', placeholder: 'Operam Suscripciones' },
    ],
    secrets: [{ key: 'password_ref', label: 'Referencia de contraseña', placeholder: 'SMTP_PASSWORD_REF' }],
  },
  erp_master: {
    shortName: 'ERP Master',
    description: 'Control de instancias: ajuste de usuarios, suspensión y reactivación.',
    icon: Server,
    fields: [
      { key: 'base_url', label: 'URL base', kind: 'url', placeholder: 'https://erp-master.ejemplo.com/api', wide: true },
      { key: 'timeout_seconds', label: 'Timeout HTTP (segundos)', kind: 'int', min: 1, max: 120, placeholder: '15' },
    ],
    secrets: [{ key: 'api_key_ref', label: 'Referencia de API key', placeholder: 'ERP_MASTER_API_KEY_REF' }],
  },
  slack: {
    shortName: 'Alertas Slack',
    description: 'Alerta única cuando un mensaje del outbox alcanza el umbral de fallos (RNF-12).',
    icon: MessageSquareWarning,
    fields: [
      { key: 'channel_label', label: 'Canal (referencia visual)', kind: 'text', placeholder: '#alertas-suscripciones' },
      { key: 'failure_threshold', label: 'Umbral de fallos', kind: 'int', min: 1, max: 10, placeholder: '3' },
    ],
    secrets: [
      { key: 'webhook_url_ref', label: 'Referencia del webhook', placeholder: 'SLACK_OUTBOX_WEBHOOK_REF' },
    ],
  },
  banxico: {
    shortName: 'Banxico SIE',
    description: 'Tipo de cambio FIX diario USD/MXN; la prueba en producción lo guarda en exchange_rates.',
    icon: Landmark,
    fields: [
      { key: 'api_url', label: 'URL de la serie', kind: 'url', wide: true },
      { key: 'series_id', label: 'Serie', kind: 'text', placeholder: 'SF43718' },
    ],
    secrets: [{ key: 'token_ref', label: 'Referencia del token SIE', placeholder: 'BANXICO_SIE_TOKEN_REF' }],
  },
  jobs_outbox: {
    shortName: 'Jobs y Outbox',
    description:
      'Worker de la cola outbox (GCP). En producción habilita el envío real al ERP de cada Emisor.',
    icon: Clock,
    fields: [
      { key: 'timezone', label: 'Zona horaria', kind: 'text', placeholder: 'America/Mexico_City' },
      { key: 'max_attempts', label: 'Intentos máximos', kind: 'int', min: 1, max: 10, placeholder: '3' },
      {
        key: 'retry_backoff_minutes',
        label: 'Espera entre reintentos (min)',
        kind: 'int',
        min: 1,
        max: 1440,
        placeholder: '5',
      },
      { key: 'daily_billing_hour', label: 'Hora de facturación diaria', kind: 'time', placeholder: '06:00' },
      { key: 'daily_collections_hour', label: 'Hora de cobranza diaria', kind: 'time', placeholder: '07:00' },
    ],
    secrets: [{ key: 'job_secret_ref', label: 'Referencia del secreto del job', placeholder: 'GCP_JOB_SECRET_REF' }],
  },
  integropay: {
    shortName: 'IntegroPAY',
    description:
      'Ligas de pago con tarjeta del Portal Cliente. Las credenciales (merchant, device y webhook) se configuran por Emisor.',
    icon: CreditCard,
    fields: [
      { key: 'api_url', label: 'URL base de la API', kind: 'url', placeholder: 'https://api.integropay.mx/v1', wide: true },
      { key: 'timeout_seconds', label: 'Timeout HTTP (segundos)', kind: 'int', min: 1, max: 120, placeholder: '20' },
      { key: 'link_ttl_hours', label: 'Vigencia de la liga (horas)', kind: 'int', min: 1, max: 168, placeholder: '24' },
    ],
    secrets: [],
  },
}

export type ExternalServiceFormValues = {
  display_name: string
  enabled: boolean
  mode: 'sandbox' | 'production'
  config: Record<string, string>
  secret_refs: Record<string, string>
}

/** Esquema Zod del modal según el servicio (validación previa a cualquier HTTP). */
export function buildExternalServiceSchema(serviceKey: ExternalServiceKey) {
  const meta = EXTERNAL_SERVICE_META[serviceKey]
  const configShape: Record<string, z.ZodType<string>> = {}
  for (const field of meta.fields) {
    configShape[field.key] = fieldSchema(field)
  }
  const secretShape: Record<string, z.ZodType<string>> = {}
  for (const secret of meta.secrets) {
    secretShape[secret.key] = secretRefNameSchema({ optional: true })
  }

  return z.object({
    display_name: z.string().trim().min(3, 'Nombre obligatorio').max(100, 'Máximo 100 caracteres'),
    enabled: z.boolean(),
    mode: z.enum(['sandbox', 'production']),
    config: z.object(configShape),
    secret_refs: z.object(secretShape),
  })
}

function fieldSchema(field: ConfigFieldDef): z.ZodType<string> {
  switch (field.kind) {
    case 'int':
      return positiveIntSchema({ min: field.min, max: field.max })
    case 'url':
      return optionalHttpUrlSchema()
    case 'email':
      return z.string().trim().email('Ingresa un correo válido')
    case 'time':
      return timeOfDaySchema()
    case 'select':
      return z.string().min(1, 'Selecciona una opción')
    default:
      return z.string().trim().max(500, 'Máximo 500 caracteres')
  }
}

export const DESTINATION_LABELS: Record<OutboxDestination, string> = {
  erp_issuer: 'ERP Emisor',
  erp_master: 'ERP Master',
  payment_gateway: 'IntegroPAY',
  smtp: 'Correo SMTP',
  slack: 'Slack',
}

export const OUTBOX_STATUS_META: Record<OutboxStatus, { label: string; tone: AppSelectTone }> = {
  pending: { label: 'Pendiente', tone: 'warning' },
  processing: { label: 'Procesando', tone: 'info' },
  sent: { label: 'Enviado', tone: 'success' },
  failed: { label: 'Fallido', tone: 'danger' },
}

export const OPERATION_LABELS: Record<string, string> = {
  create_preinvoice: 'Crear prefactura',
  stamp_cfdi: 'Timbrar CFDI',
  suspend_instance: 'Suspender instancia',
  reactivate_instance: 'Reactivar instancia',
  adjust_users: 'Ajustar usuarios',
  send_email: 'Enviar correo',
}

export const ORIGIN_LABELS: Record<IncomingEventOrigin, string> = {
  erp_issuer: 'ERP Emisor',
  integropay: 'IntegroPAY',
  erp_master: 'ERP Master',
}
