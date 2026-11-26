import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'
import { Eye, FileText, Percent, Plus, User } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { BulkPriceAdjustmentModal } from '@/components/contracts/BulkPriceAdjustmentModal'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useContracts } from '@/hooks/useContracts'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegments } from '@/hooks/useSegments'
import { cn, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import type { ContractListItem, ContractStatus } from '@/types/contract'

const contractsTableFeatures = tableFeatures({})

const STATUS_TONE: Record<ContractStatus, 'success' | 'warning' | 'danger' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  cancelled: 'danger',
  draft: 'neutral',
}

const STATUS_LABEL: Record<ContractStatus, string> = {
  active: 'Activo',
  suspended: 'Suspendido',
  cancelled: 'Cancelado',
  draft: 'Borrador',
}

/**
 * Listado de Contratos — Módulo 3 Paso 2 (`/contratos`).
 */
export function ContractsPage() {
  const navigate = useNavigate()
  const { canCreate, canEdit } = usePermissions()
  const [bulkOpen, setBulkOpen] = useState(false)
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const {
    segments,
    isSegmentLocked,
    lockedSegmentId,
  } = useSegments()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [segmentFilter, setSegmentFilter] = useState(
    isSegmentLocked && lockedSegmentId ? String(lockedSegmentId) : 'Todos',
  )
  const [issuerFilter, setIssuerFilter] = useState('Todos')
  const [frequencyFilter, setFrequencyFilter] = useState('Todas')
  const [statusFilter, setStatusFilter] = useState('Todos')

  const segmentIdParam = useMemo(() => {
    if (isSegmentLocked && lockedSegmentId) return lockedSegmentId
    if (segmentFilter === 'Todos') return undefined
    const byId = Number(segmentFilter)
    return Number.isFinite(byId) && byId > 0 ? byId : undefined
  }, [isSegmentLocked, lockedSegmentId, segmentFilter])

  const {
    items,
    total,
    perPage,
    summary,
    issuers,
    isLoading,
    isFetching,
    isApiError,
    errorMessage,
    refetch,
  } = useContracts({
    page,
    per_page: preferredSize,
    search: search.trim() || undefined,
    segment_id: segmentIdParam,
    issuer_id:
      issuerFilter !== 'Todos' && Number(issuerFilter) > 0
        ? Number(issuerFilter)
        : undefined,
    billing_frequency:
      frequencyFilter === 'Mensual'
        ? 'monthly'
        : frequencyFilter === 'Anual'
          ? 'annual'
          : undefined,
    status:
      statusFilter === 'Activo'
        ? 'active'
        : statusFilter === 'Suspendido'
          ? 'suspended'
          : statusFilter === 'Cancelado'
            ? 'cancelled'
            : undefined,
  })

  const issuerOptions = useMemo(
    () => [
      { value: 'Todos', label: 'Todos' },
      ...issuers.map((i) => ({ value: String(i.id), label: i.name })),
    ],
    [issuers],
  )

  const segmentSelectOptions = useMemo(() => {
    if (isSegmentLocked && lockedSegmentId) {
      const name = segments.find((s) => s.id === lockedSegmentId)?.name ?? 'Segmento'
      return [{ value: String(lockedSegmentId), label: name }]
    }
    return [
      { value: 'Todos', label: 'Todos' },
      ...segments.map((s) => ({ value: String(s.id), label: s.name })),
    ]
  }, [isSegmentLocked, lockedSegmentId, segments])

  const hasActiveFilters =
    search.trim() !== '' ||
    (!isSegmentLocked && segmentFilter !== 'Todos') ||
    issuerFilter !== 'Todos' ||
    frequencyFilter !== 'Todas' ||
    statusFilter !== 'Todos'

  const columns = useMemo<Array<ColumnDef<typeof contractsTableFeatures, ContractListItem>>>(
    () => [
      {
        accessorKey: 'folio',
        header: 'FOLIO',
        cell: ({ row }) => (
          <AppButton
            variant="ghost"
            className="h-auto rounded-lg px-1.5 py-0.5 font-mono text-sm font-semibold tabular-nums hover:bg-blue-500/10 hover:text-[#0066FF] dark:hover:text-[#0A84FF]"
            onClick={() => navigate(`/contratos/${row.original.id}`)}
          >
            {row.original.folio}
          </AppButton>
        ),
      },
      {
        id: 'client',
        header: 'CLIENTE',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium">{row.original.client_name}</p>
            <p className="font-mono text-xs tabular-nums text-slate-500 dark:text-zinc-400">
              {row.original.client_rfc}
            </p>
          </div>
        ),
      },
      {
        accessorKey: 'segment_name',
        header: 'SEGMENTO',
        cell: ({ row }) => (
          <AppBadge label={row.original.segment_name || '—'} tone="neutral" />
        ),
      },
      {
        accessorKey: 'issuer_name',
        header: 'EMISOR',
        cell: ({ row }) => (
          <span className="text-sm">{row.original.issuer_name || '—'}</span>
        ),
      },
      {
        accessorKey: 'billing_frequency',
        header: 'FRECUENCIA',
        cell: ({ row }) => (
          <AppBadge
            label={row.original.billing_frequency === 'annual' ? 'Anual' : 'Mensual'}
            tone="info"
          />
        ),
      },
      {
        accessorKey: 'mrr_mxn',
        header: 'MRR (MXN)',
        cell: ({ row }) => (
          <span className="block text-right font-mono text-sm font-medium tabular-nums">
            {formatCurrencyMx(row.original.mrr_mxn, { fractionDigits: 2 })}
          </span>
        ),
      },
      {
        id: 'next_cutoff',
        header: 'PRÓXIMO CORTE',
        cell: ({ row }) => {
          const date = row.original.next_billing_date ?? row.original.next_cutoff_date
          return (
            <span className="text-sm text-slate-600 dark:text-zinc-300">
              {date ? formatDateEsMx(date) : '—'}
            </span>
          )
        },
      },
      {
        accessorKey: 'status',
        header: 'ESTADO',
        cell: ({ row }) => (
          <AppBadge
            label={STATUS_LABEL[row.original.status] ?? row.original.status}
            tone={STATUS_TONE[row.original.status] ?? 'neutral'}
          />
        ),
      },
      {
        id: 'actions',
        header: () => <AppActionsColumnHeader />,
        cell: ({ row }) => (
          <div className="flex justify-end">
            <AppRowActionsMenu
              actions={[
                {
                  label: 'Ver detalle',
                  icon: <Eye className="h-4 w-4" />,
                  onClick: () => navigate(`/contratos/${row.original.id}`),
                },
                {
                  label: 'Ver cliente',
                  icon: <User className="h-4 w-4" />,
                  onClick: () => navigate(`/clientes?highlight=${row.original.client_id}`),
                },
              ]}
            />
          </div>
        ),
      },
    ],
    [navigate],
  )

  const table = useTable({
    features: contractsTableFeatures,
    data: items,
    columns,
  })

  const kpiCards = [
    { label: 'Total de contratos', value: String(summary.total_contracts) },
    { label: 'Contratos activos', value: String(summary.active_contracts) },
    {
      label: 'MRR activo (MXN)',
      value: formatCurrencyMx(summary.total_mrr_mxn, { fractionDigits: 2 }),
      mono: true,
    },
    { label: 'Contratos suspendidos', value: String(summary.suspended_contracts) },
  ]

  if (isApiError && !items.length) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error al cargar contratos</h2>
        <p className="mt-1 text-sm text-slate-500">{errorMessage}</p>
        <AppButton className="mt-4" onClick={() => void refetch()}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-semibold tracking-[-0.4px] md:text-3xl">
            Contratos
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
            Suscripciones activas, MRR congelado y próximos cortes
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {canEdit('contracts') ? (
            <AppButton variant="secondary" className="rounded-xl" onClick={() => setBulkOpen(true)}>
              <Percent className="mr-1 h-4 w-4" /> Ajuste masivo de precios
            </AppButton>
          ) : null}
          {canCreate('contracts') ? (
            <AppButton className="rounded-xl" onClick={() => navigate('/contratos/nuevo')}>
              <Plus className="mr-1 h-4 w-4" /> Nuevo contrato
            </AppButton>
          ) : null}
        </div>
      </header>

      {canEdit('contracts') ? (
        <BulkPriceAdjustmentModal
          open={bulkOpen}
          onOpenChange={setBulkOpen}
          segmentOptions={segments
            .filter((s) => !isSegmentLocked || s.id === lockedSegmentId)
            .map((s) => ({ value: String(s.id), label: s.name }))}
          lockedSegmentId={isSegmentLocked && lockedSegmentId ? lockedSegmentId : null}
          issuerOptions={issuers.map((i) => ({ value: String(i.id), label: i.name }))}
        />
      ) : null}

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {kpiCards.map((kpi) => (
          <AppCard key={kpi.label} className="pointer-events-none p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-zinc-400">
              {kpi.label}
            </p>
            <p
              className={cn(
                'mt-2 font-heading text-2xl font-semibold tracking-[-0.4px]',
                kpi.mono && 'font-mono tabular-nums',
              )}
            >
              {kpi.value}
            </p>
          </AppCard>
        ))}
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
        searchPlaceholder="Folio, cliente o RFC…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'segment',
            label: 'SEGMENTO',
            value: isSegmentLocked && lockedSegmentId ? String(lockedSegmentId) : segmentFilter,
            onValueChange: (value) => {
              setSegmentFilter(value)
              setPage(1)
            },
            options: segmentSelectOptions,
            disabled: isSegmentLocked,
          },
          {
            id: 'issuer',
            label: 'EMISOR',
            value: issuerFilter,
            onValueChange: (value) => {
              setIssuerFilter(value)
              setPage(1)
            },
            options: issuerOptions,
          },
          {
            id: 'frequency',
            label: 'FRECUENCIA',
            value: frequencyFilter,
            onValueChange: (value) => {
              setFrequencyFilter(value)
              setPage(1)
            },
            options: [
              { value: 'Todas', label: 'Todas' },
              { value: 'Mensual', label: 'Mensual' },
              { value: 'Anual', label: 'Anual' },
            ],
          },
          {
            id: 'status',
            label: 'ESTADO',
            value: statusFilter,
            onValueChange: (value) => {
              setStatusFilter(value)
              setPage(1)
            },
            options: [
              { value: 'Todos', label: 'Todos' },
              { value: 'Activo', label: 'Activo', tone: 'success' },
              { value: 'Suspendido', label: 'Suspendido', tone: 'warning' },
              { value: 'Cancelado', label: 'Cancelado', tone: 'danger' },
            ],
          },
        ]}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={() => {
          setSearch('')
          if (!isSegmentLocked) setSegmentFilter('Todos')
          setIssuerFilter('Todos')
          setFrequencyFilter('Todas')
          setStatusFilter('Todos')
          setPage(1)
        }}
      />

      <AppTableWrapper
        isLoading={isLoading}
        loadingState={
          <div className="space-y-3 p-4">
            <Skeleton className="h-10 w-full rounded-xl" />
            <Skeleton className="h-10 w-full rounded-xl" />
            <Skeleton className="h-48 w-full rounded-2xl" />
          </div>
        }
        isEmpty={!isLoading && items.length === 0}
        emptyState={
          <div className="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
            <FileText className="h-10 w-10 text-slate-300 dark:text-zinc-600" />
            <p className="font-heading text-lg font-semibold">Sin contratos para mostrar</p>
            <p className="max-w-md text-sm text-slate-500 dark:text-zinc-400">
              Ajusta los filtros o crea el primer contrato para impactar el MRR del mes.
            </p>
            {canCreate('contracts') ? (
              <AppButton className="mt-2 rounded-xl" onClick={() => navigate('/contratos/nuevo')}>
                <Plus className="mr-1 h-4 w-4" /> Nuevo contrato
              </AppButton>
            ) : null}
          </div>
        }
        footer={
          <PaginationBar
            page={page}
            pageSize={perPage}
            total={total}
            onPageChange={setPage}
            onPageSizeChange={(size) => {
              setPageSize(size)
              setPage(1)
            }}
          />
        }
      >
        <div className="hidden overflow-x-auto lg:block">
          <table className="w-full text-sm">
            <thead>
              {table.getHeaderGroups().map((hg) => (
                <AppTableHeadRow key={hg.id}>
                  {hg.headers.map((h) => (
                    <th
                      key={h.id}
                      className={cn(
                        rowPaddingClass,
                        'font-medium',
                        (h.column.id === 'actions' || h.column.id === 'mrr_mxn') && 'text-right',
                      )}
                    >
                      {h.isPlaceholder ? null : <table.FlexRender header={h} />}
                    </th>
                  ))}
                </AppTableHeadRow>
              ))}
            </thead>
            <tbody>
              {table.getRowModel().rows.map((row) => (
                <tr
                  key={row.id}
                  className="border-b border-slate-100 dark:border-white/[0.04]"
                >
                  {row.getAllCells().map((cell) => (
                    <td
                      key={cell.id}
                      className={cn(
                        rowPaddingClass,
                        (cell.column.id === 'actions' || cell.column.id === 'mrr_mxn') &&
                          'text-right',
                      )}
                    >
                      <table.FlexRender cell={cell} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="space-y-3 p-4 lg:hidden">
          {items.map((contract) => (
            <AppCard key={contract.id} className="p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <AppButton
                    variant="ghost"
                    className="h-auto rounded-lg px-0 py-0 font-mono text-sm font-semibold tabular-nums hover:text-[#0066FF]"
                    onClick={() => navigate(`/contratos/${contract.id}`)}
                  >
                    {contract.folio}
                  </AppButton>
                  <p className="mt-1 truncate font-medium">{contract.client_name}</p>
                  <p className="font-mono text-xs tabular-nums text-slate-500">
                    {contract.client_rfc}
                  </p>
                </div>
                <AppRowActionsMenu
                  actions={[
                    {
                      label: 'Ver detalle',
                      icon: <Eye className="h-4 w-4" />,
                      onClick: () => navigate(`/contratos/${contract.id}`),
                    },
                    {
                      label: 'Ver cliente',
                      icon: <User className="h-4 w-4" />,
                      onClick: () => navigate(`/clientes?highlight=${contract.client_id}`),
                    },
                  ]}
                />
              </div>
              <div className="mt-3 flex flex-wrap gap-2">
                <AppBadge label={contract.segment_name || '—'} tone="neutral" />
                <AppBadge
                  label={contract.billing_frequency === 'annual' ? 'Anual' : 'Mensual'}
                  tone="info"
                />
                <AppBadge
                  label={STATUS_LABEL[contract.status]}
                  tone={STATUS_TONE[contract.status]}
                />
              </div>
              <div className="mt-3 flex items-end justify-between text-sm">
                <span className="text-slate-500 dark:text-zinc-400">
                  {contract.next_billing_date
                    ? formatDateEsMx(contract.next_billing_date)
                    : 'Sin corte'}
                </span>
                <span className="font-mono font-semibold tabular-nums">
                  {formatCurrencyMx(contract.mrr_mxn, { fractionDigits: 2 })}
                </span>
              </div>
            </AppCard>
          ))}
        </div>
      </AppTableWrapper>

      {isFetching && !isLoading ? (
        <p className="text-center text-xs text-slate-400">Actualizando…</p>
      ) : null}
    </div>
  )
}
