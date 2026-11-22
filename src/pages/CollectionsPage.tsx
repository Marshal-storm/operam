import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'
import { useQuery } from '@tanstack/react-query'
import {
  AlertTriangle,
  Ban,
  CreditCard,
  Eye,
  FilePlus2,
  ShieldOff,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { InvoiceDetailModal } from '@/components/collections/InvoiceDetailModal'
import { NewInvoiceModal } from '@/components/collections/NewInvoiceModal'
import { RecordPaymentModal } from '@/components/collections/RecordPaymentModal'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalFooter,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import {
  useCollectionsContracts,
  useCollectionsInvoices,
} from '@/hooks/useCollections'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegments } from '@/hooks/useSegments'
import { cn, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import { fetchIssuers } from '@/services/catalogService'
import type {
  AgingBucketKey,
  ContractPortfolioItem,
  InvoiceListItem,
  InvoiceStatus,
} from '@/types/collection'

const tableFeats = tableFeatures({})

const STATUS_TONE: Record<
  InvoiceStatus,
  'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
  pending: 'warning',
  partial: 'info',
  paid: 'success',
  overdue: 'danger',
  cancelled: 'neutral',
}

const STATUS_LABEL: Record<InvoiceStatus, string> = {
  pending: 'Pendiente',
  partial: 'Parcial',
  paid: 'Pagado',
  overdue: 'Vencido',
  cancelled: 'Cancelado',
}

const NOTICE_TONE: Record<
  string,
  'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
  none: 'success',
  reminder: 'info',
  warning: 'warning',
  urgent: 'danger',
  suspension: 'danger',
}

type TabKey = 'invoices' | 'contracts'

/**
 * Cobranza y cartera — Módulo 4 Paso 2 (`/cobranza`).
 */
