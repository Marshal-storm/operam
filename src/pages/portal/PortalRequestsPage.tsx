import { AlertTriangle, ClipboardList, Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  PORTAL_NOT_CURRENT_MESSAGE,
  PortalRequestModal,
} from '@/components/portal/PortalRequestModal'
import {
  PortalEmptyState,
  PortalErrorState,
  PortalScreenSkeleton,
} from '@/components/portal/PortalStates'
import { PORTAL_CARD_CLASS } from '@/components/portal/portalTokens'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { usePortalOverview, usePortalRequests } from '@/hooks/usePortalData'
import { compareMoney } from '@/lib/moneyCents'
import {
  PORTAL_REQUEST_TYPE_LABEL,
  portalDate,
  portalMoney,
  portalRequestStatusBadge,
  portalRequestTitle,
  portalUnits,
} from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import type { PortalRequestType } from '@/types/portal'

function isPortalRequestType(value: string | null): value is PortalRequestType {
  return value !== null && value in PORTAL_REQUEST_TYPE_LABEL
}

/**
 * Solicitudes del portal (RF-8.1): alta de solicitudes (usuarios, frecuencia, bajas, servicios
 * extra) e historial con estado amigable. `?new=<tipo>` abre el formulario precargado desde los
 * accesos de "Gestionar mi plan".
 */
export function PortalRequestsPage() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const requests = usePortalRequests()
  const overview = usePortalOverview()
  const [modalOpen, setModalOpen] = useState(false)
  const [initialType, setInitialType] = useState<PortalRequestType | undefined>(undefined)

  const activeContracts = useMemo(
    () => (overview.data?.contracts ?? []).filter((contract) => contract.status === 'active'),
    [overview.data],
  )
  const hasPendingBalance = activeContracts.some((contract) => !contract.is_current)

  const requestedType = searchParams.get('new')
  useEffect(() => {
    if (!isPortalRequestType(requestedType) || activeContracts.length === 0) return
    setInitialType(requestedType)
    setModalOpen(true)
    setSearchParams({}, { replace: true })
  }, [requestedType, activeContracts.length, setSearchParams])

  function openNewRequest() {
    setInitialType(undefined)
    setModalOpen(true)
  }

  if (requests.isLoading) return <PortalScreenSkeleton rows={2} />
  if (requests.isError || !requests.data) {
    return (
      <PortalErrorState
        message={requests.error instanceof Error ? requests.error.message : 'Intenta de nuevo en unos segundos.'}
        onRetry={() => void requests.refetch()}
      />
    )
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-bold tracking-[-0.4px] sm:text-3xl">Solicitudes</h1>
          <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
            Pide cambios a tu plan y sigue su avance.
          </p>
        </div>
        <AppButton
          type="button"
          className="h-11 rounded-full px-5"
          disabled={activeContracts.length === 0}
          onClick={openNewRequest}
        >
          <Plus className="h-4 w-4" aria-hidden /> Nueva solicitud
        </AppButton>
      </header>

      {hasPendingBalance ? (
        <AppCard
          role="alert"
          className={cn(
            PORTAL_CARD_CLASS,
            'flex flex-col gap-3 border-amber-500/30 bg-amber-500/[0.06] p-5 sm:flex-row sm:items-center sm:justify-between dark:bg-amber-500/[0.08]',
          )}
        >
          <p className="flex items-start gap-2.5 text-sm text-amber-800 dark:text-amber-200">
            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden />
            <span>
              {PORTAL_NOT_CURRENT_MESSAGE} Los servicios extra siguen disponibles.
            </span>
          </p>
          <AppButton
            type="button"
            variant="outline"
            className="h-11 shrink-0 rounded-full px-5"
            onClick={() => navigate('/portal/pagos')}
          >
            Ir a ponerme al corriente
          </AppButton>
        </AppCard>
      ) : null}

      {requests.data.length === 0 ? (
        <PortalEmptyState
          icon={ClipboardList}
          title="Sin solicitudes todavía"
          description="Cuando pidas una ampliación, un cambio o un servicio extra, podrás seguir su avance aquí."
        />
      ) : (
        <ul className="space-y-3">
          {requests.data.map((item) => {
            const status = portalRequestStatusBadge(item.portal_status)
            const hasAmount = compareMoney(item.subtotal, '0.00') > 0
            return (
              <li key={item.id}>
                <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-3 p-5')}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold">{portalRequestTitle(item)}</p>
                      <p className="mt-0.5 text-xs text-slate-500 dark:text-zinc-400">
                        <span className="font-mono tabular-nums">{item.folio}</span>
                        {item.contract_folio ? (
                          <>
                            {' · '}
                            <span className="font-mono tabular-nums">{item.contract_folio}</span>
                          </>
                        ) : null}
                        {' · '}
                        {portalDate(item.created_at)}
                      </p>
                    </div>
                    <AppBadge label={status.label} tone={status.tone} className="whitespace-nowrap shrink-0" />
                  </div>

                  {item.items.length > 0 ? (
                    <div className="flex flex-wrap gap-1.5">
                      {item.items.map((line, index) => (
                        <AppBadge
                          key={`${item.id}-${index}`}
                          tone="neutral"
                          className="whitespace-nowrap shrink-0"
                          label={`${portalUnits(line.quantity)} × ${line.service_name}`}
                        />
                      ))}
                    </div>
                  ) : null}

                  {item.notes ? (
                    <p className="line-clamp-3 text-sm text-slate-600 dark:text-zinc-300">{item.notes}</p>
                  ) : null}

                  {item.resolution_notes ? (
                    <p className="rounded-2xl bg-slate-100/80 px-3 py-2 text-xs text-slate-600 dark:bg-white/[0.05] dark:text-zinc-300">
                      <span className="font-semibold">Respuesta: </span>
                      {item.resolution_notes}
                    </p>
                  ) : null}

                  {hasAmount ? (
                    <p className="flex items-center justify-between border-t border-slate-200/70 pt-3 text-sm dark:border-white/[0.07]">
                      <span className="text-slate-500 dark:text-zinc-400">Importe estimado (sin IVA)</span>
                      <span className="font-mono text-base font-semibold tabular-nums">{portalMoney(item.subtotal)}</span>
                    </p>
                  ) : null}
                </AppCard>
              </li>
            )
          })}
        </ul>
      )}

      <PortalRequestModal
        open={modalOpen}
        onOpenChange={setModalOpen}
        contracts={activeContracts}
        initialType={initialType}
      />
    </div>
  )
}
