import { AlertTriangle, CheckCircle2, Hourglass, PlayCircle } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppRowActionsMenu, type RowActionItem } from '@/components/ui/AppRowActionsMenu'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { useExternalServiceMutations, useOutboxMessages } from '@/hooks/useExternalServices'
import { formatDateTimeEsMx } from '@/lib/utils'
import type { OutboxMessage, OutboxStatus } from '@/types/externalServices'
import {
  DESTINATION_LABELS,
  OPERATION_LABELS,
  OUTBOX_STATUS_META,
} from './externalServiceMeta'
import { JsonPayloadModal } from './JsonPayloadModal'

const ALL = 'all'

const DESTINATION_OPTIONS = [
  { value: ALL, label: 'Todos' },
  ...Object.entries(DESTINATION_LABELS).map(([value, label]) => ({ value, label })),
]

const STATUS_OPTIONS = [
  { value: ALL, label: 'Todos' },
  ...(Object.keys(OUTBOX_STATUS_META) as OutboxStatus[]).map((value) => ({
    value,
    label: OUTBOX_STATUS_META[value].label,
    tone: OUTBOX_STATUS_META[value].tone,
  })),
]

type Props = {
  /** `config_external_services.edit` + alcance de segmento "Todos". */
  canOperate: boolean
}

function formatWhen(value: string | null): string {
  if (!value) return '—'
  try {
    return formatDateTimeEsMx(value)
  } catch {
    return value
  }
}

function operationLabel(operation: string): string {
  return OPERATION_LABELS[operation] ?? operation
}

