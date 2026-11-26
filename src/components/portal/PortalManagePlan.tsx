import { Lock, PackagePlus, Repeat, UserPlus, type LucideIcon } from 'lucide-react'
import { Link } from 'react-router-dom'
import { PORTAL_CARD_CLASS } from '@/components/portal/portalTokens'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard, AppCardDescription, AppCardTitle } from '@/components/ui/AppCard'
import { cn } from '@/lib/utils'

type PortalManagePlanProps = {
  /** Con adeudo exigible los cambios de plan se bloquean hasta ponerse al corriente. */
  isCurrent: boolean
}

type QuickAction = {
  label: string
  description: string
  to: string
  icon: LucideIcon
  /** Cambio de plan: exige estar al corriente (regla RF-8.1 del backend). */
  isPlanChange: boolean
}

const QUICK_ACTIONS: QuickAction[] = [
  {
    label: 'Agregar usuarios',
    description: 'Amplía tu licencia',
    to: '/portal/solicitudes?new=add_users',
    icon: UserPlus,
    isPlanChange: true,
  },
  {
    label: 'Cambiar mi plan',
    description: 'Frecuencia, bajas o cancelación',
    to: '/portal/solicitudes?new=change_frequency',
    icon: Repeat,
    isPlanChange: true,
  },
  {
    label: 'Comprar bolsa',
    description: 'Más timbres, horas o saldos',
    to: '/portal/tienda',
    icon: PackagePlus,
    isPlanChange: false,
  },
]

/**
 * "Gestionar mi plan": accesos rápidos. Si el contrato no está al corriente, los cambios de plan
 * se muestran con candado; la tienda de servicios extra sigue disponible.
 */
export function PortalManagePlan({ isCurrent }: PortalManagePlanProps) {
  return (
    <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-4 p-5')}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <AppCardTitle>Gestionar mi plan</AppCardTitle>
          <AppCardDescription>Haz crecer tu suscripción en un toque</AppCardDescription>
        </div>
        {!isCurrent ? (
          <p className="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full bg-amber-500/12 px-3 py-1 text-xs font-medium text-amber-700 dark:text-amber-400">
            <Lock className="h-3.5 w-3.5" aria-hidden /> Cambios de plan al ponerte al corriente
          </p>
        ) : null}
      </div>

      <div className="grid gap-2.5 sm:grid-cols-3">
        {QUICK_ACTIONS.map(({ label, description, to, icon: Icon, isPlanChange }) => {
          const locked = isPlanChange && !isCurrent
          const body = (
            <>
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#0066FF]/10 text-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]">
                {locked ? <Lock className="h-4 w-4" aria-hidden /> : <Icon className="h-5 w-5" aria-hidden />}
              </span>
              <span className="min-w-0 text-left">
                <span className="block text-sm font-semibold">{label}</span>
                <span className="block truncate text-xs font-normal text-slate-500 dark:text-zinc-400">
                  {description}
                </span>
              </span>
            </>
          )
          const classes =
            'h-auto min-h-[64px] w-full justify-start gap-3 rounded-2xl px-4 py-3 text-slate-900 dark:text-zinc-50'

          return locked ? (
            <AppButton key={label} type="button" variant="outline" disabled className={classes}>
              {body}
            </AppButton>
          ) : (
            <AppButton key={label} asChild variant="outline" className={classes}>
              <Link to={to}>{body}</Link>
            </AppButton>
          )
        })}
      </div>
    </AppCard>
  )
}
