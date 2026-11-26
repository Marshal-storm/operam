import { FileText } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { PortalBalancesWidget } from '@/components/portal/PortalBalancesWidget'
import { PortalHeroCard } from '@/components/portal/PortalHeroCard'
import { PortalInvoiceList } from '@/components/portal/PortalInvoiceList'
import { PortalManagePlan } from '@/components/portal/PortalManagePlan'
import { PortalPaymentModal } from '@/components/portal/PortalPaymentModal'
import { PortalPlanWidget } from '@/components/portal/PortalPlanWidget'
import { PortalSpeiModal } from '@/components/portal/PortalSpeiModal'
import {
  PortalEmptyState,
  PortalErrorState,
  PortalScreenSkeleton,
} from '@/components/portal/PortalStates'
import { PORTAL_CARD_CLASS } from '@/components/portal/portalTokens'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard, AppCardTitle } from '@/components/ui/AppCard'
import { AppSelect } from '@/components/ui/AppSelect'
import { usePortalAuth } from '@/context/PortalAuthContext'
import { usePortalOverview } from '@/hooks/usePortalData'
import { contractStatusBadge, firstNameOf } from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import type { PortalContract } from '@/types/portal'

/** Primer contrato activo; si no hay, el más reciente. */
function pickDefaultContract(
  contracts: PortalContract[],
  preferredId: number | null,
): PortalContract | null {
  if (contracts.length === 0) return null
  return (
    contracts.find((contract) => contract.id === preferredId) ??
    contracts.find((contract) => contract.status === 'active') ??
    contracts[0] ??
    null
  )
}

/**
 * Inicio del portal: saludo, tarjeta héroe del estado de cuenta, widgets Bento,
 * acciones de plan y facturas recientes. Datos reales de `GET /api/portal/overview`.
 */
export function PortalHomePage() {
  const { profile } = usePortalAuth()
  const { data, isLoading, isError, error, refetch } = usePortalOverview()
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [speiOpen, setSpeiOpen] = useState(false)
  const [payOpen, setPayOpen] = useState(false)

  const contracts = useMemo(() => data?.contracts ?? [], [data])
  const contract = useMemo(
    () =>
      pickDefaultContract(
        contracts,
        selectedId ?? data?.default_contract_id ?? profile?.contract_id ?? null,
      ),
    [contracts, selectedId, data?.default_contract_id, profile?.contract_id],
  )

  if (isLoading) return <PortalScreenSkeleton />
  if (isError || !data) {
    return (
      <PortalErrorState
        message={error instanceof Error ? error.message : 'Intenta de nuevo en unos segundos.'}
        onRetry={() => void refetch()}
      />
    )
  }

  const greetingName = firstNameOf(data.contact.name || data.client.name)

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-bold tracking-[-0.4px] sm:text-3xl">
            Hola, {greetingName}
          </h1>
          <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">{data.client.name}</p>
        </div>

        {contracts.length > 1 ? (
          <div className="w-full sm:w-72">
            <AppSelect
              aria-label="Seleccionar contrato"
              value={contract ? String(contract.id) : undefined}
              onValueChange={(value) => setSelectedId(Number(value))}
              triggerClassName="h-11 rounded-full"
              options={contracts.map((item) => ({
                value: String(item.id),
                label: `${item.folio} · ${contractStatusBadge(item.status).label}`,
                tone: item.is_current ? 'success' : 'warning',
              }))}
            />
          </div>
        ) : null}
      </header>

      {contract === null ? (
        <PortalEmptyState
          icon={FileText}
          title="Aún no tienes contratos activos"
          description="Cuando tu suscripción esté lista la verás aquí con su estado de cuenta."
        />
      ) : (
        <>
          <PortalHeroCard
            contract={contract}
            onShowSpei={() => setSpeiOpen(true)}
            onPayWithCard={() => setPayOpen(true)}
          />

          <div className="grid gap-4 md:grid-cols-2">
            <PortalBalancesWidget balances={contract.balances} />
            <PortalPlanWidget contract={contract} />
          </div>

          <PortalManagePlan isCurrent={contract.is_current} />

          <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-4 p-5')}>
            <div className="flex items-center justify-between gap-3">
              <AppCardTitle>Facturas recientes</AppCardTitle>
              <AppButton asChild variant="ghost" size="sm" className="rounded-full">
                <Link to="/portal/pagos">Ver todas</Link>
              </AppButton>
            </div>
            <PortalInvoiceList
              invoices={
                contracts.length > 1
                  ? data.recent_invoices.filter((invoice) => invoice.contract_id === contract.id)
                  : data.recent_invoices
              }
            />
          </AppCard>

          <PortalSpeiModal
            open={speiOpen}
            onOpenChange={setSpeiOpen}
            spei={contract.spei}
            contractFolio={contract.folio}
            amount={contract.is_current ? null : contract.exigible_amount_mxn}
          />

          <PortalPaymentModal open={payOpen} onOpenChange={setPayOpen} contract={contract} />
        </>
      )}
    </div>
  )
}
