import { Receipt, RefreshCcw, Send, ShieldAlert, type LucideIcon } from 'lucide-react'
import type { AppBadgeTone } from '@/components/ui/AppBadge'
import type { JobName, JobRun, JobRunStatus, JobTriggerSource } from '@/types/externalServices'

export type JobMeta = {
  title: string
  description: string
  icon: LucideIcon
  /** Campo de `jobs_outbox.config` con la hora programada (si aplica). */
  scheduleField?: 'daily_billing_hour' | 'daily_collections_hour'
}

/** Orden operativo oficial: facturar → reiniciar bolsas → cobranza → despachar outbox. */
export const JOB_ORDER: JobName[] = [
  'daily_billing',
  'reset_included_balances',
  'daily_collections',
  'process_outbox',
]

export const JOB_META: Record<JobName, JobMeta> = {
  daily_billing: {
    title: 'Facturación diaria por aniversario',
    description:
      'Emite la factura recurrente con IVA 16% de cada contrato que llega a su corte y encola la prefactura al ERP del Emisor.',
    icon: Receipt,
    scheduleField: 'daily_billing_hour',
  },
  reset_included_balances: {
    title: 'Reinicio de bolsas incluidas',
    description:
      'Reinicia el consumo de bolsas vencidas y vuelve a otorgar unidades según las reglas de beneficio vigentes.',
    icon: RefreshCcw,
  },
  daily_collections: {
    title: 'Cobranza y suspensión 90d',
    description:
      'Recalcula avisos 30/60/90, suspende contratos con licencias a +90 días y reactiva los que ya liquidaron.',
    icon: ShieldAlert,
    scheduleField: 'daily_collections_hour',
  },
  process_outbox: {
    title: 'Worker de Outbox',
    description: 'Despacha hasta 50 mensajes pendientes de la cola con reintentos y espera progresiva.',
    icon: Send,
  },
}

export const JOB_STATUS_META: Record<JobRunStatus, { label: string; tone: AppBadgeTone }> = {
  running: { label: 'En ejecución', tone: 'info' },
  completed: { label: 'Completado', tone: 'success' },
  failed: { label: 'Fallido', tone: 'danger' },
}

export const JOB_TRIGGER_LABELS: Record<JobTriggerSource, string> = {
  cloud_scheduler: 'Cloud Scheduler',
  cli: 'Cloud Run (CLI)',
  manual_ui: 'Manual (UI)',
}

function count(summary: Record<string, unknown> | null, key: string): number {
  const value = summary?.[key]
  return typeof value === 'number' ? value : Number(value ?? 0) || 0
}

/** Resumen legible de una corrida para toasts y tarjetas. */
export function describeJobRun(run: JobRun): string {
  const s = run.summary
  switch (run.job_name) {
    case 'daily_billing':
      return `${count(s, 'invoices_created')} factura(s) emitida(s) · ${count(s, 'contracts_evaluated')} contrato(s) evaluado(s) · ${count(s, 'duplicates_skipped')} duplicado(s) omitido(s)`
    case 'reset_included_balances':
      return `${count(s, 'balances_reset')} de ${count(s, 'balances_evaluated')} bolsa(s) reiniciada(s)`
    case 'daily_collections':
      return `${count(s, 'evaluated_contracts')} contrato(s) evaluado(s) · ${count(s, 'suspended_count')} suspendido(s) · ${count(s, 'reactivated_count')} reactivado(s)`
    case 'process_outbox':
      return `${count(s, 'processed')} procesado(s) · ${count(s, 'sent')} enviado(s) · ${count(s, 'failed')} fallido(s)`
    default:
      return `${run.processed_count} procesado(s)`
  }
}

/** `65` → `1 min 05 s`; null → `—`. */
export function formatDuration(seconds: number | null): string {
  if (seconds === null) return '—'
  if (seconds < 60) return `${seconds} s`
  const minutes = Math.floor(seconds / 60)
  const rest = String(seconds % 60).padStart(2, '0')
  return `${minutes} min ${rest} s`
}
