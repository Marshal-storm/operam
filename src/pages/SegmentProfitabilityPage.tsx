import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Pencil, PieChart, Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { SegmentCostModal } from '@/components/profitability/SegmentCostModal'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard, AppCardContent, AppCardHeader } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppInput } from '@/components/ui/AppInput'
import { AppTabs } from '@/components/ui/AppTabs'
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useScreenPreferences,
  type PageSizeOption,
} from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import {
  useExceptionsReport,
  useNonRecurringRevenue,
  useProfitabilitySummary,
  useSegmentCostMutations,
  useSegmentCosts,
} from '@/hooks/useProfitability'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegments } from '@/hooks/useSegments'
import { cn, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import {
  COST_CATEGORY_LABELS,
  type SegmentCost,
  type SegmentCostCategory,
} from '@/types/profitability'

type TabKey = 'summary' | 'costs' | 'non_recurring' | 'exceptions'
type UnitMode = 'contract' | 'user'

type SegmentUnitRow = {
  segment_id: number
  segment_name: string
  revenue_per_contract_mxn: string
  cost_per_contract_mxn: string
  profit_per_contract_mxn: string
  revenue_per_user_mxn: string
  cost_per_user_mxn: string
  profit_per_user_mxn: string
  active_contracts_count: number
  active_concurrent_users_count: number
  total_revenue_mxn?: string
  total_cost_mxn?: string
}

function currentMonthMexico(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Mexico_City',
    year: 'numeric',
    month: '2-digit',
  })
    .format(new Date())
    .slice(0, 7)
}

function moneyTone(amount: string): string {
  const n = Number(amount)
  if (!Number.isFinite(n) || n === 0) return 'text-[#090D16] dark:text-white'
  return n > 0
    ? 'text-emerald-600 dark:text-emerald-400'
    : 'text-red-500 dark:text-red-400'
}

/**
 * Utilidad por segmento — RF-10 / RF-11.5 (`/utilidad-segmento`).
 * 4 pestañas: Resumen · Costos · No recurrentes · Excepciones.
 */
