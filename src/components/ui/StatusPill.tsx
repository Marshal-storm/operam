import { cn } from '@/lib/utils'

type StatusPillProps = {
  label: string
  tone?: 'positive' | 'negative' | 'warning' | 'info' | 'neutral'
  className?: string
}

/** Semáforo vivo Operam — píldoras + punto luminoso ● */
const TONE_STYLES: Record<NonNullable<StatusPillProps['tone']>, string> = {
  positive:
    'border-emerald-500/30 bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
  negative: 'border-red-500/30 bg-red-500/15 text-red-700 dark:text-red-400',
  warning: 'border-amber-500/30 bg-amber-500/15 text-amber-700 dark:text-amber-400',
  info: 'border-blue-500/30 bg-blue-500/15 text-blue-700 dark:text-blue-400',
  neutral:
    'border-slate-300/70 bg-slate-100 text-slate-600 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-zinc-300',
}

const DOT_STYLES: Record<NonNullable<StatusPillProps['tone']>, string> = {
  positive: 'bg-emerald-500 shadow-[0_0_8px_#10B981]',
  negative: 'bg-red-500 shadow-[0_0_8px_#EF4444]',
  warning: 'bg-amber-500 shadow-[0_0_8px_#F59E0B]',
  info: 'bg-[#0A84FF] shadow-[0_0_8px_#0A84FF]',
  neutral: 'bg-slate-400',
}

/**
 * Píldora de estado iOS: rounded-full + punto luminoso.
 */
export function StatusPill({ label, tone = 'neutral', className }: StatusPillProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium',
        TONE_STYLES[tone],
        className,
      )}
    >
      <span
        className={cn('inline-block h-2 w-2 shrink-0 rounded-full', DOT_STYLES[tone])}
        aria-hidden
      />
      {label}
    </span>
  )
}
