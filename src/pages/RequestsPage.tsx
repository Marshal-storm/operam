import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'
import {
  Ban,
  CheckCircle2,
  ClipboardList,
  Eye,
  FilePlus2,
  Plus,
  Quote,
  XCircle,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import { NewRequestModal } from '@/components/requests/NewRequestModal'
import {
  RequestDetailModal,
  type RequestDetailMode,
} from '@/components/requests/RequestDetailModal'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppTextarea } from '@/components/ui/AppInput'
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
import { usePermissions } from '@/hooks/usePermissions'
import { useRequests } from '@/hooks/useRequests'
import { useSegments } from '@/hooks/useSegments'
import { PORTAL_REQUEST_INTENT_LABEL, SERVICE_REQUEST_TYPE_LABEL } from '@/lib/requestLabels'
import { cn, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import type {
  ServiceRequestListItem,
  ServiceRequestStatus,
  ServiceRequestType,
} from '@/types/request'

const requestsTableFeatures = tableFeatures({})

const STATUS_TONE: Record<
  ServiceRequestStatus,
  'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
  pending: 'warning',
  quoted: 'info',
  approved: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
}

const STATUS_LABEL: Record<ServiceRequestStatus, string> = {
  pending: 'Pendiente',
  quoted: 'Cotizada',
  approved: 'Aprobada',
  rejected: 'Rechazada',
  cancelled: 'Cancelada',
}

const TYPE_LABEL: Record<ServiceRequestType, string> = SERVICE_REQUEST_TYPE_LABEL

/**
 * Bandeja de Solicitudes — Módulo 4 Paso 1 (`/solicitudes`).
 */
