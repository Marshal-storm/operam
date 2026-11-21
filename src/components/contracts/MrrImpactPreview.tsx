import { ArrowRight } from 'lucide-react'
import { cn, formatCurrencyMx } from '@/lib/utils'

export type MrrImpactPreviewProps = {
  mrrBefore: string
  mrrDelta: string
  mrrAfter: string
  /** Transferencia de emisor: muestra copy de neutralidad. */
  neutral?: boolean
  className?: string
}

/**
 * Caja UI-9: vista previa de impacto MRR antes de confirmar operación.
 */
export function MrrImpactPreview({
  mrrBefore,
  mrrDelta,
  mrrAfter,
  neutral = false,
  className,
}: MrrImpactPreviewProps) {
  const deltaNum = Number(mrrDelta)
  const deltaPositive = deltaNum > 0
  const deltaNegative = deltaNum < 0
  const deltaLabel = neutral
    ? `${formatCurrencyMx('0.00', { fractionDigits: 2 })} — Operación neutral para el MRR`
    : `${deltaPositive ? '+' : ''}${formatCurrencyMx(mrrDelta, { fractionDigits: 2 })}`

  return (
    <div
      className={cn(
        'rounded-2xl border p-4',
        'bg-slate-50/80 border-slate-200/80',
        'dark:bg-white/[0.04] dark:border-white/[0.09]',
        'dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.08)]',
        className,
      )}
    >
      <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">
        Vista previa de impacto en MRR
      </p>
      <div className="mt-3 flex flex-wrap items-center gap-2 sm:gap-3">
        <div className="min-w-0">
          <p className="text-[10px] uppercase tracking-wide text-slate-400">MRR actual</p>
          <p className="font-mono text-sm font-semibold tabular-nums">
            {formatCurrencyMx(mrrBefore, { fractionDigits: 2 })}
          </p>
        </div>
        <ArrowRight className="h-4 w-4 shrink-0 text-slate-400" aria-hidden />
        <div className="min-w-0">
          <p className="text-[10px] uppercase tracking-wide text-slate-400">Delta MRR</p>
          <p
            className={cn(
              'font-mono text-sm font-semibold tabular-nums',
              neutral && 'text-slate-600 dark:text-zinc-300',
              !neutral && deltaPositive && 'text-emerald-600 dark:text-emerald-400',
              !neutral && deltaNegative && 'text-red-600 dark:text-red-400',
              !neutral && !deltaPositive && !deltaNegative && 'text-slate-600 dark:text-zinc-300',
            )}
          >
            {deltaLabel}
          </p>
        </div>
        <ArrowRight className="h-4 w-4 shrink-0 text-slate-400" aria-hidden />
        <div className="min-w-0">
          <p className="text-[10px] uppercase tracking-wide text-slate-400">Nuevo MRR</p>
          <p className="font-mono text-sm font-semibold tabular-nums">
            {formatCurrencyMx(mrrAfter, { fractionDigits: 2 })}
          </p>
        </div>
      </div>
    </div>
  )
}
