import { CalendarClock, CheckCircle2, CreditCard, Landmark } from 'lucide-react'
import { PORTAL_CARD_CLASS, PORTAL_HERO_CURRENT_CLASS, PORTAL_HERO_DUE_CLASS } from '@/components/portal/portalTokens'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { contractStatusBadge, portalDate, portalMoney } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import type { PortalContract } from '@/types/portal'

type PortalHeroCardProps = {
  contract: PortalContract
  onPayWithCard: () => void
  onShowSpei: () => void
}

/**
 * Tarjeta héroe estilo Apple Wallet: responde en 3 segundos "¿debo algo hoy?".
 * Azul/violeta con el monto exigible devengado, o verde esmeralda cuando está al corriente.
 */
export function PortalHeroCard({ contract, onPayWithCard, onShowSpei }: PortalHeroCardProps) {
  const isCurrent = contract.is_current
  const status = contractStatusBadge(contract.status)

  return (
    <AppCard
      className={cn(
        PORTAL_CARD_CLASS,
        'relative overflow-hidden border-white/20 p-6 shadow-[0_24px_60px_-20px_rgba(10,92,255,0.55)] sm:p-8 dark:border-white/[0.14]',
        isCurrent ? PORTAL_HERO_CURRENT_CLASS : PORTAL_HERO_DUE_CLASS,
      )}
    >
      {/* Brillo especular tipo cristal */}
      <div
        aria-hidden
        className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/20 blur-3xl"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/70 to-transparent"
      />

      <div className="relative space-y-6">
        <div className="flex flex-wrap items-center justify-between gap-2 text-white/85">
          <p className="text-xs font-semibold uppercase tracking-[0.14em]">Tu estado de cuenta</p>
          <p className="whitespace-nowrap rounded-full bg-white/15 px-3 py-1 font-mono text-xs tabular-nums">
            {contract.folio} · {status.label}
          </p>
        </div>

        {isCurrent ? (
          <div className="space-y-2">
            <div className="flex items-center gap-3">
              <CheckCircle2 className="h-10 w-10 shrink-0" aria-hidden />
              <h2 className="font-heading text-3xl font-bold tracking-[-0.4px] sm:text-4xl">
                Estás al corriente
              </h2>
            </div>
            <p className="text-sm text-white/85 sm:text-base">{contract.exigible_detail}</p>
          </div>
        ) : (
          <div className="space-y-2">
            <p className="text-sm font-medium text-white/85">Monto exigible hoy</p>
            <p
              className="font-heading text-5xl font-bold tracking-[-0.4px] tabular-nums sm:text-6xl"
              aria-label={`Monto exigible ${portalMoney(contract.exigible_amount_mxn)} pesos`}
            >
              {portalMoney(contract.exigible_amount_mxn)}
            </p>
            <p className="text-sm text-white/90 sm:text-base">{contract.exigible_detail}</p>
          </div>
        )}

        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          {contract.next_cutoff_date ? (
            <p className="flex items-center gap-2 text-sm text-white/85">
              <CalendarClock className="h-4 w-4 shrink-0" aria-hidden />
              <span>
                Próximo corte:{' '}
                <span className="whitespace-nowrap font-medium">
                  {portalDate(contract.next_cutoff_date)}
                </span>
              </span>
            </p>
          ) : (
            <span />
          )}

          {!isCurrent ? (
            <div className="flex flex-col gap-2.5 sm:flex-row">
              <AppButton
                type="button"
                onClick={onPayWithCard}
                className="h-12 w-full rounded-2xl bg-white px-5 text-base font-semibold text-[#0A5CFF] shadow-[0_8px_20px_rgba(0,0,0,0.18)] hover:bg-white/90 sm:w-auto dark:bg-white dark:text-[#0A4FD8] dark:hover:bg-white/90"
              >
                <CreditCard className="h-5 w-5 shrink-0" />
                <span className="whitespace-nowrap">Pagar ahora con tarjeta</span>
              </AppButton>
              <AppButton
                type="button"
                onClick={onShowSpei}
                className="h-12 w-full rounded-2xl border border-white/35 bg-white/15 px-5 text-base font-semibold text-white hover:bg-white/25 sm:w-auto dark:bg-white/15 dark:hover:bg-white/25"
              >
                <Landmark className="h-5 w-5 shrink-0" />
                <span className="whitespace-nowrap">Datos SPEI</span>
              </AppButton>
            </div>
          ) : null}
        </div>
      </div>
    </AppCard>
  )
}
