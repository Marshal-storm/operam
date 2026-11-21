import { cn } from '@/lib/utils'

export type AppBadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'slate'

export type AppBadgeProps = {
  label: string
  /** `tone` oficial; `variant` es alias semántico (p. ej. variant="neutral"). */
  tone?: AppBadgeTone
  variant?: AppBadgeTone
  className?: string
}

/** Estilos de píldora semáforo (Single Source of Truth). */
const TONE_STYLES: Record<AppBadgeTone, string> = {
  success:
    'border-emerald-500/30 bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
  warning: 'border-amber-500/30 bg-amber-500/15 text-amber-700 dark:text-amber-400',
  danger: 'border-red-500/30 bg-red-500/15 text-red-700 dark:text-red-400',
  info: 'border-blue-500/30 bg-blue-500/15 text-blue-700 dark:text-blue-400',
  neutral:
    'border-slate-300/70 bg-slate-100 text-slate-600 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-zinc-300',
  /** Alias elegante informativo (crédito, moneda, metadatos). */
  slate:
    'border-slate-300/70 bg-slate-100/90 text-slate-600 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-zinc-300',
}

const DOT_STYLES: Record<AppBadgeTone, string> = {
  success: 'bg-emerald-500 shadow-[0_0_8px_#10B981]',
  warning: 'bg-amber-500 shadow-[0_0_8px_#F59E0B]',
  danger: 'bg-red-500 shadow-[0_0_8px_#EF4444]',
  info: 'bg-[#0A84FF] shadow-[0_0_8px_#0A84FF]',
  neutral: 'bg-slate-400',
  slate: 'bg-slate-400',
}

/**
 * Badge atómico — píldora de estado en una sola línea (`whitespace-nowrap`).
 */
export function AppBadge({
  label,
  tone,
  variant,
  className,
}: AppBadgeProps) {
  const resolved: AppBadgeTone = tone ?? variant ?? 'neutral'

  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-1 text-xs font-medium',
        TONE_STYLES[resolved],
        className,
      )}
    >
      <span
        className={cn('inline-block h-1.5 w-1.5 shrink-0 rounded-full', DOT_STYLES[resolved])}
        aria-hidden
      />
      {label}
    </span>
  )
}

/** Alias de compatibilidad con el nombre histórico StatusPill. */
export function StatusPill({
  label,
  tone = 'neutral',
  className,
}: {
  label: string
  tone?: 'positive' | 'negative' | 'warning' | 'info' | 'neutral'
  className?: string
}) {
  const mapped: AppBadgeTone =
    tone === 'positive' ? 'success' : tone === 'negative' ? 'danger' : tone
  return <AppBadge label={label} tone={mapped} className={className} />
}
