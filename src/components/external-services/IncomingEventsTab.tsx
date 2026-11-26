import { useState } from 'react'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppRowActionsMenu, type RowActionItem } from '@/components/ui/AppRowActionsMenu'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useIncomingEvents } from '@/hooks/useExternalServices'
import { formatDateTimeEsMx } from '@/lib/utils'
import type { IncomingEvent } from '@/types/externalServices'
import { ORIGIN_LABELS } from './externalServiceMeta'
import { JsonPayloadModal } from './JsonPayloadModal'

const ALL = 'all'

const ORIGIN_OPTIONS = [
  { value: ALL, label: 'Todos' },
  ...Object.entries(ORIGIN_LABELS).map(([value, label]) => ({ value, label })),
]

const PROCESSED_OPTIONS = [
  { value: ALL, label: 'Todos' },
  { value: 'yes', label: 'Procesado', tone: 'success' as const },
  { value: 'no', label: 'Sin procesar', tone: 'warning' as const },
]

function formatWhen(value: string | null): string {
  if (!value) return '—'
  try {
    return formatDateTimeEsMx(value)
  } catch {
    return value
  }
}

function processedBadge(event: IncomingEvent) {
  if (event.error) return <AppBadge label="Con error" tone="danger" />
  return event.processed ? (
    <AppBadge label="Procesado" tone="success" />
  ) : (
    <AppBadge label="Sin procesar" tone="warning" />
  )
}

/**
 * Bitácora de webhooks recibidos (ERP Emisor, IntegroPAY, ERP Master) — solo lectura.
 * La deduplicación por (origin, external_id) ocurre en el backend.
 */
export function IncomingEventsTab() {
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()

  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  const [search, setSearch] = useState('')
  const [origin, setOrigin] = useState(ALL)
  const [processed, setProcessed] = useState(ALL)
  const [selected, setSelected] = useState<IncomingEvent | null>(null)

  const query = useIncomingEvents({
    page,
    perPage: pageSize,
    origin: origin === ALL ? '' : origin,
    processed: processed === ALL ? '' : processed,
    search: search.trim(),
  })

  const items = query.data?.items ?? []
  const hasActiveFilters = search.trim() !== '' || origin !== ALL || processed !== ALL

  function clearFilters() {
    setSearch('')
    setOrigin(ALL)
    setProcessed(ALL)
    setPage(1)
  }

  const rowActions = (row: IncomingEvent): RowActionItem[] => [
    { label: 'Ver payload', onClick: () => setSelected(row) },
  ]

  return (
    <div className="space-y-5">
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
        searchPlaceholder="ID externo o tipo de evento…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'origin',
            label: 'ORIGEN',
            value: origin,
            onValueChange: (value) => {
              setOrigin(value)
              setPage(1)
            },
            options: ORIGIN_OPTIONS,
          },
          {
            id: 'processed',
            label: 'PROCESADO',
            value: processed,
            onValueChange: (value) => {
              setProcessed(value)
              setPage(1)
            },
            options: PROCESSED_OPTIONS,
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
              ? 'No se pudieron cargar los eventos entrantes.'
              : 'Aún no se han recibido webhooks con estos filtros.'}
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
                <th className="px-4 py-3 font-medium">Origen</th>
                <th className="px-4 py-3 font-medium">ID externo</th>
                <th className="px-4 py-3 font-medium">Evento</th>
                <th className="px-4 py-3 font-medium">Procesado</th>
                <th className="px-4 py-3 font-medium">Fecha</th>
                <th className="px-4 py-3 text-right font-medium">ACCIONES</th>
              </AppTableHeadRow>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr key={row.id} className="border-b border-slate-100 dark:border-white/[0.04]">
                  <td className={rowPaddingClass}>
                    <AppBadge label={ORIGIN_LABELS[row.origin] ?? row.origin} tone="neutral" />
                  </td>
                  <td className={`${rowPaddingClass} max-w-xs`}>
                    <p className="truncate font-mono text-xs" title={row.external_id}>
                      {row.external_id}
                    </p>
                  </td>
                  <td className={`${rowPaddingClass} font-mono text-xs`}>{row.event_type}</td>
                  <td className={rowPaddingClass}>{processedBadge(row)}</td>
                  <td className={`${rowPaddingClass} whitespace-nowrap font-mono text-xs tabular-nums`}>
                    {formatWhen(row.created_at)}
                  </td>
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
                  <p className="font-mono text-xs">{row.event_type}</p>
                  <p className="font-mono text-xs tabular-nums text-slate-500">
                    {formatWhen(row.created_at)}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {processedBadge(row)}
                  <AppRowActionsMenu actions={rowActions(row)} />
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-1.5">
                <AppBadge label={ORIGIN_LABELS[row.origin] ?? row.origin} tone="neutral" />
              </div>
              <p className="break-all font-mono text-xs text-slate-500">{row.external_id}</p>
            </AppCard>
          ))}
        </div>
      </AppTableWrapper>

      <JsonPayloadModal
        open={selected != null}
        onOpenChange={(open) => {
          if (!open) setSelected(null)
        }}
        title={selected ? `Webhook · ${selected.event_type}` : ''}
        description={
          selected ? (
            <div className="flex flex-wrap items-center gap-1.5">
              <AppBadge label={ORIGIN_LABELS[selected.origin] ?? selected.origin} tone="neutral" />
              {processedBadge(selected)}
              <span className="font-mono text-xs">{selected.external_id}</span>
            </div>
          ) : undefined
        }
        sections={
          selected
            ? [{ label: 'Payload recibido', value: selected.payload, errorText: selected.error }]
            : []
        }
      />
    </div>
  )
}
