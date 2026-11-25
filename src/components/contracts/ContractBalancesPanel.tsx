import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard, AppCardContent, AppCardHeader } from '@/components/ui/AppCard'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import type { PageSizeOption } from '@/context/ScreenPreferencesContext'
import { formatDateEsMx } from '@/lib/utils'
import type { ContractBalancesPayload } from '@/types/balance'

type Props = {
  data: ContractBalancesPayload | undefined
  isLoading: boolean
  isError: boolean
  onRetry: () => void
  consumptionsPage: number
  consumptionsPageSize: PageSizeOption
  onConsumptionsPageChange: (page: number) => void
  onConsumptionsPageSizeChange: (size: PageSizeOption) => void
  showBags?: boolean
  showConsumptions?: boolean
  showBalanceMovements?: boolean
}

/**
 * Tarjetas de bolsas + historiales de consumo / otorgamientos (Pág. 27).
 */
export function ContractBalancesPanel({
  data,
  isLoading,
  isError,
  onRetry,
  consumptionsPage,
  consumptionsPageSize,
  onConsumptionsPageChange,
  onConsumptionsPageSizeChange,
  showBags = true,
  showConsumptions = false,
  showBalanceMovements = false,
}: Props) {
  if (isLoading) {
    return <Skeleton className="h-48 w-full rounded-3xl" />
  }
  if (isError) {
    return (
      <AppCard className="space-y-3 p-6 text-center">
        <p className="text-sm text-slate-500">No se pudieron cargar los saldos.</p>
        <AppButton variant="outline" onClick={onRetry}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  const services = data?.services ?? []

  return (
    <div className="space-y-5">
      {showBags ? (
        <div className="space-y-3">
          <h2 className="font-heading text-lg font-semibold tracking-[-0.4px]">Saldos por servicio</h2>
          {services.length === 0 ? (
            <AppCard className="p-6 text-center text-sm text-slate-500">
              Aún no hay bolsas acreditadas para este contrato.
            </AppCard>
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {services.flatMap((svc) =>
                svc.buckets
                  // Oculta tarjetas vacías 0/0 de bolsa Incluida sin beneficio activo.
                  .filter((bag) => {
                    if (bag.bucket !== 'included') return true
                    return (
                      bag.granted_units > 0 ||
                      bag.consumed_units > 0 ||
                      bag.available_units > 0
                    )
                  })
                  .map((bag) => {
                    const isIncluded = bag.bucket === 'included'
                    const pct =
                      bag.granted_units > 0
                        ? Math.min(100, Math.round((bag.consumed_units / bag.granted_units) * 100))
                        : 0
                    return (
                      <AppCard key={`${svc.service_id}-${bag.id}`} className="p-4">
                        <AppCardHeader className="mb-2 p-0">
                          <div className="flex flex-wrap items-center gap-2">
                            <h3 className="font-heading text-base font-semibold">
                              {svc.service_name || svc.service_code}
                            </h3>
                            <AppBadge
                              label={isIncluded ? 'Incluida' : 'Prepagada'}
                              tone={isIncluded ? 'info' : 'success'}
                            />
                          </div>
                        </AppCardHeader>
                        <AppCardContent className="space-y-3 p-0">
                          {isIncluded ? (
                            <>
                              <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                                <div
                                  className="h-full rounded-full bg-[#0066FF] dark:bg-[#0A84FF]"
                                  style={{ width: `${pct}%` }}
                                />
                              </div>
                              <p className="font-mono text-sm tabular-nums text-slate-700 dark:text-zinc-200">
                                {bag.consumed_units.toLocaleString('es-MX')} /{' '}
                                {bag.granted_units.toLocaleString('es-MX')} ·{' '}
                                <span className="font-semibold">
                                  {bag.available_units.toLocaleString('es-MX')} disponibles
                                </span>
                              </p>
                              <p className="text-xs text-slate-500">
                                {bag.period_end
                                  ? `Se reinicia el ${safeDate(bag.period_end)} · No acumulable`
                                  : 'Periodo incluido · No acumulable'}
                              </p>
                            </>
                          ) : (
                            <>
                              <p className="font-mono text-2xl font-semibold tabular-nums">
                                {bag.available_units.toLocaleString('es-MX')} disponibles
                              </p>
                              <p className="text-xs text-slate-500">
                                Sin caducidad · Se usa al agotar los incluidos
                              </p>
                            </>
                          )}
                        </AppCardContent>
                      </AppCard>
                    )
                  }),
              )}
            </div>
          )}
        </div>
      ) : null}

      {showConsumptions ? (
        <div className="space-y-3">
          <h2 className="font-heading text-lg font-semibold tracking-[-0.4px]">
            Historial de consumos
          </h2>
          <AppTableWrapper
            isEmpty={(data?.consumptions.items.length ?? 0) === 0}
            emptyState={
              <p className="p-6 text-center text-sm text-slate-500">Sin consumos registrados</p>
            }
            footer={
              <PaginationBar
                page={consumptionsPage}
                total={data?.consumptions.total ?? 0}
                pageSize={consumptionsPageSize}
                onPageChange={onConsumptionsPageChange}
                onPageSizeChange={onConsumptionsPageSizeChange}
              />
            }
          >
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <AppTableHeadRow>
                    <th className="px-4 py-3 text-left">Fecha</th>
                    <th className="px-4 py-3 text-left">Servicio</th>
                    <th className="px-4 py-3 text-right">Unidades</th>
                    <th className="px-4 py-3 text-left">Referencia</th>
                    <th className="px-4 py-3 text-left">Notas</th>
                  </AppTableHeadRow>
                </thead>
                <tbody>
                  {(data?.consumptions.items ?? []).map((row) => (
                    <tr
                      key={row.id}
                      className="border-b border-slate-100 dark:border-white/[0.04]"
                    >
                      <td className="px-4 py-3 whitespace-nowrap">
                        {safeDate(row.consumed_at)}
                      </td>
                      <td className="px-4 py-3">{row.service_name}</td>
                      <td className="px-4 py-3 text-right font-mono tabular-nums">
                        {row.units}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs tabular-nums">
                        {row.external_reference ?? '—'}
                      </td>
                      <td className="max-w-[240px] px-4 py-3 text-xs text-slate-500">
                        {row.notes ?? '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </AppTableWrapper>
        </div>
      ) : null}

      {showBalanceMovements ? (
        <div className="space-y-3">
          <h2 className="font-heading text-lg font-semibold tracking-[-0.4px]">
            Movimientos de bolsa
          </h2>
          <AppTableWrapper
            isEmpty={(data?.balance_movements.length ?? 0) === 0}
            emptyState={
              <p className="p-6 text-center text-sm text-slate-500">
                Sin otorgamientos ni compras de bolsa
              </p>
            }
          >
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <AppTableHeadRow>
                    <th className="px-4 py-3 text-left">Fecha</th>
                    <th className="px-4 py-3 text-left">Servicio</th>
                    <th className="px-4 py-3 text-left">Bolsa</th>
                    <th className="px-4 py-3 text-left">Tipo</th>
                    <th className="px-4 py-3 text-right">Unidades</th>
                    <th className="px-4 py-3 text-left">Notas</th>
                  </AppTableHeadRow>
                </thead>
                <tbody>
                  {(data?.balance_movements ?? []).map((row) => (
                    <tr
                      key={row.id}
                      className="border-b border-slate-100 dark:border-white/[0.04]"
                    >
                      <td className="px-4 py-3 whitespace-nowrap">
                        {safeDate(row.created_at)}
                      </td>
                      <td className="px-4 py-3">{row.service_name}</td>
                      <td className="px-4 py-3">
                        <AppBadge
                          label={row.bucket === 'included' ? 'Incluida' : 'Prepagada'}
                          tone="neutral"
                        />
                      </td>
                      <td className="px-4 py-3">
                        <AppBadge
                          label={balanceMovementTypeLabel(row.movement_type)}
                          tone={balanceMovementTypeTone(row.movement_type)}
                        />
                      </td>
                      <td className="px-4 py-3 text-right font-mono tabular-nums">
                        {row.units}
                      </td>
                      <td className="max-w-[240px] px-4 py-3 text-xs text-slate-500">
                        {row.notes ?? '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </AppTableWrapper>
        </div>
      ) : null}
    </div>
  )
}

function safeDate(value: string | null | undefined): string {
  if (!value) return '—'
  try {
    return formatDateEsMx(value)
  } catch {
    return String(value).slice(0, 10)
  }
}

/** Traducción UI de balance_movements.movement_type (ENUM inglés → español). */
function balanceMovementTypeLabel(type: string): string {
  switch (type) {
    case 'grant':
      return 'Otorgamiento'
    case 'purchase':
      return 'Compra'
    case 'promotion':
      return 'Promoción'
    case 'reset':
      return 'Reinicio'
    case 'adjustment':
      return 'Ajuste'
    default:
      return type
  }
}

function balanceMovementTypeTone(
  type: string,
): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  switch (type) {
    case 'grant':
      return 'info'
    case 'purchase':
      return 'success'
    case 'promotion':
      return 'warning'
    case 'reset':
      return 'neutral'
    case 'adjustment':
      return 'neutral'
    default:
      return 'neutral'
  }
}
