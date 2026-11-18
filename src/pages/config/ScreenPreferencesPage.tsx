import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, CreditCard, LayoutList, Rows3 } from 'lucide-react'
import {
  PAGE_SIZE_OPTIONS,
  useScreenPreferences,
  type PageSizeOption,
  type TableDensity,
} from '@/context/ScreenPreferencesContext'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { useToast } from '@/context/ToastContext'
import { usePermissions } from '@/hooks/usePermissions'
import {
  fetchSegments,
  updateSegmentCommercialParams,
  type SegmentOption,
} from '@/services/catalogService'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/** Segmentos comerciales principales del documento de diseño (RF-1). */
const PRIMARY_SEGMENT_NAMES = new Set(['Operam', 'Construsync'])

function normalizeFxInput(raw: string): string {
  const cleaned = raw.trim().replace(/,/g, '')
  const num = Number(cleaned)
  if (!Number.isFinite(num) || num < 0) return '0.0000'
  return num.toFixed(4)
}

/**
 * Preferencias de pantalla — pageSize, densidad y parámetros comerciales RF-1.
 */
export function ScreenPreferencesPage() {
  const { pageSize, density, setPageSize, setDensity } = useScreenPreferences()
  const toast = useToast()
  const { withScreenLock } = useLoadingLock()
  const queryClient = useQueryClient()
  const { canEdit, canView, isSegmentLocked, lockedSegmentId, allowedSegment } =
    usePermissions()

  const canEditCommercial = canEdit('config_preferencias')
  const canViewCommercial =
    canView('config_preferencias') || canEdit('config_preferencias')

  const segmentsQuery = useQuery({
    queryKey: ['config', 'segments', 'commercial-params'],
    queryFn: fetchSegments,
    staleTime: 30_000,
    enabled: canViewCommercial,
  })

  const editableSegments = useMemo(() => {
    const rows = segmentsQuery.data ?? []
    const primary = rows.filter((s) => PRIMARY_SEGMENT_NAMES.has(s.nombre))
    const scoped = isSegmentLocked
      ? primary.filter(
          (s) =>
            s.id === lockedSegmentId ||
            s.nombre === allowedSegment,
        )
      : primary
    return scoped.length > 0 ? scoped : primary
  }, [segmentsQuery.data, isSegmentLocked, lockedSegmentId, allowedSegment])

  const [draftDays, setDraftDays] = useState<Record<number, number>>({})
  const [draftFx, setDraftFx] = useState<Record<number, string>>({})

  useEffect(() => {
    const nextDays: Record<number, number> = {}
    const nextFx: Record<number, string> = {}
    for (const seg of editableSegments) {
      nextDays[seg.id] = seg.dias_credito_default
      nextFx[seg.id] = normalizeFxInput(seg.tipo_cambio_usd)
    }
    setDraftDays(nextDays)
    setDraftFx(nextFx)
  }, [editableSegments])

  const hasCommercialChanges = useMemo(() => {
    return editableSegments.some((seg) => {
      const daysChanged =
        (draftDays[seg.id] ?? seg.dias_credito_default) !== seg.dias_credito_default
      const fxChanged =
        normalizeFxInput(draftFx[seg.id] ?? seg.tipo_cambio_usd) !==
        normalizeFxInput(seg.tipo_cambio_usd)
      return daysChanged || fxChanged
    })
  }, [editableSegments, draftDays, draftFx])

  const saveCommercialMutation = useMutation({
    mutationFn: async (rows: SegmentOption[]) => {
      const changed = rows.filter((seg) => {
        const daysChanged =
          (draftDays[seg.id] ?? seg.dias_credito_default) !== seg.dias_credito_default
        const fxChanged =
          normalizeFxInput(draftFx[seg.id] ?? seg.tipo_cambio_usd) !==
          normalizeFxInput(seg.tipo_cambio_usd)
        return daysChanged || fxChanged
      })
      for (const seg of changed) {
        await updateSegmentCommercialParams(seg.id, {
          dias_credito_default: Math.max(
            0,
            Math.trunc(draftDays[seg.id] ?? seg.dias_credito_default),
          ),
          tipo_cambio_usd: normalizeFxInput(draftFx[seg.id] ?? seg.tipo_cambio_usd),
        })
      }
      return changed.length
    },
    onSuccess: async (count) => {
      await queryClient.invalidateQueries({ queryKey: ['config', 'segments'] })
      await segmentsQuery.refetch()
      toast.success(
        'Parámetros comerciales guardados',
        count === 1
          ? 'Se actualizó 1 segmento.'
          : `Se actualizaron ${count} segmentos.`,
      )
    },
    onError: (error) => {
      toast.apiError(error)
    },
  })

  function savePageSize(n: PageSizeOption) {
    setPageSize(n)
    toast.success('Preferencia guardada', `${n} filas por página.`)
  }

  function saveDensity(value: TableDensity) {
    setDensity(value)
    toast.success(
      'Densidad actualizada',
      value === 'compact' ? 'Tablas compactas.' : 'Tablas cómodas.',
    )
  }

  async function onSaveCommercial() {
    if (!canEditCommercial || !hasCommercialChanges) return
    await withScreenLock(
      () => saveCommercialMutation.mutateAsync(editableSegments),
      'Guardando parámetros comerciales...',
    )
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="font-heading text-2xl font-semibold md:text-3xl">
          Preferencias de pantalla
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Define cuántos registros ves por página y qué tan compactas lucen las tablas. Se guarda
          en este navegador.
        </p>
      </header>

      {canViewCommercial && (
        <section className="glass-panel space-y-4 p-5 md:p-6">
          <div className="flex items-start gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400">
              <CreditCard className="h-5 w-5" />
            </span>
            <div className="min-w-0 flex-1">
              <h2 className="font-heading text-base font-semibold">
                Parámetros Comerciales por Segmento (RF-1)
              </h2>
              <p className="text-xs text-slate-500 dark:text-zinc-400">
                Estos días de crédito se asignarán automáticamente por defecto al dar de alta
                nuevos clientes o contratos en cada segmento. El tipo de cambio USD→MXN se usa
                cuando un contrato en USD impacta `mrr_delta_mxn`.
              </p>
            </div>
          </div>

          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            {editableSegments.map((seg) => (
              <div
                key={seg.id}
                className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-4 dark:border-white/[0.09] dark:bg-white/[0.03]"
              >
                <p className="font-heading text-sm font-semibold">{seg.nombre}</p>
                <label className="block text-sm">
                  <span className="text-[11px] text-slate-500">
                    Días de crédito por defecto
                  </span>
                  <input
                    type="number"
                    min={0}
                    step={1}
                    inputMode="numeric"
                    disabled={!canEditCommercial || saveCommercialMutation.isPending}
                    value={draftDays[seg.id] ?? seg.dias_credito_default}
                    onChange={(e) => {
                      const parsed = Number.parseInt(e.target.value, 10)
                      setDraftDays((prev) => ({
                        ...prev,
                        [seg.id]: Number.isFinite(parsed) ? Math.max(0, parsed) : 0,
                      }))
                    }}
                    className="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm tabular-nums outline-none focus:ring-2 focus:ring-[#0066FF]/30 dark:border-white/[0.08] dark:bg-white/[0.05]"
                  />
                </label>
                <label className="block text-sm">
                  <span className="text-[11px] text-slate-500">
                    Tipo de cambio USD → MXN
                  </span>
                  <div className="relative mt-1">
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-mono text-xs text-slate-400">
                      $
                    </span>
                    <input
                      type="number"
                      min={0}
                      step="0.0001"
                      inputMode="decimal"
                      disabled={!canEditCommercial || saveCommercialMutation.isPending}
                      value={draftFx[seg.id] ?? seg.tipo_cambio_usd}
                      onChange={(e) => {
                        setDraftFx((prev) => ({
                          ...prev,
                          [seg.id]: e.target.value,
                        }))
                      }}
                      onBlur={() => {
                        setDraftFx((prev) => ({
                          ...prev,
                          [seg.id]: normalizeFxInput(
                            prev[seg.id] ?? seg.tipo_cambio_usd,
                          ),
                        }))
                      }}
                      className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-7 pr-16 font-mono text-sm tabular-nums outline-none focus:ring-2 focus:ring-[#0066FF]/30 dark:border-white/[0.08] dark:bg-white/[0.05]"
                    />
                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 font-mono text-[11px] text-slate-400">
                      MXN
                    </span>
                  </div>
                </label>
              </div>
            ))}
          </div>

          {editableSegments.length === 0 && (
            <p className="text-sm text-slate-500">No hay segmentos comerciales en tu alcance.</p>
          )}

          <div className="flex justify-end">
            <Button
              type="button"
              disabled={
                !canEditCommercial ||
                !hasCommercialChanges ||
                saveCommercialMutation.isPending
              }
              onClick={() => void onSaveCommercial()}
              className="rounded-xl"
            >
              Guardar días de crédito
            </Button>
          </div>
        </section>
      )}

      <section className="glass-panel space-y-4 p-5 md:p-6">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#0066FF]/10 text-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]">
            <LayoutList className="h-5 w-5" />
          </span>
          <div>
            <h2 className="font-heading text-base font-semibold">Resultados por página</h2>
            <p className="text-xs text-slate-500">
              Valor por defecto para Catálogo, Instancias, Listas y demás tablas paginadas.
            </p>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {PAGE_SIZE_OPTIONS.map((n) => (
            <button
              key={n}
              type="button"
              onClick={() => savePageSize(n as PageSizeOption)}
              className={cn(
                'relative min-w-[4.5rem] rounded-2xl border px-4 py-3 text-center transition',
                pageSize === n
                  ? 'border-[#0066FF]/40 bg-blue-50 dark:border-[#0A84FF]/40 dark:bg-[#0A84FF]/15'
                  : 'border-slate-200/80 bg-white hover:bg-slate-50 dark:border-white/[0.09] dark:bg-white/[0.03]',
              )}
            >
              {pageSize === n && (
                <Check className="absolute right-2 top-2 h-3.5 w-3.5 text-[#0066FF] dark:text-[#0A84FF]" />
              )}
              <p className="font-mono text-xl font-bold tabular-nums">{n}</p>
              <p className="text-[11px] text-slate-500">filas</p>
            </button>
          ))}
        </div>
      </section>

      <section className="glass-panel space-y-4 p-5 md:p-6">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-slate-100 text-slate-600 dark:bg-white/[0.06] dark:text-slate-300">
            <Rows3 className="h-5 w-5" />
          </span>
          <div>
            <h2 className="font-heading text-base font-semibold">Densidad de tablas</h2>
            <p className="text-xs text-slate-500">
              Cómoda para lectura diaria; Compacta cuando trabajas con muchos registros.
            </p>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {(
            [
              {
                value: 'comfortable' as TableDensity,
                title: 'Cómoda',
                hint: 'Más aire entre filas, ideal en pantallas grandes',
              },
              {
                value: 'compact' as TableDensity,
                title: 'Compacta',
                hint: 'Más filas visibles sin hacer scroll',
              },
            ] as const
          ).map((opt) => (
            <button
              key={opt.value}
              type="button"
              onClick={() => saveDensity(opt.value)}
              className={cn(
                'rounded-2xl border p-4 text-left transition',
                density === opt.value
                  ? 'border-[#0066FF]/40 bg-blue-50 dark:border-[#0A84FF]/40 dark:bg-[#0A84FF]/15'
                  : 'border-slate-200/80 hover:bg-slate-50 dark:border-white/[0.09] dark:hover:bg-white/[0.04]',
              )}
            >
              <p className="font-heading font-semibold">{opt.title}</p>
              <p className="mt-1 text-xs text-slate-500">{opt.hint}</p>
              <div className="mt-3 space-y-1">
                {[1, 2, 3].map((i) => (
                  <div
                    key={i}
                    className={cn(
                      'rounded-md bg-slate-200/80 dark:bg-white/10',
                      opt.value === 'compact' ? 'h-2' : 'h-3.5',
                    )}
                  />
                ))}
              </div>
            </button>
          ))}
        </div>
      </section>
    </div>
  )
}