export function RequestsPage() {
  const toast = useToast()
  const { canCreate, canEdit, canDelete } = usePermissions()
  const canCreateCollections = canCreate('collections')
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const { segments, isSegmentLocked, lockedSegmentId } = useSegments()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('Todos')
  const [typeFilter, setTypeFilter] = useState('Todos')
  const [segmentFilter, setSegmentFilter] = useState(
    isSegmentLocked && lockedSegmentId ? String(lockedSegmentId) : 'Todos',
  )

  const [newOpen, setNewOpen] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailId, setDetailId] = useState<number | null>(null)
  const [detailMode, setDetailMode] = useState<RequestDetailMode>('view')
  const [cancelTarget, setCancelTarget] = useState<ServiceRequestListItem | null>(null)
  const [cancelNotes, setCancelNotes] = useState('')
  const [cancelSubmitting, setCancelSubmitting] = useState(false)

  const segmentIdParam = useMemo(() => {
    if (isSegmentLocked && lockedSegmentId) return lockedSegmentId
    if (segmentFilter === 'Todos') return undefined
    const byId = Number(segmentFilter)
    return Number.isFinite(byId) && byId > 0 ? byId : undefined
  }, [isSegmentLocked, lockedSegmentId, segmentFilter])

  const statusParam =
    statusFilter === 'Pendiente'
      ? 'pending'
      : statusFilter === 'Cotizada'
        ? 'quoted'
        : statusFilter === 'Aprobada'
          ? 'approved'
          : statusFilter === 'Rechazada'
            ? 'rejected'
            : statusFilter === 'Cancelada'
              ? 'cancelled'
              : undefined

  const typeParam =
    typeFilter === 'Pago único'
      ? 'one_time'
      : typeFilter === 'Consumo'
        ? 'consumption'
        : typeFilter === 'Expansión recurrente'
          ? 'recurring_expansion'
          : typeFilter === 'Cambio de plan'
            ? 'plan_change'
            : undefined

  const {
    items,
    total,
    perPage,
    kpis,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createRequest,
    quoteRequest,
    approveRequest,
    rejectRequest,
    cancelRequest,
    generateInvoiceFromRequest,
  } = useRequests({
    page,
    per_page: preferredSize,
    search: search.trim() || undefined,
    status: statusParam,
    request_type: typeParam,
    segment_id: segmentIdParam,
  })

  const canEditRequests = canEdit('requests')
  const canCreateRequests = canCreate('requests')
  const canDeleteRequests = canDelete('requests')

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
    statusFilter !== 'Todos' ||
    typeFilter !== 'Todos' ||
    (!isSegmentLocked && segmentFilter !== 'Todos')

  const openDetail = (id: number, mode: RequestDetailMode) => {
    setDetailId(id)
    setDetailMode(mode)
    setDetailOpen(true)
  }

  const columns = useMemo<Array<ColumnDef<typeof requestsTableFeatures, ServiceRequestListItem>>>(
    () => [
      {
        accessorKey: 'folio',
        header: 'FOLIO',
        cell: ({ row }) => (
          <div className="flex flex-wrap items-center gap-1.5">
            <AppButton
              variant="ghost"
              className="h-auto rounded-lg px-1.5 py-0.5 font-mono text-sm font-semibold tabular-nums hover:bg-blue-500/10 hover:text-[#0066FF] dark:hover:text-[#0A84FF]"
              onClick={() => openDetail(row.original.id, 'view')}
            >
              {row.original.folio}
            </AppButton>
            {row.original.invoice_folio ? (
              <AppBadge
                label={row.original.invoice_folio}
                variant="neutral"
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
            <div className="flex flex-wrap items-center gap-1.5">
              <p className="truncate font-medium">{row.original.client_name}</p>
              {!row.original.contract_is_current ? (
                <AppBadge label="Adeudo" tone="warning" />
              ) : null}
            </div>
            <p className="font-mono text-xs tabular-nums text-slate-500 dark:text-zinc-400">
              {row.original.contract_folio}
              {row.original.client_rfc ? ` · ${row.original.client_rfc}` : ''}
            </p>
          </div>
        ),
      },
      {
        id: 'concept',
        header: 'CONCEPTO / SERVICIOS',
        cell: ({ row }) => (
          <div className="min-w-0 max-w-[240px]">
            <p className="truncate text-sm">
              {row.original.notes || row.original.services_summary || '—'}
            </p>
            {row.original.notes && row.original.services_summary ? (
              <p className="truncate text-xs text-slate-500">
                {row.original.services_summary}
              </p>
            ) : null}
          </div>
        ),
      },
      {
        accessorKey: 'request_type',
        header: 'TIPO',
        cell: ({ row }) => (
          <div className="flex flex-wrap gap-1.5">
            <AppBadge label={TYPE_LABEL[row.original.request_type]} tone="info" />
            {row.original.origin === 'portal' ? (
              <AppBadge
                label={
                  row.original.portal_request_type
                    ? `Portal · ${PORTAL_REQUEST_INTENT_LABEL[row.original.portal_request_type]}`
                    : 'Portal'
                }
                tone="neutral"
              />
            ) : null}
          </div>
        ),
      },
      {
        id: 'amount',
        header: 'MONTO / IMPACTO MRR',
        cell: ({ row }) => {
          const noMrr = row.original.request_type !== 'recurring_expansion'
          return (
            <div className="text-right">
              <p className="font-mono text-sm font-medium tabular-nums">
                {formatCurrencyMx(row.original.subtotal, { fractionDigits: 2 })}
              </p>
              <p className="text-xs text-slate-500 dark:text-zinc-400">
                {noMrr
                  ? 'No suma al MRR'
                  : `+${formatCurrencyMx(row.original.mrr_impact_mxn, { fractionDigits: 2 })} / mes`}
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
          />
        ),
      },
      {
        id: 'fecha',
        header: 'FECHA',
        cell: ({ row }) => (
          <span className="whitespace-nowrap text-sm text-slate-600 dark:text-zinc-300">
            {formatDateEsMx(row.original.created_at)}
          </span>
        ),
      },
      {
        id: 'actions',
        header: () => <AppActionsColumnHeader />,
        cell: ({ row }) => {
          const r = row.original
          const actionable = r.status === 'pending' || r.status === 'quoted'
          const canBill =
            r.status === 'approved' &&
            (r.request_type === 'one_time' || r.request_type === 'consumption') &&
            !r.invoice_id &&
            canCreateCollections
          return (
            <div className="flex justify-end">
              <AppRowActionsMenu
                actions={[
                  {
                    label: 'Ver detalle',
                    icon: <Eye className="h-4 w-4" />,
                    onClick: () => openDetail(r.id, 'view'),
                  },
                  {
                    label: 'Cotizar',
                    icon: <Quote className="h-4 w-4" />,
                    onClick: () => openDetail(r.id, 'quote'),
                    hidden: !canEditRequests || !actionable,
                  },
                  {
                    label: 'Aprobar',
                    icon: <CheckCircle2 className="h-4 w-4" />,
                    onClick: () => openDetail(r.id, 'approve'),
                    hidden: !canEditRequests || !actionable,
                  },
                  {
                    label: 'Rechazar',
                    icon: <XCircle className="h-4 w-4" />,
                    onClick: () => openDetail(r.id, 'reject'),
                    variant: 'danger',
                    hidden: !canEditRequests || !actionable,
                  },
                  {
                    label: 'Generar factura en cobranza',
                    icon: <FilePlus2 className="h-4 w-4" />,
                    onClick: async () => {
                      try {
                        const inv = await generateInvoiceFromRequest(r.id)
                        toast.success(`Documento ${inv.folio} generado`, r.folio)
                      } catch (error) {
                        toast.apiError(error)
                      }
                    },
                    hidden: !canBill,
                  },
                  {
                    label: 'Cancelar solicitud',
                    icon: <Ban className="h-4 w-4" />,
                    onClick: () => {
                      setCancelTarget(r)
                      setCancelNotes('')
                    },
                    variant: 'danger',
                    separatorBefore: true,
                    hidden: !canDeleteRequests || !actionable,
                  },
                ]}
              />
            </div>
          )
        },
      },
    ],
    [
      canEditRequests,
      canDeleteRequests,
      canCreateCollections,
      generateInvoiceFromRequest,
      toast,
    ],
  )

  const table = useTable({
    features: requestsTableFeatures,
    data: items,
    columns,
  })

  const kpiCards = [
    { label: 'Pendientes de atención', value: String(kpis.pending_count), mono: true },
    { label: 'Cotizadas', value: String(kpis.quoted_count), mono: true },
    { label: 'Aprobadas este mes', value: String(kpis.approved_month_count), mono: true },
    {
      label: 'Cargos únicos aprobados (MXN)',
      value: formatCurrencyMx(kpis.approved_one_time_total_mxn, { fractionDigits: 2 }),
      mono: true,
    },
  ]

  if (isApiError && !items.length) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error al cargar solicitudes</h2>
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
            Solicitudes
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
            Pedidos de horas de desarrollo, timbres, compras sueltas y ampliaciones
          </p>
        </div>
        {canCreateRequests ? (
          <AppButton className="rounded-xl" onClick={() => setNewOpen(true)}>
            <Plus className="mr-1 h-4 w-4" /> Nueva solicitud
          </AppButton>
        ) : null}
      </header>

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
              {isLoading ? '—' : kpi.value}
            </p>
          </AppCard>
        ))}
      </div>

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
        searchPlaceholder="Buscar por folio, cliente, RFC o descripción…"
        hasActiveFilters={hasActiveFilters}
        onClearFilters={() => {
          setSearch('')
          setStatusFilter('Todos')
          setTypeFilter('Todos')
          if (!isSegmentLocked) setSegmentFilter('Todos')
          setPage(1)
        }}
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
              { value: 'Cotizada', label: 'Cotizada' },
              { value: 'Aprobada', label: 'Aprobada' },
              { value: 'Rechazada', label: 'Rechazada' },
              { value: 'Cancelada', label: 'Cancelada' },
            ],
          },
          {
            id: 'type',
            label: 'Tipo',
            value: typeFilter,
            onValueChange: (v) => {
              setTypeFilter(v)
              setPage(1)
            },
            options: [
              { value: 'Todos', label: 'Todos' },
              { value: 'Pago único', label: 'Pago único' },
              { value: 'Consumo', label: 'Consumo' },
              { value: 'Expansión recurrente', label: 'Expansión recurrente' },
              { value: 'Cambio de plan', label: 'Cambio de plan' },
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
        ]}
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
            <ClipboardList className="h-10 w-10 text-slate-300 dark:text-zinc-600" />
            <p className="font-heading text-lg font-semibold">Sin solicitudes</p>
            <p className="max-w-md text-sm text-slate-500 dark:text-zinc-400">
              No hay solicitudes con los filtros actuales. Crea un pedido de horas, timbres o
              ampliación.
            </p>
            {canCreateRequests ? (
              <AppButton className="mt-2 rounded-xl" onClick={() => setNewOpen(true)}>
                <Plus className="mr-1 h-4 w-4" /> Nueva solicitud
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
                        (h.column.id === 'actions' || h.column.id === 'amount') && 'text-right',
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
          {items.map((r) => {
            const actionable = r.status === 'pending' || r.status === 'quoted'
            const canBill =
              r.status === 'approved' &&
              (r.request_type === 'one_time' || r.request_type === 'consumption') &&
              !r.invoice_id &&
              canCreateCollections
            return (
              <AppCard key={r.id} className="p-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <div className="flex flex-wrap items-center gap-1.5">
                      <p className="font-mono text-sm font-semibold tabular-nums">{r.folio}</p>
                      {r.invoice_folio ? (
                        <AppBadge
                          label={r.invoice_folio}
                          variant="neutral"
                          className="whitespace-nowrap shrink-0"
                        />
                      ) : null}
                    </div>
                    <p className="mt-0.5 font-medium">{r.client_name}</p>
                    <p className="font-mono text-xs tabular-nums text-slate-500">
                      {r.contract_folio}
                    </p>
                  </div>
                  <AppRowActionsMenu
                    actions={[
                      {
                        label: 'Ver detalle',
                        icon: <Eye className="h-4 w-4" />,
                        onClick: () => openDetail(r.id, 'view'),
                      },
                      {
                        label: 'Cotizar',
                        icon: <Quote className="h-4 w-4" />,
                        onClick: () => openDetail(r.id, 'quote'),
                        hidden: !canEditRequests || !actionable,
                      },
                      {
                        label: 'Aprobar',
                        icon: <CheckCircle2 className="h-4 w-4" />,
                        onClick: () => openDetail(r.id, 'approve'),
                        hidden: !canEditRequests || !actionable,
                      },
                      {
                        label: 'Rechazar',
                        icon: <XCircle className="h-4 w-4" />,
                        onClick: () => openDetail(r.id, 'reject'),
                        variant: 'danger',
                        hidden: !canEditRequests || !actionable,
                      },
                      {
                        label: 'Generar factura en cobranza',
                        icon: <FilePlus2 className="h-4 w-4" />,
                        onClick: async () => {
                          try {
                            const inv = await generateInvoiceFromRequest(r.id)
                            toast.success(`Documento ${inv.folio} generado`, r.folio)
                          } catch (error) {
                            toast.apiError(error)
                          }
                        },
                        hidden: !canBill,
                      },
                      {
                        label: 'Cancelar solicitud',
                        icon: <Ban className="h-4 w-4" />,
                        onClick: () => {
                          setCancelTarget(r)
                          setCancelNotes('')
                        },
                        variant: 'danger',
                        separatorBefore: true,
                        hidden: !canDeleteRequests || !actionable,
                      },
                    ]}
                  />
                </div>
                <div className="mt-3 flex flex-wrap gap-1.5">
                  <AppBadge label={TYPE_LABEL[r.request_type]} tone="info" />
                  {r.origin === 'portal' ? <AppBadge label="Portal" tone="neutral" /> : null}
                  <AppBadge label={STATUS_LABEL[r.status]} tone={STATUS_TONE[r.status]} />
                  {!r.contract_is_current ? (
                    <AppBadge label="Adeudo" tone="warning" />
                  ) : null}
                </div>
                <div className="mt-3 flex items-end justify-between">
                  <p className="text-xs text-slate-500">{formatDateEsMx(r.created_at)}</p>
                  <div className="text-right">
                    <p className="font-mono text-sm font-semibold tabular-nums">
                      {formatCurrencyMx(r.subtotal, { fractionDigits: 2 })}
                    </p>
                    <p className="text-[11px] text-slate-500">
                      {r.request_type === 'recurring_expansion'
                        ? `+${formatCurrencyMx(r.mrr_impact_mxn, { fractionDigits: 2 })} / mes`
                        : 'No suma al MRR'}
                    </p>
                  </div>
                </div>
              </AppCard>
            )
          })}
        </div>
      </AppTableWrapper>

      <NewRequestModal
        open={newOpen}
        onOpenChange={setNewOpen}
        onSubmit={async (input) => {
          try {
            await createRequest(input)
            setPage(1)
            toast.success('Solicitud creada', 'La solicitud quedó pendiente de atención.')
          } catch (error) {
            toast.apiError(error)
            throw error
          }
        }}
      />

      <RequestDetailModal
        open={detailOpen}
        onOpenChange={setDetailOpen}
        requestId={detailId}
        mode={detailMode}
        canEdit={canEditRequests}
        onApprove={async (id, input) => {
          try {
            await approveRequest(id, input)
            toast.success('Solicitud aprobada', 'La operación se registró correctamente.')
          } catch (error) {
            toast.apiError(error)
            throw error
          }
        }}
        onReject={async (id, input) => {
          try {
            await rejectRequest(id, input)
            toast.success('Solicitud rechazada', 'Se registró el motivo de rechazo.')
          } catch (error) {
            toast.apiError(error)
            throw error
          }
        }}
        onQuote={async (request) => {
          try {
            await quoteRequest(request.id, {
              resolution_notes: request.resolution_notes ?? 'Cotización registrada',
            })
            toast.success('Solicitud cotizada', `${request.folio} pasó a estado Cotizada.`)
            setDetailOpen(false)
          } catch (error) {
            toast.apiError(error)
          }
        }}
      />

      <AppModal
        open={cancelTarget != null}
        onOpenChange={(open) => {
          if (!open) {
            setCancelTarget(null)
            setCancelNotes('')
          }
        }}
      >
        <AppModalContent className="max-w-md">
          <AppModalHeader>
            <AppModalTitle>Cancelar solicitud</AppModalTitle>
            <AppModalDescription>
              {cancelTarget
                ? `¿Confirmas cancelar ${cancelTarget.folio}? Esta acción no se puede deshacer.`
                : 'Confirma la cancelación.'}
            </AppModalDescription>
          </AppModalHeader>
          <div className="space-y-3">
            <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">
              Motivo (recomendado)
            </label>
            <AppTextarea
              value={cancelNotes}
              onChange={(e) => setCancelNotes(e.target.value)}
              placeholder="Opcional: indica por qué se cancela"
            />
          </div>
          <AppModalFooter>
            <AppButton
              type="button"
              variant="ghost"
              onClick={() => {
                setCancelTarget(null)
                setCancelNotes('')
              }}
            >
              Volver
            </AppButton>
            <AppButton
              type="button"
              variant="danger"
              isLoading={cancelSubmitting}
              onClick={async () => {
                if (!cancelTarget) return
                setCancelSubmitting(true)
                try {
                  await cancelRequest(cancelTarget.id, {
                    resolution_notes: cancelNotes.trim() || undefined,
                  })
                  toast.success(
                    'Solicitud cancelada',
                    `${cancelTarget.folio} quedó en estado Cancelada.`,
                  )
                  setCancelTarget(null)
                  setCancelNotes('')
                } catch (error) {
                  toast.apiError(error)
                } finally {
                  setCancelSubmitting(false)
                }
              }}
            >
              Confirmar cancelación
            </AppButton>
          </AppModalFooter>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
