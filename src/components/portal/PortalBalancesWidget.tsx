import { Gauge } from 'lucide-react'
import { PORTAL_CARD_CLASS } from '@/components/portal/portalTokens'
import { AppCard, AppCardDescription, AppCardTitle } from '@/components/ui/AppCard'
import { bucketLabel, portalDate } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import type { PortalBalance } from '@/types/portal'

type PortalBalancesWidgetProps = { balances: PortalBalance[] }

/** Color de la barra según lo que queda: verde > 40 %, ámbar > 15 %, rojo si casi se agota. */
function barTone(available: number, granted: number): string {
  if (granted <= 0) return 'bg-slate-300 dark:bg-white/20'
  const ratio = available / granted
  if (ratio > 0.4) return 'bg-emerald-500'
  if (ratio > 0.15) return 'bg-amber-500'
  return 'bg-red-500'
}

/** Widget Bento de saldos: unidades disponibles por servicio y tipo de bolsa. */
export function PortalBalancesWidget({ balances }: PortalBalancesWidgetProps) {
  return (
    <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-4 p-5')}>
      <div className="flex items-center gap-3">
        <span className="flex h-10 w-10 items-center justify-center rounded-full bg-[#0066FF]/10 text-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]">
          <Gauge className="h-5 w-5" aria-hidden />
        </span>
        <div>
          <AppCardTitle>Mis saldos</AppCardTitle>
          <AppCardDescription>Lo que te queda por usar</AppCardDescription>
        </div>
      </div>

      {balances.length === 0 ? (
        <p className="rounded-2xl bg-slate-50 px-4 py-5 text-center text-sm text-slate-500 dark:bg-white/[0.04] dark:text-zinc-400">
          Tu plan no incluye bolsas de consumo.
        </p>
      ) : (
        <ul className="space-y-4">
          {balances.map((balance) => {
            const percent =
              balance.granted_units > 0
                ? Math.min(100, Math.max(0, Math.round((balance.available_units / balance.granted_units) * 100)))
                : 0
            return (
              <li key={balance.id} className="space-y-1.5">
                <div className="flex items-baseline justify-between gap-3">
                  <p className="truncate text-sm font-medium">{balance.service_name || balance.service_code}</p>
                  <p className="shrink-0 font-mono text-sm tabular-nums">
                    <span className="font-semibold">{balance.available_units.toLocaleString('es-MX')}</span>
                    <span className="text-slate-400 dark:text-zinc-500">
                      {' '}
                      / {balance.granted_units.toLocaleString('es-MX')}
                    </span>
                  </p>
                </div>
                <div
                  className="h-2 overflow-hidden rounded-full bg-slate-200/80 dark:bg-white/[0.08]"
                  role="progressbar"
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-valuenow={percent}
                  aria-label={`Disponible de ${balance.service_name}`}
                >
                  <div
                    className={cn('h-full rounded-full transition-all', barTone(balance.available_units, balance.granted_units))}
                    style={{ width: `${percent}%` }}
                  />
                </div>
                <p className="text-xs text-slate-500 dark:text-zinc-400">
                  {bucketLabel(balance.bucket)}
                  {balance.period_end ? ` · se renueva el ${portalDate(balance.period_end)}` : ''}
                </p>
              </li>
            )
          })}
        </ul>
      )}
    </AppCard>
  )
}