export function CollectionsPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const { canCreate, canEdit, canDelete } = usePermissions()
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const { segments, isSegmentLocked, lockedSegmentId } = useSegments()

  const [tab, setTab] = useState<TabKey>('invoices')
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('Todos')
  const [agingFilter, setAgingFilter] = useState('Todos')
  const [segmentFilter, setSegmentFilter] = useState(
    isSegmentLocked && lockedSegmentId ? String(lockedSegmentId) : 'Todos',
  )
  const [issuerFilter, setIssuerFilter] = useState('Todos')

  const [newOpen, setNewOpen] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailId, setDetailId] = useState<number | null>(null)
  const [payOpen, setPayOpen] = useState(false)
  const [payTarget, setPayTarget] = useState<InvoiceListItem | null>(null)
  const [cancelTarget, setCancelTarget] = useState<InvoiceListItem | null>(null)

  const issuersQuery = useQuery({
    queryKey: ['issuers', 'collections-filter'],
    queryFn: fetchIssuers,
    staleTime: 60_000,
  })

  const segmentIdParam = useMemo(() => {
    if (isSegmentLocked && lockedSegmentId) return lockedSegmentId
    if (segmentFilter === 'Todos') return undefined
    const byId = Number(segmentFilter)
    return Number.isFinite(byId) && byId > 0 ? byId : undefined
  }, [isSegmentLocked, lockedSegmentId, segmentFilter])

  const statusParam =
    statusFilter === 'Pendiente'
      ? 'pending'
      : statusFilter === 'Parcial'
        ? 'partial'
        : statusFilter === 'Pagado'
          ? 'paid'
          : statusFilter === 'Vencido'
            ? 'overdue'
            : statusFilter === 'Cancelado'
              ? 'cancelled'
              : undefined

  const agingParam =
    agingFilter === 'Por vencer'
      ? 'upcoming'
      : agingFilter === '1–30d'
        ? 'days_1_30'
        : agingFilter === '31–60d'
          ? 'days_31_60'
          : agingFilter === '61–90d'
            ? 'days_61_90'
            : agingFilter === 'Suspendidos +90d'
              ? 'suspended_90_plus'
              : undefined

  const issuerIdParam =
    issuerFilter === 'Todos' ? undefined : Number(issuerFilter) || undefined

  const {
    items,
    total,
    perPage,
    kpis,
    aging,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    issueInvoice,
    recordPayment,
    cancelInvoice,
    runSuspensionCheck,
    isIssuing,
    isPaying,
    isCancelling,
    isCheckingSuspension,
  } = useCollectionsInvoices({
    page,
    per_page: preferredSize,
    search: search.trim() || undefined,
    status: statusParam as InvoiceStatus | undefined,
    aging_bucket: agingParam as AgingBucketKey | undefined,
    segment_id: segmentIdParam,
    issuer_id: issuerIdParam,
  })

  const contractsQuery = useCollectionsContracts({
    page,
    per_page: preferredSize,
    search: search.trim() || undefined,
    segment_id: segmentIdParam,
  })

  const canEditCollections = canEdit('collections')
  const canCreateCollections = canCreate('collections')
  const canDeleteCollections = canDelete('collections')

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

  const issuerOptions = useMemo(
    () => [
      { value: 'Todos', label: 'Todos' },
      ...(issuersQuery.data ?? []).map((i) => ({
        value: String(i.id),
        label: i.name,
      })),
    ],
    [issuersQuery.data],
  )

  const hasActiveFilters =
    search.trim() !== '' ||
    statusFilter !== 'Todos' ||
    agingFilter !== 'Todos' ||
    issuerFilter !== 'Todos' ||
    (!isSegmentLocked && segmentFilter !== 'Todos')

  const clearFilters = () => {
    setSearch('')
    setStatusFilter('Todos')
    setAgingFilter('Todos')
    setIssuerFilter('Todos')
    if (!isSegmentLocked) setSegmentFilter('Todos')
    setPage(1)
  }

  const columns = useMemo<Array<ColumnDef<typeof tableFeats, InvoiceListItem>>>(
    () => [
      {
        accessorKey: 'folio',
        header: 'FOLIO',
        cell: ({ row }) => (
          <div className="flex flex-wrap items-center gap-1.5">
            <AppButton
              variant="ghost"
              className="h-auto rounded-lg px-1.5 py-0.5 font-mono text-sm font-semibold tabular-nums"
              onClick={() => {
                setDetailId(row.original.id)
                setDetailOpen(true)
              }}
            >
              {row.original.folio}
            </AppButton>
            {row.original.request_folio ? (
              <AppBadge
                label={row.original.request_folio}
                tone="info"
                className="whitespace-nowrap shrink-0"
              />
            ) : null}
          </div>
        ),
      },
      {
        id: 'client_contract',
        header: 'CLIENTE / CONTRATO',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium">{row.original.client_name}</p>
            <p className="font-mono text-xs tabular-nums text-slate-500 dark:text-zinc-400">
              {row.original.contract_folio}
            </p>
          </div>
        ),
      },
      {
        accessorKey: 'concept',
        header: 'CONCEPTO',
        cell: ({ row }) => (
          <p className="max-w-[220px] truncate text-sm">{row.original.concept}</p>
        ),
      },
      {
        id: 'due',
        header: 'VENCIMIENTO',
        cell: ({ row }) => (
          <div>
            <p className="whitespace-nowrap text-sm">{formatDateEsMx(row.original.due_date)}</p>
            {row.original.days_overdue > 0 ? (
              <p className="text-xs font-medium text-red-500">
                +{row.original.days_overdue}d vencido
              </p>
            ) : null}
          </div>
        ),
      },
      {
        id: 'amounts',
        header: 'TOTAL / SALDO',
        cell: ({ row }) => {
          const inv = row.original
          if (inv.status === 'cancelled') {
            return (
              <div className="text-right">
                <p className="font-mono text-sm tabular-nums text-slate-400 line-through">
                  {formatCurrencyMx(inv.total, { fractionDigits: 2 })}
                </p>
                <p className="text-xs font-medium text-slate-500 dark:text-zinc-400">
                  Cancelado · Saldo $0.00
                </p>
              </div>
            )
          }
          return (
            <div className="text-right">
              <p className="font-mono text-sm tabular-nums">
                {formatCurrencyMx(inv.total, { fractionDigits: 2 })}
              </p>
              <p className="font-mono text-xs tabular-nums text-slate-500 dark:text-zinc-400">
                Saldo {formatCurrencyMx(inv.balance, { fractionDigits: 2 })}
              </p>
            </div>
          )
        },
      },
      {
        accessorKey: 'status',
        header: 'ESTADO',
        cell: ({ row }) => (
          <AppBadge
            label={STATUS_LABEL[row.original.status]}
            tone={STATUS_TONE[row.original.status]}
            className="whitespace-nowrap shrink-0"
          />
        ),
      },
      {
        id: 'actions',
        header: () => <AppActionsColumnHeader />,
        cell: ({ row }) => {
          const inv = row.original
          const hasBalance = Number(inv.balance) > 0 && inv.status !== 'cancelled'
          const canCancelDoc =
            Number(inv.paid_amount) === 0 &&
            inv.status !== 'cancelled' &&
            canDeleteCollections
          return (
            <div className="flex justify-end">
              <AppRowActionsMenu
                actions={[
                  {
                    label: 'Ver detalle y pagos',
                    icon: <Eye className="h-4 w-4" />,
                    onClick: () => {
                      setDetailId(inv.id)
                      setDetailOpen(true)
                    },
                  },
                  {
                    label: 'Registrar pago',
                    icon: <CreditCard className="h-4 w-4" />,
                    onClick: () => {
                      setPayTarget(inv)
                      setPayOpen(true)
                    },
                    hidden: !canEditCollections || !hasBalance,
                  },
                  {
                    label: 'Cancelar documento',
                    icon: <Ban className="h-4 w-4" />,
                    onClick: () => setCancelTarget(inv),
                    variant: 'danger',
                    separatorBefore: true,
                    hidden: !canCancelDoc,
                  },
                ]}
              />
            </div>
          )
        },
      },
    ],
    [canEditCollections, canDeleteCollections],
  )

  const table = useTable({
    features: tableFeats,
    data: items,
    columns,
  })

  const contractColumns = useMemo<
    Array<ColumnDef<typeof tableFeats, ContractPortfolioItem>>
  >(
    () => [
      {
        accessorKey: 'contract_folio',
        header: 'CONTRATO',
        cell: ({ row }) => (
          <Link
            to={`/contratos/${row.original.contract_id}`}
            className="font-mono text-sm font-semibold tabular-nums text-[#0066FF] hover:underline dark:text-[#0A84FF]"
          >
            {row.original.contract_folio}
          </Link>
        ),
      },
      {
        accessorKey: 'client_name',
        header: 'CLIENTE',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium">{row.original.client_name}</p>
            {row.original.exclude_suspension ? (
              <AppBadge
                label="Protegido (excluido)"
                tone="info"
                className="mt-1 whitespace-nowrap shrink-0"
              />
            ) : null}
          </div>
        ),
      },
      {
        id: 'balances',
        header: 'SALDOS',
        cell: ({ row }) => (
          <div className="text-right">
            <p className="font-mono text-sm tabular-nums">
              Vigente {formatCurrencyMx(row.original.upcoming_balance, { fractionDigits: 2 })}
            </p>
            <p className="font-mono text-xs tabular-nums text-red-500">
              Vencido {formatCurrencyMx(row.original.overdue_balance, { fractionDigits: 2 })}
            </p>
          </div>
        ),
      },
      {
        accessorKey: 'days_overdue',
        header: 'DÍAS ATRASO',
        cell: ({ row }) => (
          <span
            className={cn(
              'font-mono tabular-nums',
              row.original.days_overdue > 0 ? 'text-red-500' : 'text-slate-500',
            )}
          >
            {row.original.days_overdue}
          </span>
        ),
      },
      {
        id: 'notice',
        header: 'NIVEL AVISO',
        cell: ({ row }) => (
          <AppBadge
            label={row.original.notice_label}
            tone={NOTICE_TONE[row.original.notice_code] ?? 'neutral'}
            className="whitespace-nowrap shrink-0"
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
                  label: 'Ver contrato',
                  icon: <Eye className="h-4 w-4" />,
                  onClick: () => navigate(`/contratos/${row.original.contract_id}`),
                },
              ]}
            />
          </div>
        ),
      },
    ],
    [navigate],
  )

  const contractsTable = useTable({
    features: tableFeats,
    data: contractsQuery.items,
    columns: contractColumns,
  })

  const kpiCards = [
    {
      label: 'Por vencer (MXN)',
      value: formatCurrencyMx(kpis?.upcoming_total_mxn ?? '0', { fractionDigits: 2 }),
    },
    {
      label: 'Cartera vencida (MXN)',
      value: formatCurrencyMx(kpis?.overdue_total_mxn ?? '0', { fractionDigits: 2 }),
    },
    {
      label: 'Contratos +60 días',
      value: String(kpis?.contracts_over_60_days ?? 0),
    },
    {
      label: 'Suspendidos (+90 días)',
      value: String(kpis?.suspended_contracts_count ?? 0),
    },
  ]

  const activeListLoading = tab === 'invoices' ? isLoading : contractsQuery.isLoading
  const activeError = tab === 'invoices' ? isApiError : contractsQuery.isApiError
  const activeErrorMsg = tab === 'invoices' ? errorMessage : contractsQuery.errorMessage
  const activeRefetch = tab === 'invoices' ? refetch : contractsQuery.refetch
  const activeItemsLen = tab === 'invoices' ? items.length : contractsQuery.items.length

  if (activeError && !activeItemsLen) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error al cargar cobranza</h2>
        <p className="mt-1 text-sm text-slate-500">{activeErrorMsg}</p>
        <AppButton className="mt-4" onClick={() => void activeRefetch()}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-bold tracking-tight text-[#090D16] dark:text-white md:text-3xl">
            Cobranza y cartera
          </h1>
          <p className="mt-1 max-w-2xl text-sm text-slate-500 dark:text-zinc-400">
            Documentos emitidos, registro de pagos, antigüedad de saldos y suspensiones (+90 días)
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {canEditCollections ? (
            <AppButton
              variant="secondary"
              isLoading={isCheckingSuspension}
              onClick={async () => {
                try {
                  const result = await runSuspensionCheck()
                  toast.success(
                    `Suspensiones: ${result.suspended_count} · Excluidos: ${result.excluded_count}`,
                  )
                } catch (error) {
                  toast.apiError(error)
                }
              }}
            >
              <AlertTriangle className="h-4 w-4" />
              Evaluar suspensiones (+90d)
            </AppButton>
          ) : null}
          {canCreateCollections ? (
            <AppButton variant="primary" onClick={() => setNewOpen(true)}>
              <FilePlus2 className="h-4 w-4" />
              + Emitir documento
            </AppButton>
          ) : null}
        </div>
      </header>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {kpiCards.map((card) => (
          <AppCard
            key={card.label}
            className="pointer-events-none select-none p-4"
            aria-hidden={false}
          >
            <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-400">
              {card.label}
            </p>
            <p className="mt-2 font-mono text-2xl font-semibold tabular-nums text-[#090D16] dark:text-white">
              {activeListLoading && tab === 'invoices' ? '—' : card.value}
            </p>
          </AppCard>
        ))}
      </div>

      <AppCard className="p-4 md:p-5">
        <h2 className="mb-3 text-sm font-semibold">Antigüedad de cartera</h2>
        <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
          {(aging.length > 0
            ? aging
            : [
                { key: 'upcoming', label: 'Por vencer', amount_mxn: '0.00', color: '#10B981' },
                { key: 'days_1_30', label: '1-30 días', amount_mxn: '0.00', color: '#F59E0B' },
                { key: 'days_31_60', label: '31-60 días', amount_mxn: '0.00', color: '#F97316' },
                { key: 'days_61_90', label: '61-90 días', amount_mxn: '0.00', color: '#EF4444' },
                {
                  key: 'suspended_90_plus',
                  label: 'Suspendidos (+90)',
                  amount_mxn: '0.00',
                  color: '#7F1D1D',
                },
              ]
          ).map((bucket) => (
            <div
              key={bucket.key}
              className="rounded-2xl border border-slate-200/80 px-3 py-2.5 dark:border-white/[0.08]"
            >
              <div className="mb-1.5 flex items-center gap-1.5">
                <span
                  className="h-2 w-2 rounded-full"
                  style={{ backgroundColor: bucket.color, boxShadow: `0 0 8px ${bucket.color}66` }}
                />
                <span className="text-[11px] font-medium text-slate-600 dark:text-zinc-300">
                  {bucket.label}
                </span>
              </div>
              <p className="font-mono text-sm font-semibold tabular-nums">
                {formatCurrencyMx(bucket.amount_mxn, { fractionDigits: 2 })}
              </p>
            </div>
          ))}
        </div>
      </AppCard>

      <div className="flex gap-2 rounded-full border border-slate-200/80 bg-white/70 p-1 dark:border-white/[0.08] dark:bg-white/[0.03]">
        <AppButton
          variant={tab === 'invoices' ? 'primary' : 'ghost'}
          className="rounded-full"
          onClick={() => {
            setTab('invoices')
            setPage(1)
          }}
        >
          Documentos / Facturas
        </AppButton>
        <AppButton
          variant={tab === 'contracts' ? 'primary' : 'ghost'}
          className="rounded-full"
          onClick={() => {
            setTab('contracts')
            setPage(1)
          }}
        >
          Cartera por contrato
        </AppButton>
      </div>

      {tab === 'invoices' ? (
        <AppFilterBar
          searchValue={search}
          onSearchChange={(v) => {
            setSearch(v)
            setPage(1)
          }}
          onSearchClear={() => {
            setSearch('')
            setPage(1)
          }}
          searchPlaceholder="Buscar folio, cliente, concepto…"
          hasActiveFilters={hasActiveFilters}
          onClearFilters={clearFilters}
          filters={[
            {
              id: 'status',
              label: 'Estado',
              value: statusFilter,
              onValueChange: (v) => {
                setStatusFilter(v)
                setPage(1)
              },
              options: [
                { value: 'Todos', label: 'Todos' },
                { value: 'Pendiente', label: 'Pendiente' },
                { value: 'Parcial', label: 'Parcial' },
                { value: 'Pagado', label: 'Pagado' },
                { value: 'Vencido', label: 'Vencido' },
                { value: 'Cancelado', label: 'Cancelado' },
              ],
            },
            {
              id: 'aging',
              label: 'Antigüedad',
              value: agingFilter,
              onValueChange: (v) => {
                setAgingFilter(v)
                setPage(1)
              },
              options: [
                { value: 'Todos', label: 'Todos' },
                { value: 'Por vencer', label: 'Por vencer' },
                { value: '1–30d', label: '1–30d' },
                { value: '31–60d', label: '31–60d' },
                { value: '61–90d', label: '61–90d' },
                { value: 'Suspendidos +90d', label: 'Suspendidos +90d' },
              ],
            },
            {
              id: 'segment',
              label: 'Segmento',
              value: segmentFilter,
              onValueChange: (v) => {
                setSegmentFilter(v)
                setPage(1)
              },
              options: segmentSelectOptions,
              disabled: isSegmentLocked,
            },
            {
              id: 'issuer',
              label: 'Emisor',
              value: issuerFilter,
              onValueChange: (v) => {
                setIssuerFilter(v)
                setPage(1)
              },
              options: issuerOptions,
            },
          ]}
        />
      ) : (
        <AppFilterBar
          searchValue={search}
          onSearchChange={(v) => {
            setSearch(v)
            setPage(1)
          }}
          onSearchClear={() => {
            setSearch('')
            setPage(1)
          }}
          searchPlaceholder="Buscar contrato, cliente, RFC…"
          hasActiveFilters={
            search.trim() !== '' || (!isSegmentLocked && segmentFilter !== 'Todos')
          }
          onClearFilters={() => {
            setSearch('')
            if (!isSegmentLocked) setSegmentFilter('Todos')
            setPage(1)
          }}
          filters={[
            {
              id: 'segment',
              label: 'Segmento',
              value: segmentFilter,
              onValueChange: (v) => {
                setSegmentFilter(v)
                setPage(1)
              },
              options: segmentSelectOptions,
              disabled: isSegmentLocked,
            },
          ]}
        />
      )}

      {tab === 'invoices' ? (
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
              <ShieldOff className="h-10 w-10 text-slate-300 dark:text-zinc-600" />
              <p className="font-heading text-lg font-semibold">Sin documentos</p>
              <p className="max-w-md text-sm text-slate-500 dark:text-zinc-400">
                Emite un documento o aprueba una solicitud one_time/consumption.
              </p>
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
                          (h.column.id === 'actions' || h.column.id === 'amounts') &&
                            'text-right',
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
                      <td key={cell.id} className={cn(rowPaddingClass, 'align-middle')}>
                        <table.FlexRender cell={cell} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="space-y-3 p-3 lg:hidden">
            {items.map((inv) => (
              <AppCard key={inv.id} className="p-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-mono text-sm font-semibold tabular-nums">{inv.folio}</p>
                    <p className="mt-0.5 font-medium">{inv.client_name}</p>
                    <p className="font-mono text-xs tabular-nums text-slate-500">
                      {inv.contract_folio}
                    </p>
                  </div>
                  <AppBadge
                    label={STATUS_LABEL[inv.status]}
                    tone={STATUS_TONE[inv.status]}
                    className="whitespace-nowrap shrink-0"
                  />
                </div>
                <p className="mt-2 truncate text-sm text-slate-600 dark:text-zinc-300">
                  {inv.concept}
                </p>
                <div className="mt-3 flex items-end justify-between text-sm">
                  <span>{formatDateEsMx(inv.due_date)}</span>
                  <span className="font-mono tabular-nums">
                    {formatCurrencyMx(inv.balance, { fractionDigits: 2 })}
                  </span>
                </div>
              </AppCard>
            ))}
          </div>
        </AppTableWrapper>
      ) : (
        <AppTableWrapper
          isLoading={contractsQuery.isLoading}
          loadingState={
            <div className="space-y-3 p-4">
              <Skeleton className="h-10 w-full rounded-xl" />
              <Skeleton className="h-10 w-full rounded-xl" />
              <Skeleton className="h-48 w-full rounded-2xl" />
            </div>
          }
          isEmpty={!contractsQuery.isLoading && contractsQuery.items.length === 0}
          emptyState={
            <div className="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
              <ShieldOff className="h-10 w-10 text-slate-300 dark:text-zinc-600" />
              <p className="font-heading text-lg font-semibold">Sin cartera</p>
              <p className="max-w-md text-sm text-slate-500 dark:text-zinc-400">
                No hay contratos activos/suspendidos con el filtro actual.
              </p>
            </div>
          }
          footer={
            <PaginationBar
              page={page}
              pageSize={contractsQuery.perPage}
              total={contractsQuery.total}
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
                {contractsTable.getHeaderGroups().map((hg) => (
                  <AppTableHeadRow key={hg.id}>
                    {hg.headers.map((h) => (
                      <th
                        key={h.id}
                        className={cn(
                          rowPaddingClass,
                          'font-medium',
                          (h.column.id === 'actions' || h.column.id === 'balances') &&
                            'text-right',
                        )}
                      >
                        {h.isPlaceholder ? null : <contractsTable.FlexRender header={h} />}
                      </th>
                    ))}
                  </AppTableHeadRow>
                ))}
              </thead>
              <tbody>
                {contractsTable.getRowModel().rows.map((row) => (
                  <tr
                    key={row.id}
                    className="border-b border-slate-100 dark:border-white/[0.04]"
                  >
                    {row.getAllCells().map((cell) => (
                      <td key={cell.id} className={cn(rowPaddingClass, 'align-middle')}>
                        <contractsTable.FlexRender cell={cell} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="space-y-3 p-3 lg:hidden">
            {contractsQuery.items.map((c) => (
              <AppCard key={c.contract_id} className="p-4">
                <div className="flex justify-between gap-2">
                  <Link
                    to={`/contratos/${c.contract_id}`}
                    className="font-mono font-semibold text-[#0066FF] dark:text-[#0A84FF]"
                  >
                    {c.contract_folio}
                  </Link>
                  <AppBadge
                    label={c.notice_label}
                    tone={NOTICE_TONE[c.notice_code] ?? 'neutral'}
                    className="whitespace-nowrap shrink-0"
                  />
                </div>
                <p className="mt-1 text-sm">{c.client_name}</p>
                <p className="mt-2 font-mono text-sm tabular-nums text-red-500">
                  Vencido {formatCurrencyMx(c.overdue_balance, { fractionDigits: 2 })} ·{' '}
                  {c.days_overdue}d
                </p>
              </AppCard>
            ))}
          </div>
        </AppTableWrapper>
      )}

      <NewInvoiceModal
        open={newOpen}
        onOpenChange={setNewOpen}
        isSubmitting={isIssuing}
        onSubmit={async (data) => {
          try {
            const created = await issueInvoice(data)
            toast.success(`Documento ${created.folio} emitido`)
            setNewOpen(false)
            setPage(1)
          } catch (error) {
            toast.apiError(error)
          }
        }}
      />

      <InvoiceDetailModal
        open={detailOpen}
        invoiceId={detailId}
        onOpenChange={setDetailOpen}
        canRecordPayment={canEditCollections}
        onRecordPayment={() => {
          const found = items.find((i) => i.id === detailId)
          if (found) {
            setPayTarget(found)
            setPayOpen(true)
          }
        }}
      />

      <RecordPaymentModal
        open={payOpen}
        invoice={payTarget}
        onOpenChange={setPayOpen}
        isSubmitting={isPaying}
        onSubmit={async (data) => {
          if (!payTarget) return
          try {
            const updated = await recordPayment(payTarget.id, data)
            toast.success(`Pago registrado en ${updated.folio}`)
            setPayOpen(false)
            setPayTarget(null)
          } catch (error) {
            toast.apiError(error)
          }
        }}
      />

      <AppModal open={cancelTarget !== null} onOpenChange={(open) => !open && setCancelTarget(null)}>
        <AppModalContent className="max-w-md">
          <AppModalHeader>
            <AppModalTitle>Cancelar documento</AppModalTitle>
            <AppModalDescription>
              ¿Cancelar {cancelTarget?.folio}? Solo documentos sin pagos aplicados.
            </AppModalDescription>
          </AppModalHeader>
          <AppModalFooter>
            <AppButton
              variant="ghost"
              disabled={isCancelling}
              onClick={() => setCancelTarget(null)}
            >
              No
            </AppButton>
            <AppButton
              variant="danger"
              isLoading={isCancelling}
              onClick={async () => {
                if (!cancelTarget) return
                try {
                  await cancelInvoice(cancelTarget.id)
                  toast.success(`Documento ${cancelTarget.folio} cancelado`)
                  setCancelTarget(null)
                } catch (error) {
                  toast.apiError(error)
                }
              }}
            >
              Cancelar documento
            </AppButton>
          </AppModalFooter>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
