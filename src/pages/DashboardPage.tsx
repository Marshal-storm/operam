import { useEffect, useMemo, useState } from 'react'
import { AgingPortfolioCard } from '@/components/dashboard/AgingPortfolioCard'
import { MrrBridgeChart } from '@/components/dashboard/MrrBridgeChart'
import { RecentMovementsTable } from '@/components/dashboard/RecentMovementsTable'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterField } from '@/components/ui/AppFilterField'
import { AppSelect } from '@/components/ui/AppSelect'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/context/AuthContext'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { useDashboardData } from '@/hooks/useDashboardData'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { cn, formatCurrencyMx } from '@/lib/utils'
import {
  DATA_SOURCE_OPTIONS,
  filterSnapshotMovements,
  type DataSourceMode,
} from '@/services/dashboardService'
import { SEGMENT_OPTIONS } from '@/services/mockDashboardData'
import type { MrrCategory } from '@/types/mrr'

/** Meses fijos del dataset de demostración (Mockup sep-2026). */
const MOCK_MONTH_OPTIONS = [
  { value: '2026-09', label: 'Sep 2026' },
  { value: '2026-08', label: 'Ago 2026' },
  { value: '2026-07', label: 'Jul 2026' },
] as const

const MOCK_DEFAULT_MONTH = '2026-09'

const MONTH_SHORT_ES = [
  'Ene',
  'Feb',
  'Mar',
  'Abr',
  'May',
  'Jun',
  'Jul',
  'Ago',
  'Sep',
  'Oct',
  'Nov',
  'Dic',
] as const

const MONTH_LONG_ES = [
  'enero',
  'febrero',
  'marzo',
  'abril',
  'mayo',
  'junio',
  'julio',
  'agosto',
  'septiembre',
  'octubre',
  'noviembre',
  'diciembre',
] as const

/** Mes actual `YYYY-MM` en zona America/Mexico_City. */
function currentMonthKeyMexicoCity(now = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Mexico_City',
    year: 'numeric',
    month: '2-digit',
  }).format(now)
}

/** Últimos 12 meses (mes en curso primero) para modo API Real. */
function buildLast12MonthOptions(referenceMonth = currentMonthKeyMexicoCity()): Array<{
  value: string
  label: string
}> {
  const [yearRaw, monthRaw] = referenceMonth.split('-').map(Number)
  let year = yearRaw
  let month = monthRaw
  const options: Array<{ value: string; label: string }> = []
  for (let i = 0; i < 12; i += 1) {
    const value = `${year}-${String(month).padStart(2, '0')}`
    options.push({
      value,
      label: `${MONTH_SHORT_ES[month - 1]} ${year}`,
    })
    month -= 1
    if (month < 1) {
      month = 12
      year -= 1
    }
  }
  return options
}

function previousMonthKey(month: string): string {
  const [yearRaw, monthRaw] = month.split('-').map(Number)
  let year = yearRaw
  let monthNum = monthRaw - 1
  if (monthNum < 1) {
    monthNum = 12
    year -= 1
  }
  return `${year}-${String(monthNum).padStart(2, '0')}`
}

function monthLongLabelEs(month: string): string {
  const [, monthRaw] = month.split('-').map(Number)
  return MONTH_LONG_ES[(monthRaw ?? 1) - 1] ?? month
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    window.setTimeout(resolve, ms)
  })
}

type KpiCardProps = {
  label: string
  value: string
  hint: string
  tone?: 'positive' | 'info' | 'neutral'
  onClick?: () => void
}

function KpiCard({ label, value, hint, tone = 'neutral', onClick }: KpiCardProps) {
  return (
    <AppButton
      type="button"
      variant="ghost"
      onClick={onClick}
      className="h-auto w-full flex-col items-start justify-start gap-0 rounded-2xl border border-slate-200/80 bg-white p-4 text-left font-normal shadow-[0_8px_30px_rgb(0,0,0,0.04)] backdrop-blur-2xl hover:border-blue-500/30 hover:bg-white dark:border-white/[0.09] dark:bg-white/[0.03] dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.12),0_12px_32px_rgba(0,0,0,0.6)] dark:hover:bg-white/[0.03] md:p-5"
    >
      <p className="text-sm font-normal text-slate-500 dark:text-slate-400">{label}</p>
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
    </AppButton>
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
  disabled = false,
}: {
  label: string
  options: Array<{ value: T; label: string }>
  value: T
  onChange: (value: T) => void
  disabled?: boolean
}) {
  return (
    <AppFilterField label={label}>
      <div className="ios-pill-track" role="group" aria-label={label}>
        {options.map((option) => {
          const active = value === option.value
          return (
            <AppButton
              key={option.value}
              type="button"
              variant="ghost"
              size="sm"
              disabled={disabled}
              onClick={() => onChange(option.value)}
              className={cn(
                'h-auto rounded-xl px-3 py-1.5 text-sm font-normal',
                active
                  ? 'bg-[#0066FF] font-medium text-white shadow-[0_0_15px_rgba(0,102,255,0.4)] hover:bg-[#0055DD] hover:text-white dark:bg-[#0A84FF] dark:text-white dark:shadow-[0_0_20px_rgba(10,132,255,0.35)] dark:hover:bg-[#0A74E0]'
                  : 'text-slate-600 hover:bg-white/80 dark:text-slate-300 dark:hover:bg-white/5',
              )}
            >
              {option.label}
            </AppButton>
          )
        })}
      </div>
    </AppFilterField>
  )
}

/**
 * Dashboard Liquid Glass — funcionalidad intacta + estética iOS.
 */
