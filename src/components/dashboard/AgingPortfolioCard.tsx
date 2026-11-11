import { Link } from 'react-router-dom'
import { formatCurrencyMx } from '@/lib/utils'
import type { AgingBucket, AgingBucketLabel } from '@/types/mrr'

type AgingPortfolioCardProps = {
  buckets: AgingBucket[]
}

const AGING_COLORS: Record<AgingBucketLabel, string> = {
  'Por vencer': '#0A84FF',
  '1-30 días': '#22D3EE',
  '31-60 días': '#F59E0B',
  '61-90 días': '#F97316',
  'Suspendidos (+90)': '#EF4444',
}

/**
 * Cartera por antigüedad — barras luminosas Liquid Glass.
 */
export function AgingPortfolioCard({ buckets }: AgingPortfolioCardProps) {
  const maxAmount = Math.max(...buckets.map((bucket) => bucket.amountMxn), 1)

  return (
    <section className="glass-panel flex h-full flex-col p-4 md:p-5">
      <header className="mb-4">
        <h2 className="text-base font-semibold md:text-lg">Cartera por antigüedad</h2>
      </header>

      <ul className="flex flex-1 flex-col gap-3">
        {buckets.map((bucket) => {
          const widthPercent = Math.trunc((bucket.amountMxn * 100) / maxAmount)
          const color = AGING_COLORS[bucket.label] ?? bucket.color

          return (
            <li key={bucket.label} className="space-y-1.5">
              <div className="flex items-center justify-between gap-3 text-sm">
                <span className="text-slate-600 dark:text-slate-300">{bucket.label}</span>
                <span className="amount-tabular font-medium text-[#090D16] dark:text-white">
                  {formatCurrencyMx(bucket.amountMxn)}
                </span>
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-slate-200/80 dark:bg-white/[0.06]">
                <div
                  className="h-full rounded-full transition-[width]"
                  style={{
                    width: `${widthPercent}%`,
                    backgroundColor: color,
                    boxShadow: `0 0 12px ${color}66`,
                  }}
                />
              </div>
            </li>
          )
        })}
      </ul>

      <div className="mt-5 border-t border-slate-200/80 pt-3 dark:border-white/[0.07]">
        <Link
          to="/cobranza"
          className="text-sm font-medium text-[#0066FF] hover:underline dark:text-[#0A84FF]"
        >
          Ver cobranza
        </Link>
      </div>
    </section>
  )
}
