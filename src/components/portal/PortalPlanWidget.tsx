import { Layers, Users } from 'lucide-react'
import { PORTAL_CARD_CLASS } from '@/components/portal/portalTokens'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppCard, AppCardDescription, AppCardTitle } from '@/components/ui/AppCard'
import { frequencyLabel, portalMoney } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import type { PortalContract } from '@/types/portal'

type PortalPlanWidgetProps = {
  contract: PortalContract
  /** Máximo de líneas visibles (el resto se resume). */
  maxLines?: number
}

/** Widget Bento del plan: servicios contratados y usuarios concurrentes. */
export function PortalPlanWidget({ contract, maxLines = 4 }: PortalPlanWidgetProps) {
  const visible = contract.lines.slice(0, maxLines)
  const hidden = contract.lines.length - visible.length

  return (
    <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-4 p-5')}>
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-full bg-[#7A3FC4]/10 text-[#7A3FC4] dark:bg-[#7A3FC4]/20 dark:text-[#B38AF0]">
            <Layers className="h-5 w-5" aria-hidden />
          </span>
          <div>
            <AppCardTitle>Mi plan</AppCardTitle>
            <AppCardDescription>{contract.issuer.name}</AppCardDescription>
          </div>
        </div>
        <AppBadge label={frequencyLabel(contract.frequency)} tone="info" />
      </div>

      {contract.concurrent_users > 0 ? (
        <div className="flex items-center gap-3 rounded-2xl bg-slate-50 px-4 py-3 dark:bg-white/[0.04]">
          <Users className="h-5 w-5 shrink-0 text-slate-400 dark:text-zinc-500" aria-hidden />
          <p className="text-sm text-slate-600 dark:text-zinc-300">
            <span className="font-heading text-lg font-bold tabular-nums text-slate-900 dark:text-zinc-50">
              {contract.concurrent_users.toLocaleString('es-MX')}
            </span>{' '}
            usuarios concurrentes
          </p>
        </div>
      ) : null}

      {visible.length === 0 ? (
        <p className="rounded-2xl bg-slate-50 px-4 py-5 text-center text-sm text-slate-500 dark:bg-white/[0.04] dark:text-zinc-400">
          Aún no hay servicios vigentes en este contrato.
        </p>
      ) : (
        <ul className="divide-y divide-slate-200/70 dark:divide-white/[0.07]">
          {visible.map((line) => (
            <li key={line.id} className="flex items-start justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{line.description}</p>
                <p className="text-xs text-slate-500 dark:text-zinc-400">
                  {line.quantity.toLocaleString('es-MX')} × {portalMoney(line.unit_price)}
                </p>
              </div>
              <p className="shrink-0 font-mono text-sm font-medium tabular-nums">{portalMoney(line.subtotal)}</p>
            </li>
          ))}
        </ul>
      )}
      {hidden > 0 ? (
        <p className="text-xs text-slate-500 dark:text-zinc-400">
          y {hidden} {hidden === 1 ? 'servicio más' : 'servicios más'} en el detalle del contrato.
        </p>
      ) : null}
    </AppCard>
  )
}
