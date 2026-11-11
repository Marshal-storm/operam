import { cn } from '@/lib/utils'

type StatusPillProps = {
  label: string
  tone?: 'positive' | 'negative' | 'warning' | 'info' | 'neutral'
  className?: string
}

const TONE_STYLES: Record<NonNullable<StatusPillProps['tone']>, string> = {
  positive:
    'border-emerald-500/30 bg-emerald-500/10 text-emerald-400 dark:text-emerald-300',
  negative: 'border-red-500/30 bg-red-500/10 text-red-500 dark:text-red-400',
  warning: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-300',
  info: 'border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-300',
  neutral:
    'border-slate-300/70 bg-slate-100 text-slate-600 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-zinc-300',
}

const DOT_STYLES: Record<NonNullable<StatusPillProps['tone']>, string> = {
  positive: 'bg-emerald-400 shadow-[0_0_8px_rgba(16,185,129,0.8)]',
  negative: 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.8)]',
  warning: 'bg-amber-400 shadow-[0_0_8px_rgba(245,158,11,0.8)]',
  info: 'bg-blue-500 shadow-[0_0_8px_rgba(10,132,255,0.8)]',
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
      <span className={cn('h-2 w-2 rounded-full', DOT_STYLES[tone])} />
      {label}
    </span>
  )
}