export function SegmentProfitabilityPage() {
  const toast = useToast()
  const { canCreate, canEdit, canDelete } = usePermissions()
  const { pageSize: preferredSize, setPageSize } = useScreenPreferences()
  const { segments, isSegmentLocked, lockedSegmentId, filterOptions } = useSegments()

  const [tab, setTab] = useState<TabKey>('summary')
  const [month, setMonth] = useState(currentMonthMexico)
  const [segmentFilter, setSegmentFilter] = useState(
    isSegmentLocked && lockedSegmentId ? String(lockedSegmentId) : 'Todos',
  )

  const [costsPage, setCostsPage] = useState(1)
  const [costsPageSize, setCostsPageSize] = useState<PageSizeOption>(preferredSize)
  const [costsSearch, setCostsSearch] = useState('')
  const [costsCategory, setCostsCategory] = useState('Todos')

  const [nrPage, setNrPage] = useState(1)
  const [nrPageSize, setNrPageSize] = useState<PageSizeOption>(preferredSize)

  const [exPage, setExPage] = useState(1)
  const [exPageSize, setExPageSize] = useState<PageSizeOption>(preferredSize)
  const [exFrom, setExFrom] = useState(`${month}-01`)
  const [exTo, setExTo] = useState(() => {
    const [y, m] = month.split('-').map(Number)
    const last = new Date(Date.UTC(y, m, 0)).getUTCDate()
    return `${month}-${String(last).padStart(2, '0')}`
  })

  const [modalOpen, setModalOpen] = useState(false)
  const [editingCost, setEditingCost] = useState<SegmentCost | null>(null)

  useEffect(() => {
    if (isSegmentLocked && lockedSegmentId) {
      setSegmentFilter(String(lockedSegmentId))
    }
  }, [isSegmentLocked, lockedSegmentId])

  const segmentIdParam = useMemo(() => {
    if (isSegmentLocked && lockedSegmentId) return lockedSegmentId
    if (segmentFilter === 'Todos') return null
    const byId = Number(segmentFilter)
    if (Number.isFinite(byId) && byId > 0) return byId
    const byName = segments.find((s) => s.name === segmentFilter)
    return byName?.id ?? null
  }, [isSegmentLocked, lockedSegmentId, segmentFilter, segments])

  const summaryQuery = useProfitabilitySummary({
    month,
    segmentId: segmentIdParam,
  })
  const costsQuery = useSegmentCosts({
    page: costsPage,
    perPage: costsPageSize,
    month,
    segmentId: segmentIdParam,
    search: costsSearch.trim() || undefined,
    category: costsCategory !== 'Todos' ? costsCategory : undefined,
  })
  const nonRecurringQuery = useNonRecurringRevenue({
    page: nrPage,
    perPage: nrPageSize,
    month,
    segmentId: segmentIdParam,
    enabled: tab === 'non_recurring',
  })
  const exceptionsQuery = useExceptionsReport({
    page: exPage,
    perPage: exPageSize,
    from: exFrom,
    to: exTo,
    segmentId: segmentIdParam,
    enabled: tab === 'exceptions',
  })

  const { createMutation, updateMutation, deleteMutation } = useSegmentCostMutations()

  const consolidated = summaryQuery.data?.consolidated
  const chartData = (summaryQuery.data?.monthly_trend ?? []).map((p) => ({
    month: p.month,
    Ingresos: Number(p.total_revenue_mxn),
    Costos: Number(p.total_cost_mxn),
    Utilidad: Number(p.gross_profit_mxn),
  }))

  const costsTotal = costsQuery.data?.total ?? 0

  const segmentFilterOptions = useMemo(() => {
    if (isSegmentLocked) {
      return filterOptions.map((o) => ({
        value: lockedSegmentId != null ? String(lockedSegmentId) : o.value,
        label: o.label,
      }))
    }
    return [
      { value: 'Todos', label: 'Todos' },
      ...segments.map((s) => ({ value: String(s.id), label: s.name })),
    ]
  }, [isSegmentLocked, filterOptions, lockedSegmentId, segments])

  const categoryFilterOptions = useMemo(
    () => [
      { value: 'Todos', label: 'Todas' },
      ...(Object.entries(COST_CATEGORY_LABELS) as Array<[SegmentCostCategory, string]>).map(
        ([value, label]) => ({ value, label }),
      ),
    ],
    [],
  )

  async function handleSaveCost(input: Parameters<typeof createMutation.mutateAsync>[0]) {
    try {
      if (editingCost) {
        await updateMutation.mutateAsync({ id: editingCost.id, input })
        toast.success('Costo actualizado', input.concept)
      } else {
        await createMutation.mutateAsync(input)
        toast.success('Costo registrado', input.concept)
        setCostsPage(1)
      }
      setModalOpen(false)
      setEditingCost(null)
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function handleDeleteCost(row: SegmentCost) {
    try {
      await deleteMutation.mutateAsync(row.id)
      toast.success('Costo eliminado', row.concept)
    } catch (error) {
      toast.apiError(error)
    }
  }

  const tabItems = [
    { id: 'summary', label: 'Resumen financiero' },
    {
      id: 'costs',
      label: 'Costos operativos',
      badge: costsTotal > 0 ? costsTotal : undefined,
    },
    { id: 'non_recurring', label: 'Ingresos no recurrentes' },
    { id: 'exceptions', label: 'Excepciones' },
  ]

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div className="flex items-start gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
            <PieChart className="h-5 w-5" />
          </span>
          <div>
            <h1 className="font-heading text-2xl font-semibold md:text-3xl">
              Utilidad por segmento
            </h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">
              Margen bruto, unit economics, costos operativos e ingresos no recurrentes
            </p>
          </div>
        </div>
        {canCreate('segment_profitability') && (
          <AppButton
            onClick={() => {
              setEditingCost(null)
              setModalOpen(true)
            }}
          >
            <Plus className="h-4 w-4" /> Registrar costo mensual
          </AppButton>
        )}
      </header>

      <AppFilterBar
        filters={[
          {
            id: 'month',
            label: 'MES',
            value: month,
            onValueChange: (value) => {
              setMonth(value)
              setCostsPage(1)
              setNrPage(1)
              const [y, m] = value.split('-').map(Number)
              const last = new Date(Date.UTC(y, m, 0)).getUTCDate()
              setExFrom(`${value}-01`)
              setExTo(`${value}-${String(last).padStart(2, '0')}`)
              setExPage(1)
            },
            options: buildMonthOptions(month),
          },
          {
            id: 'segment',
            label: 'SEGMENTO',
            value: segmentFilter,
            onValueChange: (value) => {
              if (isSegmentLocked) return
              setSegmentFilter(value)
              setCostsPage(1)
              setNrPage(1)
              setExPage(1)
            },
            options: segmentFilterOptions,
            disabled: isSegmentLocked,
          },
        ]}
        hasActiveFilters={!isSegmentLocked && segmentFilter !== 'Todos'}
        onClearFilters={() => {
          if (!isSegmentLocked) setSegmentFilter('Todos')
        }}
      />

      <AppTabs
        aria-label="Vistas de utilidad por segmento"
        value={tab}
        onChange={(id) => setTab(id as TabKey)}
        items={tabItems}
      />

      {tab === 'summary' && (
        <SummaryTab
          summaryLoading={summaryQuery.isLoading}
          summaryError={summaryQuery.isError}
          onRetrySummary={() => void summaryQuery.refetch()}
          consolidated={consolidated}
          bySegment={summaryQuery.data?.by_segment ?? []}
          chartData={chartData}
        />
      )}

      {tab === 'costs' && (
        <CostsTab
          costsQuery={costsQuery}
          costsSearch={costsSearch}
          onCostsSearch={(v) => {
            setCostsSearch(v)
            setCostsPage(1)
          }}
          costsCategory={costsCategory}
          onCostsCategory={(v) => {
            setCostsCategory(v)
            setCostsPage(1)
          }}
          categoryFilterOptions={categoryFilterOptions}
          costsPage={costsPage}
          costsPageSize={costsPageSize}
          onCostsPageChange={setCostsPage}
          onCostsPageSizeChange={(size) => {
            setCostsPageSize(size)
            setPageSize(size)
            setCostsPage(1)
          }}
          canEdit={canEdit('segment_profitability')}
          canDelete={canDelete('segment_profitability')}
          canCreate={canCreate('segment_profitability')}
          onEdit={(row) => {
            setEditingCost(row)
            setModalOpen(true)
          }}
          onDelete={(row) => void handleDeleteCost(row)}
          onCreate={() => {
            setEditingCost(null)
            setModalOpen(true)
          }}
        />
      )}

      {tab === 'non_recurring' && (
        <NonRecurringTab
          query={nonRecurringQuery}
          page={nrPage}
          pageSize={nrPageSize}
          onPageChange={setNrPage}
          onPageSizeChange={(size) => {
            setNrPageSize(size)
            setPageSize(size)
            setNrPage(1)
          }}
        />
      )}

      {tab === 'exceptions' && (
        <ExceptionsTab
          query={exceptionsQuery}
          from={exFrom}
          to={exTo}
          defaultFrom={`${month}-01`}
          defaultTo={(() => {
            const [y, m] = month.split('-').map(Number)
            const last = new Date(Date.UTC(y, m, 0)).getUTCDate()
            return `${month}-${String(last).padStart(2, '0')}`
          })()}
          onFromChange={(v) => {
            setExFrom(v)
            setExPage(1)
          }}
          onToChange={(v) => {
            setExTo(v)
            setExPage(1)
          }}
          page={exPage}
          pageSize={exPageSize}
          onPageChange={setExPage}
          onPageSizeChange={(size) => {
            setExPageSize(size)
            setPageSize(size)
            setExPage(1)
          }}
        />
      )}

      <SegmentCostModal
        open={modalOpen}
        onOpenChange={(open) => {
          setModalOpen(open)
          if (!open) setEditingCost(null)
        }}
        editing={editingCost}
        defaultMonth={month}
        defaultSegmentId={segmentIdParam}
        isSaving={createMutation.isPending || updateMutation.isPending}
        onSubmit={handleSaveCost}
      />
    </div>
  )
}

function buildMonthOptions(current: string): Array<{ value: string; label: string }> {
  const options: Array<{ value: string; label: string }> = []
  const [y, m] = current.split('-').map(Number)
  const base = new Date(Date.UTC(y, m - 1, 1))
  for (let i = 0; i < 18; i++) {
    const d = new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth() - i, 1))
    const value = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`
    options.push({ value, label: value })
  }
  return options
}

function KpiCard({
  title,
  value,
  toneClass,
}: {
  title: string
  value: string
  toneClass?: string
}) {
  return (
    <AppCard>
      <AppCardHeader className="pb-0.5 pt-3 px-4">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
          {title}
        </p>
      </AppCardHeader>
      <AppCardContent className="px-4 pb-3 pt-1">
        <p className={cn('font-mono text-xl font-semibold tabular-nums md:text-2xl', toneClass)}>
          {value}
        </p>
      </AppCardContent>
    </AppCard>
  )
}

function SummaryTab({
  summaryLoading,
  summaryError,
  onRetrySummary,
  consolidated,
  bySegment,
  chartData,
}: {
  summaryLoading: boolean
  summaryError: boolean
  onRetrySummary: () => void
  consolidated: ReturnType<typeof useProfitabilitySummary>['data'] extends infer T
    ? T extends { consolidated: infer C }
      ? C
      : undefined
    : undefined
  bySegment: SegmentUnitRow[]
  chartData: Array<{ month: string; Ingresos: number; Costos: number; Utilidad: number }>
}) {
  const [unitMode, setUnitMode] = useState<UnitMode>('contract')

  if (summaryLoading) {
    return <Skeleton className="h-72 w-full rounded-3xl" />
  }
  if (summaryError) {
    return (
      <AppCard className="p-8 text-center">
        <p className="text-sm text-slate-500">No se pudo cargar el resumen de utilidad.</p>
        <AppButton className="mt-4" variant="outline" onClick={onRetrySummary}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  const visibleSegments = bySegment.filter((row) => {
    if (unitMode === 'contract') {
      return row.active_contracts_count > 0 || Number(row.total_cost_mxn ?? 0) > 0
    }
    return row.active_concurrent_users_count > 0 || Number(row.total_cost_mxn ?? 0) > 0
  })

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          title="Ingresos totales"
          value={formatCurrencyMx(consolidated?.total_revenue_mxn ?? '0', {
            fractionDigits: 2,
          })}
        />
        <KpiCard
          title="Costos operativos"
          value={formatCurrencyMx(consolidated?.total_cost_mxn ?? '0', {
            fractionDigits: 2,
          })}
        />
        <KpiCard
          title="Utilidad bruta"
          value={formatCurrencyMx(consolidated?.gross_profit_mxn ?? '0', {
            fractionDigits: 2,
          })}
          toneClass={moneyTone(consolidated?.gross_profit_mxn ?? '0')}
        />
        <KpiCard
          title="Margen bruto %"
          value={`${consolidated?.gross_margin_pct ?? '0.00'}%`}
          toneClass={moneyTone(consolidated?.gross_profit_mxn ?? '0')}
        />
      </div>

      <AppCard>
        <AppCardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="font-heading text-lg font-semibold">Unit Economics por segmento</h2>
            <p className="text-sm text-slate-500">
              Promedios en español natural, sin scroll horizontal
            </p>
          </div>
          <AppTabs
            aria-label="Modo de unit economics"
            fullWidth={false}
            value={unitMode}
            onChange={(id) => setUnitMode(id as UnitMode)}
            items={[
              { id: 'contract', label: 'Por contrato activo' },
              { id: 'user', label: 'Por usuario concurrente' },
            ]}
            className="shrink-0"
          />
        </AppCardHeader>
        <AppCardContent>
          {visibleSegments.length === 0 ? (
            <p className="py-6 text-center text-sm text-slate-500">
              No hay segmentos con contratos, usuarios o costos para el mes seleccionado.
            </p>
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {visibleSegments.map((row) => {
                const count =
                  unitMode === 'contract'
                    ? row.active_contracts_count
                    : row.active_concurrent_users_count
                const badgeLabel =
                  unitMode === 'contract'
                    ? `${count} contrato${count === 1 ? '' : 's'} activo${count === 1 ? '' : 's'}`
                    : `${count} usuario${count === 1 ? '' : 's'} activo${count === 1 ? '' : 's'}`
                const revenue =
                  unitMode === 'contract'
                    ? row.revenue_per_contract_mxn
                    : row.revenue_per_user_mxn
                const cost =
                  unitMode === 'contract' ? row.cost_per_contract_mxn : row.cost_per_user_mxn
                const profit =
                  unitMode === 'contract'
                    ? row.profit_per_contract_mxn
                    : row.profit_per_user_mxn

                return (
                  <div
                    key={row.segment_id}
                    className="rounded-2xl border border-slate-200/80 bg-white/70 p-4 dark:border-white/[0.09] dark:bg-white/[0.03]"
                  >
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                      <h3 className="font-heading text-base font-semibold">{row.segment_name}</h3>
                      <AppBadge label={badgeLabel} tone="neutral" />
                    </div>
                    <dl className="space-y-2">
                      <div className="flex items-center justify-between gap-3">
                        <dt className="text-sm text-slate-500">Ingreso promedio</dt>
                        <dd className="font-mono text-sm font-medium tabular-nums">
                          {formatCurrencyMx(revenue, { fractionDigits: 2 })}
                        </dd>
                      </div>
                      <div className="flex items-center justify-between gap-3">
                        <dt className="text-sm text-slate-500">Costo promedio</dt>
                        <dd className="font-mono text-sm font-medium tabular-nums">
                          {formatCurrencyMx(cost, { fractionDigits: 2 })}
                        </dd>
                      </div>
                      <div className="flex items-center justify-between gap-3">
                        <dt className="text-sm text-slate-500">Utilidad promedio</dt>
                        <dd
                          className={cn(
                            'font-mono text-sm font-semibold tabular-nums',
                            moneyTone(profit),
                          )}
                        >
                          {formatCurrencyMx(profit, { fractionDigits: 2 })}
                        </dd>
                      </div>
                    </dl>
                  </div>
                )
              })}
            </div>
          )}
        </AppCardContent>
      </AppCard>

      <AppCard>
        <AppCardHeader>
          <h2 className="font-heading text-lg font-semibold">
            Evolución mensual (últimos 6 meses)
          </h2>
        </AppCardHeader>
        <AppCardContent className="h-[280px]">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
              <XAxis dataKey="month" tick={{ fontSize: 12 }} />
              <YAxis tick={{ fontSize: 12 }} />
              <Tooltip content={<ChartMoneyTooltip />} />
              <Legend />
              <Line type="monotone" dataKey="Ingresos" stroke="#0066FF" strokeWidth={2} />
              <Line type="monotone" dataKey="Costos" stroke="#F59E0B" strokeWidth={2} />
              <Line type="monotone" dataKey="Utilidad" stroke="#10B981" strokeWidth={2} />
            </LineChart>
          </ResponsiveContainer>
        </AppCardContent>
      </AppCard>
    </div>
  )
}

function ChartMoneyTooltip({
  active,
  payload,
  label,
}: {
  active?: boolean
  payload?: Array<{ name?: string; value?: number; color?: string }>
  label?: string
}) {
  if (!active || !payload?.length) return null

  return (
    <div className="rounded-xl border border-slate-200/80 bg-white/95 px-3 py-2 text-xs shadow-lg backdrop-blur-xl dark:border-white/[0.12] dark:bg-[#111113]/95">
      <p className="mb-1 font-semibold text-slate-700 dark:text-zinc-200">{label}</p>
      {payload.map((entry) => (
        <p key={entry.name} className="font-mono tabular-nums" style={{ color: entry.color }}>
          {entry.name}:{' '}
          {formatCurrencyMx(entry.value ?? 0, {
            fractionDigits: 2,
            showCurrencyCode: true,
          })}
        </p>
      ))}
    </div>
  )
}

function CostsTab({
  costsQuery,
  costsSearch,
  onCostsSearch,
  costsCategory,
  onCostsCategory,
  categoryFilterOptions,
  costsPage,
  costsPageSize,
  onCostsPageChange,
  onCostsPageSizeChange,
  canEdit,
  canDelete,
  canCreate,
  onEdit,
  onDelete,
  onCreate,
}: {
  costsQuery: ReturnType<typeof useSegmentCosts>
  costsSearch: string
  onCostsSearch: (v: string) => void
  costsCategory: string
  onCostsCategory: (v: string) => void
  categoryFilterOptions: Array<{ value: string; label: string }>
  costsPage: number
  costsPageSize: PageSizeOption
  onCostsPageChange: (p: number) => void
  onCostsPageSizeChange: (s: PageSizeOption) => void
  canEdit: boolean
  canDelete: boolean
  canCreate: boolean
  onEdit: (row: SegmentCost) => void
  onDelete: (row: SegmentCost) => void
  onCreate: () => void
}) {
  return (
    <div className="space-y-3">
      <h2 className="font-heading text-lg font-semibold">Costos mensuales capturados</h2>
      <AppFilterBar
        searchValue={costsSearch}
        onSearchChange={onCostsSearch}
        onSearchClear={() => onCostsSearch('')}
        searchPlaceholder="Buscar concepto o notas…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'category',
            label: 'CATEGORÍA',
            value: costsCategory,
            onValueChange: onCostsCategory,
            options: categoryFilterOptions,
          },
        ]}
        hasActiveFilters={costsSearch.trim() !== '' || costsCategory !== 'Todos'}
        onClearFilters={() => {
          onCostsSearch('')
          onCostsCategory('Todos')
        }}
      />
      {costsQuery.isError ? (
        <AppCard className="space-y-3 p-8 text-center">
          <p className="text-sm text-slate-500">No se pudieron cargar los costos.</p>
          <AppButton variant="outline" onClick={() => void costsQuery.refetch()}>
            Reintentar
          </AppButton>
        </AppCard>
      ) : (
        <AppTableWrapper
          isLoading={costsQuery.isLoading}
          loadingState={<Skeleton className="h-40 w-full rounded-none" />}
          isEmpty={!costsQuery.isLoading && (costsQuery.data?.items.length ?? 0) === 0}
          emptyState={
            <div className="space-y-3 p-8 text-center">
              <p className="text-sm text-slate-500">
                Aún no hay costos capturados para este mes.
              </p>
              {canCreate && (
                <AppButton onClick={onCreate}>
                  <Plus className="h-4 w-4" /> Registrar primer costo
                </AppButton>
              )}
            </div>
          }
          footer={
            <PaginationBar
              page={costsPage}
              total={costsQuery.data?.total ?? 0}
              pageSize={costsPageSize}
              onPageChange={onCostsPageChange}
              onPageSizeChange={onCostsPageSizeChange}
            />
          }
        >
          <div className="hidden md:block">
            <table className="w-full text-sm">
              <thead>
                <AppTableHeadRow>
                  <th className="px-4 py-3 text-left">Mes</th>
                  <th className="px-4 py-3 text-left">Segmento</th>
                  <th className="px-4 py-3 text-left">Categoría</th>
                  <th className="px-4 py-3 text-left">Concepto</th>
                  <th className="px-4 py-3 text-right">Monto</th>
                  <th className="px-4 py-3 text-right">
                    <AppActionsColumnHeader />
                  </th>
                </AppTableHeadRow>
              </thead>
              <tbody>
                {(costsQuery.data?.items ?? []).map((row) => (
                  <tr
                    key={row.id}
                    className="border-b border-slate-100 dark:border-white/[0.04]"
                  >
                    <td className="px-4 py-3 font-mono tabular-nums">{row.month}</td>
                    <td className="px-4 py-3">{row.segment_name ?? '—'}</td>
                    <td className="px-4 py-3">
                      <AppBadge
                        label={
                          COST_CATEGORY_LABELS[row.category as SegmentCostCategory] ??
                          row.category
                        }
                        tone="neutral"
                      />
                    </td>
                    <td className="px-4 py-3">{row.concept}</td>
                    <td className="px-4 py-3 text-right font-mono tabular-nums">
                      {formatCurrencyMx(row.amount, { fractionDigits: 2 })}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <AppRowActionsMenu
                        actions={[
                          {
                            label: 'Editar',
                            icon: <Pencil className="h-4 w-4" />,
                            onClick: () => onEdit(row),
                            hidden: !canEdit,
                          },
                          {
                            label: 'Eliminar',
                            icon: <Trash2 className="h-4 w-4" />,
                            onClick: () => onDelete(row),
                            hidden: !canDelete,
                            variant: 'danger',
                          },
                        ]}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="space-y-3 p-4 md:hidden">
            {(costsQuery.data?.items ?? []).map((row) => (
              <AppCard key={row.id} className="p-3">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-medium">{row.concept}</p>
                    <p className="text-xs text-slate-500">
                      {row.segment_name} · {row.month}
                    </p>
                    <p className="mt-1 font-mono text-sm tabular-nums">
                      {formatCurrencyMx(row.amount, { fractionDigits: 2 })}
                    </p>
                  </div>
                  <AppRowActionsMenu
                    actions={[
                      {
                        label: 'Editar',
                        onClick: () => onEdit(row),
                        hidden: !canEdit,
                      },
                      {
                        label: 'Eliminar',
                        onClick: () => onDelete(row),
                        hidden: !canDelete,
                        variant: 'danger',
                      },
                    ]}
                  />
                </div>
              </AppCard>
            ))}
          </div>
        </AppTableWrapper>
      )}
    </div>
  )
}

function NonRecurringTab({
  query,
  page,
  pageSize,
  onPageChange,
  onPageSizeChange,
}: {
  query: ReturnType<typeof useNonRecurringRevenue>
  page: number
  pageSize: PageSizeOption
  onPageChange: (p: number) => void
  onPageSizeChange: (s: PageSizeOption) => void
}) {
  if (query.isError) {
    return (
      <AppCard className="space-y-3 p-8 text-center">
        <p className="text-sm text-slate-500">No se pudo cargar el reporte.</p>
        <AppButton variant="outline" onClick={() => void query.refetch()}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  return (
    <AppTableWrapper
      isLoading={query.isLoading}
      loadingState={<Skeleton className="h-48 w-full rounded-none" />}
      isEmpty={!query.isLoading && (query.data?.items.length ?? 0) === 0}
      emptyState={
        <div className="p-8 text-center text-sm text-slate-500">
          No hay ingresos no recurrentes facturados en este mes.
        </div>
      }
      footer={
        <PaginationBar
          page={page}
          total={query.data?.total ?? 0}
          pageSize={pageSize}
          onPageChange={onPageChange}
          onPageSizeChange={onPageSizeChange}
        />
      }
    >
      <div className="hidden md:block">
        <table className="w-full text-sm">
          <thead>
            <AppTableHeadRow>
              <th className="px-4 py-3 text-left">Servicio / Concepto</th>
              <th className="px-4 py-3 text-left">Segmento</th>
              <th className="px-4 py-3 text-left">Mes</th>
              <th className="px-4 py-3 text-right">Operaciones</th>
              <th className="px-4 py-3 text-right">Ingreso cobrado</th>
              <th className="px-4 py-3 text-right">Unidades regaladas (promoción)</th>
            </AppTableHeadRow>
          </thead>
          <tbody>
            {(query.data?.items ?? []).map((row, idx) => (
              <tr
                key={`${row.service_name}-${row.segment_id}-${idx}`}
                className="border-b border-slate-100 dark:border-white/[0.04]"
              >
                <td className="px-4 py-3">{row.service_name}</td>
                <td className="px-4 py-3">{row.segment_name ?? '—'}</td>
                <td className="px-4 py-3 font-mono tabular-nums">{row.month}</td>
                <td className="px-4 py-3 text-right font-mono tabular-nums">
                  {row.operations_count}
                </td>
                <td className="px-4 py-3 text-right font-mono tabular-nums">
                  {formatCurrencyMx(row.billed_amount_mxn, { fractionDigits: 2 })}
                </td>
                <td className="px-4 py-3 text-right font-mono tabular-nums">
                  {row.gifted_units}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="space-y-3 p-4 md:hidden">
        {(query.data?.items ?? []).map((row, idx) => (
          <AppCard key={`${row.service_name}-${idx}`} className="p-3">
            <p className="font-medium">{row.service_name}</p>
            <p className="text-xs text-slate-500">
              {row.segment_name} · {row.month}
            </p>
            <p className="mt-2 font-mono text-sm tabular-nums">
              {formatCurrencyMx(row.billed_amount_mxn, { fractionDigits: 2 })} ·{' '}
              {row.operations_count} ops · regaladas {row.gifted_units}
            </p>
          </AppCard>
        ))}
      </div>
    </AppTableWrapper>
  )
}

function ExceptionsTab({
  query,
  from,
  to,
  defaultFrom,
  defaultTo,
  onFromChange,
  onToChange,
  page,
  pageSize,
  onPageChange,
  onPageSizeChange,
}: {
  query: ReturnType<typeof useExceptionsReport>
  from: string
  to: string
  defaultFrom: string
  defaultTo: string
  onFromChange: (v: string) => void
  onToChange: (v: string) => void
  page: number
  pageSize: PageSizeOption
  onPageChange: (p: number) => void
  onPageSizeChange: (s: PageSizeOption) => void
}) {
  const hasActiveDateFilters = from !== defaultFrom || to !== defaultTo

  return (
    <div className="space-y-3">
      <AppFilterBar
        filters={[
          {
            id: 'from',
            label: 'DESDE',
            content: (
              <AppInput
                type="date"
                className="rounded-full"
                aria-label="Fecha desde"
                value={from}
                onChange={(e) => onFromChange(e.target.value)}
              />
            ),
          },
          {
            id: 'to',
            label: 'HASTA',
            content: (
              <AppInput
                type="date"
                className="rounded-full"
                aria-label="Fecha hasta"
                value={to}
                onChange={(e) => onToChange(e.target.value)}
              />
            ),
          },
        ]}
        hasActiveFilters={hasActiveDateFilters}
        onClearFilters={() => {
          onFromChange(defaultFrom)
          onToChange(defaultTo)
        }}
      />

      {query.isError ? (
        <AppCard className="space-y-3 p-8 text-center">
          <p className="text-sm text-slate-500">No se pudo cargar el reporte.</p>
          <AppButton variant="outline" onClick={() => void query.refetch()}>
            Reintentar
          </AppButton>
        </AppCard>
      ) : (
        <AppTableWrapper
          isLoading={query.isLoading}
          loadingState={<Skeleton className="h-48 w-full rounded-none" />}
          isEmpty={!query.isLoading && (query.data?.items.length ?? 0) === 0}
          emptyState={
            <div className="p-8 text-center text-sm text-slate-500">
              No hay excepciones comerciales en el rango seleccionado.
            </div>
          }
          footer={
            <PaginationBar
              page={page}
              total={query.data?.total ?? 0}
              pageSize={pageSize}
              onPageChange={onPageChange}
              onPageSizeChange={onPageSizeChange}
            />
          }
        >
          <div className="hidden md:block">
            <table className="w-full text-sm">
              <thead>
                <AppTableHeadRow>
                  <th className="px-4 py-3 text-left">Fecha</th>
                  <th className="px-4 py-3 text-left">Usuario</th>
                  <th className="px-4 py-3 text-left">Contrato / Cliente</th>
                  <th className="px-4 py-3 text-left">Política exceptuada</th>
                  <th className="px-4 py-3 text-left">Motivo</th>
                  <th className="px-4 py-3 text-right">Saldo vencido al momento</th>
                </AppTableHeadRow>
              </thead>
              <tbody>
                {(query.data?.items ?? []).map((row) => (
                  <tr
                    key={row.exception_uid}
                    className="border-b border-slate-100 dark:border-white/[0.04]"
                  >
                    <td className="px-4 py-3 whitespace-nowrap">
                      {safeFormatDate(row.date)}
                    </td>
                    <td className="px-4 py-3">{row.user_name}</td>
                    <td className="px-4 py-3">
                      <p className="font-mono text-xs tabular-nums">
                        {row.contract_folio ?? '—'}
                      </p>
                      <p className="text-xs text-slate-500">{row.client_name ?? '—'}</p>
                    </td>
                    <td className="px-4 py-3">
                      <AppBadge
                        label={row.policy ?? row.action ?? 'excepción'}
                        tone="warning"
                      />
                    </td>
                    <td className="max-w-[280px] px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                      {row.reason ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-right font-mono tabular-nums">
                      {row.overdue_balance_at_exception != null
                        ? formatCurrencyMx(row.overdue_balance_at_exception, {
                            fractionDigits: 2,
                          })
                        : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="space-y-3 p-4 md:hidden">
            {(query.data?.items ?? []).map((row) => (
              <AppCard key={row.exception_uid} className="p-3">
                <p className="text-xs text-slate-500">{safeFormatDate(row.date)}</p>
                <p className="font-medium">{row.user_name}</p>
                <p className="font-mono text-xs tabular-nums">
                  {row.contract_folio} · {row.client_name}
                </p>
                <p className="mt-2 text-xs">{row.reason}</p>
                <p className="mt-1 font-mono text-sm tabular-nums">
                  {row.overdue_balance_at_exception != null
                    ? formatCurrencyMx(row.overdue_balance_at_exception, {
                        fractionDigits: 2,
                      })
                    : '—'}
                </p>
              </AppCard>
            ))}
          </div>
        </AppTableWrapper>
      )}
    </div>
  )
}

function safeFormatDate(value: string): string {
  try {
    return formatDateEsMx(value)
  } catch {
    return value.slice(0, 10)
  }
}
