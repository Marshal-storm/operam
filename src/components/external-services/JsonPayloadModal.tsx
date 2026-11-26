import type { ReactNode } from 'react'
import { AppButton } from '@/components/ui/AppButton'
import { AppDrawer } from '@/components/ui/AppDrawer'

export type JsonPayloadSection = {
  label: string
  value: unknown
  /** Texto plano alterno (p. ej. `last_error`); se pinta en rojo. */
  errorText?: string | null
}

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: ReactNode
  sections: JsonPayloadSection[]
}

const LABEL_CLASS =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500'

function stringify(value: unknown): string {
  if (value === null || value === undefined) return 'Sin datos'
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

/**
 * Visor de solo lectura en AppDrawer xl para payloads JSON (outbox / webhooks / jobs) en DM Mono.
 */
export function JsonPayloadModal({ open, onOpenChange, title, description, sections }: Props) {
  return (
    <AppDrawer
      open={open}
      onClose={() => onOpenChange(false)}
      size="xl"
      title={title}
      description="Inspector de payload JSON de solo lectura."
      footer={
        <AppButton type="button" variant="outline" onClick={() => onOpenChange(false)}>
          Cerrar
        </AppButton>
      }
    >
      <div className="min-w-0 space-y-4">
        {description ? <div className="min-w-0">{description}</div> : null}
        {sections.map((section) => (
          <div key={section.label} className="min-w-0">
            <span className={LABEL_CLASS}>{section.label}</span>
            {section.errorText ? (
              <p className="mb-2 break-words rounded-xl border border-red-500/20 bg-red-500/[0.06] px-3 py-2 font-mono text-xs text-red-600 dark:text-red-400">
                {section.errorText}
              </p>
            ) : null}
            <pre className="overflow-x-auto rounded-xl border border-slate-200/80 bg-slate-50 p-3.5 font-mono text-xs leading-relaxed text-slate-700 dark:border-white/[0.08] dark:bg-white/[0.03] dark:text-zinc-200">
              {stringify(section.value)}
            </pre>
          </div>
        ))}
      </div>
    </AppDrawer>
  )
}
