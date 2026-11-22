import { cn, formatCurrencyMx } from '@/lib/utils'

export type RequestImpactPreviewProps = {
  requestTotal: string
  mrrImpact: string
  /** true = cargo único/consumo (no toca contrato/MRR). */
  noMrrImpact: boolean
  rf2Applied?: boolean
  rf2Savings?: string
  className?: string
}

/**
 * Caja UI-9: vista previa Total vs Impacto MRR antes de crear/aprobar.
 */
export function RequestImpactPreview({
  requestTotal,
  mrrImpact,
  noMrrImpact,
  rf2Applied = false,
  rf2Savings = '0.00',
  className,
}: RequestImpactPreviewProps) {
  const mrrNum = Number(mrrImpact)

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
        Vista previa de impacto
      </p>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
          <p className="text-[10px] uppercase tracking-wide text-slate-400">
            Total de la solicitud
          </p>
          <p className="font-mono text-lg font-semibold tabular-nums">
            {formatCurrencyMx(requestTotal, { fractionDigits: 2 })}
          </p>
        </div>
        <div>
          <p className="text-[10px] uppercase tracking-wide text-slate-400">
            Impacto en MRR
          </p>
          {noMrrImpact || mrrNum <= 0 ? (
            <p className="text-sm font-medium text-slate-600 dark:text-zinc-300">
              $0.00 — No suma al MRR
            </p>
          ) : (
            <p className="font-mono text-lg font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
              +{formatCurrencyMx(mrrImpact, { fractionDigits: 2 })} / mes
            </p>
          )}
        </div>
      </div>
      {noMrrImpact ? (
        <p className="mt-3 text-xs text-slate-500 dark:text-zinc-400">
          Al aprobar, el cargo queda listo para facturación/cobranza sin modificar el
          contrato ni el MRR.
        </p>
      ) : (
        <p className="mt-3 text-xs text-slate-500 dark:text-zinc-400">
          Al aprobar se expandirá el contrato en{' '}
          <span className="font-mono tabular-nums font-semibold text-emerald-600 dark:text-emerald-400">
            +{formatCurrencyMx(mrrImpact, { fractionDigits: 2 })} / mes
          </span>
          {rf2Applied && Number(rf2Savings) > 0
            ? ` (conversión RF-2 aplicada, ahorro ${formatCurrencyMx(rf2Savings, { fractionDigits: 2 })})`
            : ''}
          .
        </p>
      )}
    </div>
  )
}