function KpiCard({
  label,
  value,
  icon,
  toneClass,
}: {
  label: string
  value: number
  icon: ReactNode
  toneClass: string
}) {
  return (
    <AppCard className="flex items-center gap-3 p-4">
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ${toneClass}`}>
        {icon}
      </span>
      <div>
        <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-400">
          {label}
        </p>
        <p className="font-heading text-2xl font-semibold tabular-nums tracking-[-0.4px]">
          {value.toLocaleString('es-MX')}
        </p>
      </div>
    </AppCard>
  )
}

/**
 * Observabilidad de la cola `outbox`: KPIs, filtros, reintentos y procesamiento manual.
 */
export function OutboxMonitorTab({ canOperate }: Props) {
  const toast = useToast()
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const { retryMutation, processNowMutation } = useExternalServiceMutations()

  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  const [search, setSearch] = useState('')
  const [destination, setDestination] = useState(ALL)
  const [status, setStatus] = useState(ALL)
  const [selected, setSelected] = useState<OutboxMessage | null>(null)

  const query = useOutboxMessages({
    page,
    perPage: pageSize,
    status: status === ALL ? '' : status,
    destination: destination === ALL ? '' : destination,
    search: search.trim(),
  })

  const items = query.data?.items ?? []
  const kpis = query.data?.kpis
  const hasActiveFilters = search.trim() !== '' || destination !== ALL || status !== ALL

  function clearFilters() {
    setSearch('')
    setDestination(ALL)
    setStatus(ALL)
    setPage(1)
  }

  async function handleProcessNow() {
    try {
      const result = await processNowMutation.mutateAsync()
      const summary = `${result.processed} procesados · ${result.sent} enviados · ${result.failed} fallidos`
      if (result.processed === 0) {
        toast.info('Cola al día', 'No hay mensajes pendientes listos para enviarse.')
      } else if (result.failed > 0) {
        toast.warning('Cola procesada con fallos', summary)
      } else {
        toast.success('Cola procesada', summary)
      }
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function handleRetry(message: OutboxMessage) {
    try {
      const result = await retryMutation.mutateAsync(message.id)
      if (result.status === 'sent') {
        toast.success('Reenvío exitoso', `El mensaje #${result.id} se entregó correctamente.`)
      } else {
        toast.warning(
          'El reintento volvió a fallar',
          result.last_error ?? `El mensaje #${result.id} sigue en estado ${OUTBOX_STATUS_META[result.status].label.toLowerCase()}.`,
        )
      }
      if (selected?.id === result.id) setSelected(result)
    } catch (error) {
      toast.apiError(error)
    }
  }

  function rowActions(row: OutboxMessage): RowActionItem[] {
    return [
      { label: 'Ver payload y respuesta', onClick: () => setSelected(row) },
      {
        label: 'Reintentar envío',
        onClick: () => void handleRetry(row),
        hidden: !canOperate,
        disabled: row.status === 'sent' || row.status === 'processing' || retryMutation.isPending,
      },
    ]
  }

  const statusBadge = (row: OutboxMessage) => {
    const meta = OUTBOX_STATUS_META[row.status]
    return <AppBadge label={meta.label} tone={meta.tone} />
  }

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-3">
        <KpiCard
          label="Pendientes"
          value={(kpis?.pending ?? 0) + (kpis?.processing ?? 0)}
          icon={<Hourglass className="h-5 w-5" />}
          toneClass="bg-amber-500/10 text-[#F59E0B]"
        />
        <KpiCard
          label="Enviados"
          value={kpis?.sent ?? 0}
          icon={<CheckCircle2 className="h-5 w-5" />}
          toneClass="bg-emerald-500/10 text-[#10B981]"
        />
        <KpiCard
          label="Fallidos"
          value={kpis?.failed ?? 0}
          icon={<AlertTriangle className="h-5 w-5" />}
          toneClass="bg-red-500/10 text-[#EF4444]"
        />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-500 dark:text-zinc-400">
          {kpis && kpis.exhausted > 0
            ? `${kpis.exhausted} mensaje(s) agotaron sus intentos y requieren reintento manual.`
            : 'Los mensajes fallidos se reintentan automáticamente con espera progresiva.'}
        </p>
        {canOperate ? (
          <AppButton
            onClick={() => void handleProcessNow()}
            isLoading={processNowMutation.isPending}
            disabled={processNowMutation.isPending}
          >
            {!processNowMutation.isPending ? <PlayCircle className="h-4 w-4" /> : null}
            Procesar cola ahora
          </AppButton>
        ) : null}
      </div>

      <AppFilterBar
        searchValue={search}
        onSearchChange={(value) => {
          setSearch(value)
          setPage(1)
        }}
        onSearchClear={() => {
          setSearch('')
          setPage(1)
        }}
        searchPlaceholder="ID, llave de idempotencia u operación…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'destination',
            label: 'DESTINO',
            value: destination,
            onValueChange: (value) => {
              setDestination(value)
              setPage(1)
            },
            options: DESTINATION_OPTIONS,
          },
          {
            id: 'status',
            label: 'ESTADO',
            value: status,
            onValueChange: (value) => {
              setStatus(value)
              setPage(1)
            },
            options: STATUS_OPTIONS,
          },
        ]}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      />

      <AppTableWrapper
        isLoading={query.isLoading}
        loadingState={<Skeleton className="h-56 w-full rounded-none" />}
        isEmpty={!query.isLoading && (query.isError || items.length === 0)}
        emptyState={
          <p className="px-4 py-10 text-center text-sm text-slate-500">
            {query.isError
              ? 'No se pudo cargar la cola del outbox.'
              : 'No hay mensajes en la cola con estos filtros.'}
          </p>
        }
        footer={
          <PaginationBar
            page={page}
            total={query.data?.total ?? 0}
            pageSize={pageSize}
            onPageChange={setPage}
            onPageSizeChange={(size) => {
              setLocalPageSize(size)
              setPageSize(size)
              setPage(1)
            }}
          />
        }
      >
        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-sm">
            <thead>
              <AppTableHeadRow>
                <th className="px-4 py-3 font-medium">ID</th>
                <th className="px-4 py-3 font-medium">Destino</th>
                <th className="px-4 py-3 font-medium">Operación</th>
                <th className="px-4 py-3 font-medium">Llave idempotencia</th>
                <th className="px-4 py-3 font-medium">Intentos</th>
                <th className="px-4 py-3 font-medium">Estado</th>
                <th className="px-4 py-3 text-right font-medium">ACCIONES</th>
              </AppTableHeadRow>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr key={row.id} className="border-b border-slate-100 dark:border-white/[0.04]">
                  <td className={`${rowPaddingClass} font-mono text-xs tabular-nums`}>#{row.id}</td>
                  <td className={rowPaddingClass}>
                    <AppBadge label={DESTINATION_LABELS[row.destination] ?? row.destination} tone="neutral" />
                  </td>
                  <td className={rowPaddingClass}>
                    <p className="font-medium">{operationLabel(row.operation)}</p>
                    <p className="font-mono text-xs tabular-nums text-slate-500">
                      {formatWhen(row.updated_at ?? row.created_at)}
                    </p>
                  </td>
                  <td className={`${rowPaddingClass} max-w-xs`}>
                    <p className="truncate font-mono text-xs" title={row.idempotency_key}>
                      {row.idempotency_key}
                    </p>
                  </td>
                  <td className={`${rowPaddingClass} whitespace-nowrap font-mono text-xs tabular-nums`}>
                    {row.attempts} / {row.max_attempts}
                  </td>
                  <td className={rowPaddingClass}>{statusBadge(row)}</td>
                  <td className={`${rowPaddingClass} text-right`}>
                    <AppRowActionsMenu actions={rowActions(row)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="space-y-3 p-4 md:hidden">
          {items.map((row) => (
            <AppCard key={row.id} className="space-y-2 p-3">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-medium">{operationLabel(row.operation)}</p>
                  <p className="font-mono text-xs tabular-nums text-slate-500">
                    #{row.id} · {formatWhen(row.updated_at ?? row.created_at)}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {statusBadge(row)}
                  <AppRowActionsMenu actions={rowActions(row)} />
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-1.5">
                <AppBadge label={DESTINATION_LABELS[row.destination] ?? row.destination} tone="neutral" />
                <span className="font-mono text-xs tabular-nums text-slate-500">
                  Intentos {row.attempts} / {row.max_attempts}
                </span>
              </div>
              <p className="break-all font-mono text-xs text-slate-500">{row.idempotency_key}</p>
            </AppCard>
          ))}
        </div>
      </AppTableWrapper>

      <JsonPayloadModal
        open={selected != null}
        onOpenChange={(open) => {
          if (!open) setSelected(null)
        }}
        title={selected ? `Mensaje #${selected.id} · ${operationLabel(selected.operation)}` : ''}
        description={
          selected ? (
            <div className="flex flex-wrap items-center gap-1.5">
              <AppBadge label={DESTINATION_LABELS[selected.destination] ?? selected.destination} tone="neutral" />
              {statusBadge(selected)}
              <span className="font-mono text-xs tabular-nums">
                Intentos {selected.attempts} / {selected.max_attempts}
                {selected.next_attempt_at && selected.status !== 'sent'
                  ? ` · Próximo intento ${formatWhen(selected.next_attempt_at)}`
                  : ''}
                {selected.sent_at ? ` · Enviado ${formatWhen(selected.sent_at)}` : ''}
              </span>
            </div>
          ) : undefined
        }
        sections={
          selected
            ? [
                { label: 'Payload enviado', value: selected.payload },
                {
                  label: 'Respuesta del destino',
                  value: selected.response,
                  errorText: selected.last_error,
                },
              ]
            : []
        }
      />
    </div>
  )
}
