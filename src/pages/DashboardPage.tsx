import { useEffect, useMemo, useState } from 'react'
import { AgingPortfolioCard } from '@/components/dashboard/AgingPortfolioCard'
import { MrrBridgeChart } from '@/components/dashboard/MrrBridgeChart'
import { RecentMovementsTable } from '@/components/dashboard/RecentMovementsTable'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/context/AuthContext'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { useDashboardData } from '@/hooks/useDashboardData'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { cn, formatCurrencyMx } from '@/lib/utils'

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    window.setTimeout(resolve, ms)
  })
}
import {
  DATA_SOURCE_OPTIONS,
  filterSnapshotMovements,
  type DataSourceMode,
} from '@/services/dashboardService'
import { SEGMENT_OPTIONS } from '@/services/mockDashboardData'
import type { MrrCategory, ProductSegment } from '@/types/mrr'

type KpiCardProps = {
  label: string
  value: string
  hint: string
  tone?: 'positive' | 'info' | 'neutral'
  onClick?: () => void
}

function KpiCard({ label, value, hint, tone = 'neutral', onClick }: KpiCardProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="glass-panel p-4 text-left transition hover:border-blue-500/30 md:p-5"
    >
      <p className="text-sm text-slate-500 dark:text-slate-400">{label}</p>
      <p className="amount-tabular font-heading mt-2 text-2xl font-semibold text-[#090D16] dark:text-white md:text-[1.65rem]">
        {value}
      </p>
      <p
        className={cn(
          'mt-2 text-xs font-medium md:text-sm',
          tone === 'positive' && 'text-emerald-500',
          tone === 'info' && 'text-[#0066FF] dark:text-[#0A84FF]',
          tone === 'neutral' && 'text-slate-500 dark:text-slate-400',
        )}
      >
        {hint}
      </p>
    </button>
  )
}

function DashboardSkeleton() {
  return (
    <div className="space-y-5 md:space-y-6" aria-busy="true" aria-label="Cargando dashboard">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, index) => (
          <Skeleton key={index} className="h-28 w-full rounded-2xl bg-slate-200/80 dark:bg-white/[0.05]" />
        ))}
      </div>
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <Skeleton className="h-80 w-full rounded-2xl bg-slate-200/80 dark:bg-white/[0.05]" />
        <Skeleton className="h-80 w-full rounded-2xl bg-slate-200/80 dark:bg-white/[0.05]" />
      </div>
      <Skeleton className="h-56 w-full rounded-2xl bg-slate-200/80 dark:bg-white/[0.05]" />
    </div>
  )
}

function PillGroup<T extends string>({
  label,
  options,
  value,
  onChange,
}: {
  label: string
  options: Array<{ value: T; label: string }>
  value: T
  onChange: (value: T) => void
}) {
  return (
    <div className="ios-pill-track" role="group" aria-label={label}>
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          onClick={() => onChange(option.value)}
          className={cn(
            'rounded-xl px-3 py-1.5 text-sm transition',
            value === option.value
              ? 'ios-pill-active'
              : 'text-slate-600 hover:bg-white/80 dark:text-slate-300 dark:hover:bg-white/5',
          )}
        >
          {option.label}
        </button>
      ))}
    </div>
  )
}

/**
 * Dashboard Liquid Glass — funcionalidad intacta + estética iOS.
 */