export function DashboardPage() {
  const { user } = useAuth()
  const { withScreenLock } = useLoadingLock()
  const {
    segment: lockedSegmentFilter,
    segmentOptions,
    isSegmentLocked,
  } = useSegmentLock()
  const [segment, setSegment] = useState<string>(
    isSegmentLocked ? lockedSegmentFilter : 'Todos',
  )
  const [month, setMonth] = useState(MOCK_DEFAULT_MONTH)
  const [mode, setMode] = useState<DataSourceMode>('mock')
  const [activeCategory, setActiveCategory] = useState<MrrCategory | null>(null)

  const apiMonthOptions = useMemo(() => buildLast12MonthOptions(), [])
  const monthOptions = mode === 'api' ? apiMonthOptions : [...MOCK_MONTH_OPTIONS]

  const segmentChoices = useMemo(() => {
    if (mode === 'api' || isSegmentLocked) return segmentOptions
    return SEGMENT_OPTIONS.map((option) => ({ value: option, label: option }))
  }, [mode, isSegmentLocked, segmentOptions])

  useEffect(() => {
    if (isSegmentLocked) {
      setSegment(lockedSegmentFilter)
      return
    }
    if (mode === 'mock' && !SEGMENT_OPTIONS.includes(segment)) {
      setSegment('Todos')
    }
  }, [isSegmentLocked, lockedSegmentFilter, mode, segment])

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

  const prevMonthHint = useMemo(() => monthLongLabelEs(previousMonthKey(month)), [month])

  const subtitle = useMemo(() => {
    if (mode === 'api' && (isLoading || isFetching)) {
      return 'Cargando datos de API real...'
    }
    if (snapshot?.periodLabel) {
      return snapshot.periodLabel
    }
    if (mode === 'mock') {
      return 'Cierre de septiembre 2026 · Datos de ejemplo'
    }
    const [year, monthNum] = month.split('-').map(Number)
    const name = MONTH_LONG_ES[(monthNum ?? 1) - 1] ?? month
    return `Cierre de ${name} ${year} · Datos en vivo de MySQL`
  }, [mode, isLoading, isFetching, snapshot?.periodLabel, month])

  const tableMovements = useMemo(() => {
    if (!snapshot) return []
    return filterSnapshotMovements(snapshot, activeCategory, mode)
  }, [snapshot, activeCategory, mode])

  const firstName = user?.name?.split(' ')[0] ?? 'Usuario'

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
            {subtitle}
          </p>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
          <PillGroup
            label="Fuente"
            value={mode}
            onChange={(next) => {
              if (next === mode) return
              void runDashboardSync(() => {
                setMode(next)
                setMonth(next === 'api' ? currentMonthKeyMexicoCity() : MOCK_DEFAULT_MONTH)
                setActiveCategory(null)
              }, 'Sincronizando métricas del ERP...')
            }}
            options={DATA_SOURCE_OPTIONS.map((option) => ({
              value: option.value,
              label: option.label,
            }))}
          />

          <PillGroup
            label="Segmento"
            value={segment}
            disabled={isSegmentLocked}
            onChange={(next) => {
              if (isSegmentLocked || next === segment) return
              void runDashboardSync(() => {
                setSegment(next)
                setActiveCategory(null)
              }, 'Sincronizando métricas del ERP...')
            }}
            options={segmentChoices}
          />

          <AppFilterField label="Mes">
            <AppSelect
              aria-label="Mes"
              value={month}
              onValueChange={(next) => {
                if (next === month) return
                void runDashboardSync(() => {
                  setMonth(next)
                  setActiveCategory(null)
                }, 'Sincronizando métricas del ERP...')
              }}
              options={monthOptions}
              triggerClassName="h-9 w-[9.5rem] rounded-2xl border-slate-200 bg-slate-100 px-3 dark:border-white/[0.08] dark:bg-white/[0.05]"
            />
          </AppFilterField>
        </div>
      </header>

      {(isLoading || (mode === 'api' && isFetching && !snapshot)) && <DashboardSkeleton />}

      {!isLoading && isError && isApiError && (
        <AppCard className="border-red-500/30 p-5">
          <h2 className="text-base font-semibold text-red-500">No se pudieron cargar los datos</h2>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{errorMessage}</p>
          <AppButton
            type="button"
            className="mt-4"
            onClick={() =>
              void runDashboardSync(() => refetch(), 'Sincronizando métricas del ERP...')
            }
          >
            Reintentar
          </AppButton>
        </AppCard>
      )}

      {!isLoading && !isApiError && (isEmpty || (isError && !isApiError)) && (
        <AppCard className="p-5 text-center">
          <h2 className="text-base font-semibold">Sin datos</h2>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            No hay información de MRR para el segmento y mes seleccionados.
          </p>
          {mode === 'api' && (
            <AppButton
              type="button"
              className="mt-4"
              variant="outline"
              onClick={() =>
                void runDashboardSync(() => refetch(), 'Sincronizando métricas del ERP...')
              }
            >
              Reintentar
            </AppButton>
          )}
        </AppCard>
      )}

      {snapshot && !isEmpty && !isApiError && (
        <>
          <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard
              label="MRR"
              value={formatCurrencyMx(snapshot.kpis.mrrMxn)}
              hint={`${snapshot.kpis.mrrDeltaVsPrevPercent} vs ${prevMonthHint}`}
              tone="positive"
              onClick={() => setActiveCategory(null)}
            />
            <KpiCard
              label="Contratos activos"
              value={String(snapshot.kpis.activeContracts)}
              hint={`+${snapshot.kpis.newContracts} nuevos · -${snapshot.kpis.churnedContracts} bajas`}
              tone="info"
              onClick={() => setActiveCategory('new')}
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
