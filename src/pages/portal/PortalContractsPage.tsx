import { CalendarClock, ChevronRight, FileText } from 'lucide-react'
import { useState } from 'react'
import { PortalBalancesWidget } from '@/components/portal/PortalBalancesWidget'
import { PortalInvoiceList } from '@/components/portal/PortalInvoiceList'
import { PortalPlanWidget } from '@/components/portal/PortalPlanWidget'
import {
  PortalEmptyState,
  PortalErrorState,
  PortalScreenSkeleton,
  PortalSkeletonBlock,
} from '@/components/portal/PortalStates'
import { PORTAL_CARD_CLASS } from '@/components/portal/portalTokens'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard, AppCardTitle } from '@/components/ui/AppCard'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { usePortalContract, usePortalOverview } from '@/hooks/usePortalData'
import {
  contractStatusBadge,
  frequencyLabel,
  portalDate,
  portalMoney,
} from '@/lib/portalLabels'
import { cn } from '@/lib/utils'

/** Detalle de un contrato propio dentro de un modal (carga perezosa por id). */
function ContractDetailModal({
  contractId,
  onClose,
}: {
  contractId: number | null
  onClose: () => void
}) {
  const { data, isLoading, isError, error, refetch } = usePortalContract(contractId)

  return (
    <AppModal open={contractId !== null} onOpenChange={(open) => !open && onClose()}>
      <AppModalContent className="max-h-[90vh] max-w-2xl overflow-y-auto rounded-[28px]">
        <AppModalHeader>
          <AppModalTitle>{data ? `Contrato ${data.folio}` : 'Detalle del contrato'}</AppModalTitle>
          <AppModalDescription>
            {data ? `${data.issuer.name} · ${frequencyLabel(data.frequency)}` : 'Cargando…'}
          </AppModalDescription>
        </AppModalHeader>

        {isLoading ? (
          <div className="space-y-3" aria-busy="true">
            <PortalSkeletonBlock className="h-40 w-full rounded-[28px]" />
            <PortalSkeletonBlock className="h-32 w-full rounded-[28px]" />
          </div>
        ) : isError || !data ? (
          <PortalErrorState
            message={error instanceof Error ? error.message : 'Intenta de nuevo.'}
            onRetry={() => void refetch()}
          />
        ) : (
          <div className="space-y-4">
            <PortalPlanWidget contract={data} maxLines={20} />
            <PortalBalancesWidget balances={data.balances} />
            <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-4 p-5')}>
              <AppCardTitle>Facturas del contrato</AppCardTitle>
              <PortalInvoiceList invoices={data.invoices} />
            </AppCard>
          </div>
        )}
      </AppModalContent>
    </AppModal>
  )
}

/** Mis contratos: tarjetas con estado de cuenta resumido y detalle bajo demanda. */
export function PortalContractsPage() {
  const { data, isLoading, isError, error, refetch } = usePortalOverview()
  const [openId, setOpenId] = useState<number | null>(null)

  if (isLoading) return <PortalScreenSkeleton rows={2} />
  if (isError || !data) {
    return (
      <PortalErrorState
        message={error instanceof Error ? error.message : 'Intenta de nuevo en unos segundos.'}
        onRetry={() => void refetch()}
      />
    )
  }

  return (
    <div className="space-y-5">
      <header>
        <h1 className="font-heading text-2xl font-bold tracking-[-0.4px] sm:text-3xl">Mis contratos</h1>
        <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
          Tus suscripciones y su estado de cuenta.
        </p>
      </header>

      {data.contracts.length === 0 ? (
        <PortalEmptyState
          icon={FileText}
          title="Sin contratos por ahora"
          description="Cuando tengas una suscripción activa aparecerá aquí."
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {data.contracts.map((contract) => {
            const status = contractStatusBadge(contract.status)
            return (
              <AppCard key={contract.id} className={cn(PORTAL_CARD_CLASS, 'flex flex-col gap-4 p-5')}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-mono text-sm font-semibold tabular-nums">{contract.folio}</p>
                    <p className="truncate text-sm text-slate-500 dark:text-zinc-400">
                      {contract.issuer.name}
                    </p>
                  </div>
                  <div className="flex shrink-0 flex-wrap justify-end gap-1.5">
                    <AppBadge label={status.label} tone={status.tone} />
                    <AppBadge label={frequencyLabel(contract.frequency)} tone="info" />
                  </div>
                </div>

                <div className="rounded-2xl bg-slate-50 px-4 py-3 dark:bg-white/[0.04]">
                  {contract.is_current ? (
                    <p className="text-sm font-medium text-emerald-600 dark:text-emerald-400">
                      Al corriente
                    </p>
                  ) : (
                    <>
                      <p className="text-xs text-slate-500 dark:text-zinc-400">Monto exigible hoy</p>
                      <p className="font-heading text-2xl font-bold tabular-nums text-[#0066FF] dark:text-[#0A84FF]">
                        {portalMoney(contract.exigible_amount_mxn)}
                      </p>
                      <p className="mt-0.5 text-xs text-slate-500 dark:text-zinc-400">
                        {contract.exigible_detail}
                      </p>
                    </>
                  )}
                </div>

                {contract.next_cutoff_date ? (
                  <p className="flex items-center gap-2 text-xs text-slate-500 dark:text-zinc-400">
                    <CalendarClock className="h-4 w-4 shrink-0" aria-hidden />
                    Próximo corte {portalDate(contract.next_cutoff_date)}
                  </p>
                ) : null}

                <AppButton
                  type="button"
                  variant="outline"
                  className="mt-auto h-11 w-full rounded-2xl"
                  onClick={() => setOpenId(contract.id)}
                >
                  Ver detalle <ChevronRight className="h-4 w-4" />
                </AppButton>
              </AppCard>
            )
          })}
        </div>
      )}

      <ContractDetailModal contractId={openId} onClose={() => setOpenId(null)} />
    </div>
  )
}