export function DashboardPage() {
  const { user } = useAuth()
  const { withScreenLock } = useLoadingLock()
  const {
    segmento: lockedSegmentFilter,
    segmentOptions,
    isSegmentLocked,
  } = useSegmentLock()
  const [segment, setSegment] = useState<ProductSegment>(
    isSegmentLocked ? (lockedSegmentFilter as ProductSegment) : 'Todos',
  )
  const [month, setMonth] = useState('2026-09')
  const [mode, setMode] = useState<DataSourceMode>('mock')
  const [activeCategory, setActiveCategory] = useState<MrrCategory | null>(null)

  useEffect(() => {
    if (isSegmentLocked) {
      setSegment(lockedSegmentFilter as ProductSegment)
    }
  }, [isSegmentLocked, lockedSegmentFilter])

  const {
    data: snapshot,
    isLoading,
    isFetching,
    isError,
    isEmpty,
    isApiError,
    errorMessage,
    refetch,
  } = useDashboardData({ mode, segment, month })

  const tableMovements = useMemo(() => {
    if (!snapshot) return []
    return filterSnapshotMovements(snapshot, activeCategory, mode)
  }, [snapshot, activeCategory, mode])

  const firstName = user?.nombre?.split(' ')[0] ?? 'Usuario'

  /** Candado breve al cambiar fuente / segmento / mes o al recargar. */
  async function runDashboardSync(
    action: () => void | Promise<unknown>,
    message = 'Sincronizando métricas del ERP...',
  ) {
    await withScreenLock(async () => {
      await action()
      await sleep(450)
    }, message)
  }

  return (
    <div className="space-y-5 md:space-y-6">
      <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            ¡Bienvenido de vuelta,{' '}
            <span className="font-semibold text-[#0066FF] dark:text-[#0A84FF]">
              {firstName.toUpperCase()}
            </span>
            !
          </p>
          <h1 className="font-heading mt-1 text-2xl font-semibold text-[#090D16] dark:text-white md:text-3xl">
            MRR y crecimiento
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 md:text-base">
            {snapshot?.periodLabel ??
              (mode === 'mock'
                ? 'Cierre de septiembre 2026 · Datos de ejemplo'
                : 'Cargando datos de API real…')}
          </p>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
          <PillGroup
            label="Fuente de datos"
            value={mode}
            onChange={(next) => {
              if (next === mode) return
              void runDashboardSync(() => {
                setMode(next)
                setActiveCategory(null)
              }, 'Sincronizando métricas del ERP...')
            }}
            options={DATA_SOURCE_OPTIONS.map((option) => ({
              value: option.value,
              label: option.label,
            }))}
          />

          <PillGroup
            label="Filtro de segmento"
            value={segment}
            onChange={(next) => {
              if (isSegmentLocked || next === segment) return
              void runDashboardSync(() => {
                setSegment(next)
                setActiveCategory(null)
              }, 'Sincronizando métricas del ERP...')
            }}
            options={(isSegmentLocked ? segmentOptions : SEGMENT_OPTIONS).map((option) => ({
              value: option as ProductSegment,
              label: option,
            }))}
          />

          <label className="ios-pill-track items-center gap-2 px-3 py-1.5 text-sm text-slate-600 dark:text-slate-300">
            <span className="sr-only">Mes</span>
            <select
              className="bg-transparent outline-none"
              value={month}
              onChange={(event) => {
                const next = event.target.value
                if (next === month) return
                void runDashboardSync(() => {
                  setMonth(next)
                  setActiveCategory(null)
                }, 'Sincronizando métricas del ERP...')
              }}
              aria-label="Selector de mes"
            >
              <option value="2026-09">Sep 2026</option>
              <option value="2026-08">Ago 2026</option>
              <option value="2026-07">Jul 2026</option>
            </select>
          </label>
        </div>
      </header>

      {(isLoading || (mode === 'api' && isFetching && !snapshot)) && <DashboardSkeleton />}

      {!isLoading && isError && isApiError && (
        <section className="glass-panel border-red-500/30 p-5">
          <h2 className="text-base font-semibold text-red-500">No se pudieron cargar los datos</h2>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{errorMessage}</p>
          <Button
            className="mt-4 rounded-xl"
            onClick={() =>
              void runDashboardSync(() => refetch(), 'Sincronizando métricas del ERP...')
            }
          >
            Reintentar
          </Button>
        </section>
      )}

      {!isLoading && !isApiError && (isEmpty || (isError && !isApiError)) && (
        <section className="glass-panel p-5 text-center">
          <h2 className="text-base font-semibold">Sin datos</h2>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            No hay información de MRR para el segmento y mes seleccionados.
          </p>
          {mode === 'api' && (
            <Button
              className="mt-4 rounded-xl"
              variant="outline"
              onClick={() =>
                void runDashboardSync(() => refetch(), 'Sincronizando métricas del ERP...')
              }
            >
              Reintentar
            </Button>
          )}
        </section>
      )}

      {snapshot && !isEmpty && !isApiError && (
        <>
          <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard
              label="MRR"
              value={formatCurrencyMx(snapshot.kpis.mrrMxn)}
              hint={`${snapshot.kpis.mrrDeltaVsPrevPercent} vs agosto`}
              tone="positive"
              onClick={() => setActiveCategory(null)}
            />
            <KpiCard
              label="Contratos activos"
              value={String(snapshot.kpis.activeContracts)}
              hint={`+${snapshot.kpis.newContracts} nuevos · -${snapshot.kpis.churnedContracts} bajas`}
              tone="info"
              onClick={() => setActiveCategory('nuevo')}
            />
            <KpiCard
              label="NRR 12 meses"
              value={snapshot.kpis.nrr12mPercent}
              hint={`GRR ${snapshot.kpis.grrPercent}`}
              tone="info"
            />
            <KpiCard
              label="Cartera vencida"
              value={formatCurrencyMx(snapshot.kpis.overduePortfolioMxn)}
              hint={`${snapshot.kpis.overdueOver60Count} contratos a más de 60 días`}
            />
          </section>

          <section className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <MrrBridgeChart
              steps={snapshot.bridge}
              resolveMovements={(category) =>
                filterSnapshotMovements(snapshot, category, mode)
              }
              onCategorySelect={setActiveCategory}
            />
            <AgingPortfolioCard buckets={snapshot.aging} />
          </section>

          <RecentMovementsTable
            movements={tableMovements}
            activeCategory={activeCategory}
          />
        </>
      )}
    </div>
  )
}
