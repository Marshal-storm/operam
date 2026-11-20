import { useMemo, useState } from 'react'
import {
  Bar,
  BarChart,
  Cell,
  LabelList,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppCard } from '@/components/ui/AppCard'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { useTheme } from '@/context/ThemeContext'
import { formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import type { MrrBridgeStep, MrrCategory, MrrMovement } from '@/types/mrr'

type MrrBridgeChartProps = {
  steps: MrrBridgeStep[]
  resolveMovements: (category: MrrCategory) => MrrMovement[]
  onCategorySelect?: (category: MrrCategory | null) => void
}

type VisualBridgeStep = MrrBridgeStep & {
  visualStart: number
  visualSize: number
  themeColor: string
}

function resolveVisualFloor(steps: MrrBridgeStep[]): number {
  const floatingStarts = steps
    .filter((step) => step.type !== 'total')
    .map((step) => step.start)
  const minFloating = floatingStarts.length > 0 ? Math.min(...floatingStarts) : 0
  const raw = Math.trunc((minFloating * 96) / 100)
  return Math.trunc(raw / 1000) * 1000
}

function themeBarColor(
  step: MrrBridgeStep,
  isDark: boolean,
): string {
  if (step.category === 'start') return isDark ? '#3F3F46' : '#334155'
  if (step.category === 'end') return isDark ? '#FAFAFA' : '#0F172A'
  if (step.type === 'negative') return isDark ? '#F97316' : '#EF4444'
  return isDark ? '#0A84FF' : '#2563EB'
}

function toVisualSteps(
  steps: MrrBridgeStep[],
  floor: number,
  isDark: boolean,
): VisualBridgeStep[] {
  return steps.map((step) => {
    const themeColor = themeBarColor(step, isDark)
    if (step.type === 'total') {
      return {
        ...step,
        visualStart: 0,
        visualSize: Math.max(step.size - floor, 0),
        themeColor,
      }
    }

    return {
      ...step,
      visualStart: Math.max(step.start - floor, 0),
      visualSize: step.size,
      themeColor,
    }
  })
}

function MovementList({ movements }: { movements: MrrMovement[] }) {
  if (movements.length === 0) {
    return (
      <p className="text-sm text-slate-500 dark:text-slate-400">
        No hay movimientos en esta categoría para el segmento seleccionado.
      </p>
    )
  }

  return (
    <ul className="max-h-80 space-y-3 overflow-y-auto">
      {movements.map((movement) => {
        const negative = movement.mrr_delta_mxn.startsWith('-')
        return (
          <li
            key={movement.id}
            className="rounded-2xl border border-slate-200/80 bg-slate-50/80 p-3 text-sm dark:border-white/[0.09] dark:bg-white/[0.03]"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-[#0A84FF] to-[#6366F1] text-xs font-bold text-white">
                  {movement.client_name.replace(/[\[\]]/g, '').slice(0, 1).toUpperCase()}
                </span>
                <span className="font-medium">
                  {formatDateEsMx(movement.effective_date)} · {movement.contract_folio}
                </span>
              </div>
              <AppBadge
                label={`${negative ? '-' : '+'}${formatCurrencyMx(
                  negative ? movement.mrr_delta_mxn.slice(1) : movement.mrr_delta_mxn,
                )}`}
                tone={negative ? 'danger' : 'success'}
              />
            </div>
            <p className="mt-2 text-slate-500 dark:text-slate-400">
              {movement.client_name} · {movement.segment}
            </p>
            <p className="mt-0.5">{movement.description}</p>
          </li>
        )
      })}
    </ul>
  )
}

/**
 * Cascada Puente de MRR — colores dinámicos Liquid Glass.
 */
export function MrrBridgeChart({
  steps,
  resolveMovements,
  onCategorySelect,
}: MrrBridgeChartProps) {
  const { resolvedTheme } = useTheme()
  const isDark = resolvedTheme === 'dark'
  const [selectedCategory, setSelectedCategory] = useState<MrrCategory | null>(null)

  const floor = useMemo(() => resolveVisualFloor(steps), [steps])
  const visualData = useMemo(
    () => toVisualSteps(steps, floor, isDark),
    [steps, floor, isDark],
  )
  const yMax = useMemo(
    () =>
      Math.max(
        ...visualData.map((step) => step.visualStart + step.visualSize),
        1,
      ) + 8_000,
    [visualData],
  )

  const selectedStep = steps.find((step) => step.category === selectedCategory)
  const detailMovements =
    selectedCategory != null ? resolveMovements(selectedCategory) : []

  const axisColor = isDark ? '#94A3B8' : '#64748B'
  const labelColor = isDark ? '#F8FAFC' : '#0F172A'
  const chartFontFamily = '"Ubuntu", "DM Sans", system-ui, sans-serif'

  function handleBarClick(category: MrrCategory) {
    if (category === 'start' || category === 'end') {
      setSelectedCategory(category)
      onCategorySelect?.(null)
      return
    }
    setSelectedCategory(category)
    onCategorySelect?.(category)
  }

  return (
    <AppCard className="p-4 md:p-5">
      <header className="mb-4">
        <h2 className="text-base font-semibold md:text-lg">Puente de MRR del mes</h2>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          Inicio + deltas por categoría = Fin (bitácora `movimiento`)
        </p>
      </header>

      <div className="h-72 w-full md:h-80">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart
            data={visualData}
            margin={{ top: 28, right: 8, left: 0, bottom: 8 }}
            barCategoryGap="18%"
          >
            <XAxis
              dataKey="label"
              tickLine={false}
              axisLine={false}
              interval={0}
              tick={{ fill: axisColor, fontSize: 11, fontFamily: chartFontFamily }}
              height={48}
            />
            <YAxis hide domain={[0, yMax]} />
            <Tooltip
              cursor={{ fill: isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.04)' }}
              content={({ active, payload }) => {
                if (!active || !payload?.[0]) return null
                const step = payload[0].payload as VisualBridgeStep
                return (
                  <div className="rounded-xl border border-white/[0.09] bg-[#111113]/90 px-3 py-2 font-sans text-xs text-white shadow-xl backdrop-blur-md">
                    <p className="font-heading font-medium">{step.label}</p>
                    <p className="amount-tabular mt-1 text-slate-300">
                      {step.type === 'total'
                        ? formatCurrencyMx(step.size)
                        : `${step.deltaMxn < 0 ? '-' : '+'}${formatCurrencyMx(Math.abs(step.deltaMxn))}`}
                    </p>
                    <p className="mt-1 text-slate-400">
                      Acumulado: {formatCurrencyMx(step.accumulated)}
                    </p>
                  </div>
                )
              }}
            />
            <Bar dataKey="visualStart" stackId="mrr" fill="transparent" isAnimationActive={false} />
            <Bar
              dataKey="visualSize"
              stackId="mrr"
              radius={[4, 4, 4, 4]}
              isAnimationActive={false}
              cursor="pointer"
              onClick={(_, index) => {
                const step = visualData[index]
                if (step) handleBarClick(step.category)
              }}
            >
              {visualData.map((step) => (
                <Cell key={step.category} fill={step.themeColor} />
              ))}
              <LabelList
                dataKey="displayLabel"
                position="top"
                fill={labelColor}
                style={{ fontFamily: chartFontFamily, fontSize: 11, fontWeight: 500 }}
              />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>

      <AppModal
        open={selectedCategory != null}
        onOpenChange={(open) => {
          if (!open) {
            setSelectedCategory(null)
            onCategorySelect?.(null)
          }
        }}
      >
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>
              {selectedStep?.label ?? 'Categoría'} — movimientos
            </AppModalTitle>
            <AppModalDescription>
              RF-3.7: detalle de la bitácora que compone esta cifra del puente.
            </AppModalDescription>
          </AppModalHeader>
          {selectedCategory === 'start' || selectedCategory === 'end' ? (
            <p className="text-sm text-slate-500 dark:text-slate-400">
              {selectedCategory === 'start'
                ? `MRR de apertura del periodo: ${formatCurrencyMx(selectedStep?.size ?? 0)}.`
                : `MRR de cierre del periodo: ${formatCurrencyMx(selectedStep?.size ?? 0)}.`}
            </p>
          ) : (
            <MovementList movements={detailMovements} />
          )}
        </AppModalContent>
      </AppModal>
    </AppCard>
  )
}
