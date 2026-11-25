import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Building2, Check } from 'lucide-react'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppCurrencyInput, AppInput } from '@/components/ui/AppInput'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { useToast } from '@/context/ToastContext'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegments, SEGMENTS_QUERY_KEY } from '@/hooks/useSegments'
import {
  updateSegmentCommercialParams,
  type SegmentOption,
} from '@/services/catalogService'
import { Skeleton } from '@/components/ui/skeleton'
import {
  exchangeRateSchema,
  nonNegativeIntSchema,
  sanitizeNumericTyping,
} from '@/lib/validationSchemas'

function normalizeFxInput(raw: string): string {
  const parsed = exchangeRateSchema().safeParse(raw)
  if (!parsed.success) return '0.0000'
  const num = Number(parsed.data)
  if (!Number.isFinite(num) || num < 0) return '0.0000'
  return num.toFixed(4)
}

/**
 * Empresa — parámetros comerciales por segmento (RF-1).
 * Lee/guarda `segments.default_credit_days` y `segments.usd_exchange_rate`.
 */
export function CompanySettingsPage() {
  const toast = useToast()
  const { withScreenLock } = useLoadingLock()
  const queryClient = useQueryClient()
  const { canEdit, canView } = usePermissions()
  const { segments, isLoading } = useSegments({
    enabled: canView('config_company'),
  })

  const canEditCompany = canEdit('config_company')

  const [draftDays, setDraftDays] = useState<Record<number, number>>({})
  const [draftFx, setDraftFx] = useState<Record<number, string>>({})

  useEffect(() => {
    const nextDays: Record<number, number> = {}
    const nextFx: Record<number, string> = {}
    for (const seg of segments) {
      nextDays[seg.id] = seg.default_credit_days
      nextFx[seg.id] = normalizeFxInput(seg.usd_exchange_rate)
    }
    setDraftDays(nextDays)
    setDraftFx(nextFx)
  }, [segments])

  const hasChanges = useMemo(() => {
    return segments.some((seg) => {
      const daysChanged =
        (draftDays[seg.id] ?? seg.default_credit_days) !== seg.default_credit_days
      const fxChanged =
        normalizeFxInput(draftFx[seg.id] ?? seg.usd_exchange_rate) !==
        normalizeFxInput(seg.usd_exchange_rate)
      return daysChanged || fxChanged
    })
  }, [segments, draftDays, draftFx])

  const saveMutation = useMutation({
    mutationFn: async (rows: SegmentOption[]) => {
      const changed = rows.filter((seg) => {
        const daysChanged =
          (draftDays[seg.id] ?? seg.default_credit_days) !== seg.default_credit_days
        const fxChanged =
          normalizeFxInput(draftFx[seg.id] ?? seg.usd_exchange_rate) !==
          normalizeFxInput(seg.usd_exchange_rate)
        return daysChanged || fxChanged
      })
      for (const seg of changed) {
        await updateSegmentCommercialParams(seg.id, {
          default_credit_days: Math.max(
            0,
            Math.trunc(draftDays[seg.id] ?? seg.default_credit_days),
          ),
          usd_exchange_rate: normalizeFxInput(draftFx[seg.id] ?? seg.usd_exchange_rate),
        })
      }
      return changed.length
    },
    onSuccess: async (count) => {
      await queryClient.invalidateQueries({ queryKey: SEGMENTS_QUERY_KEY })
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

  async function onSave() {
    if (!canEditCompany || !hasChanges) return
    for (const seg of segments) {
      const daysOk = nonNegativeIntSchema().safeParse(
        String(draftDays[seg.id] ?? seg.default_credit_days),
      )
      const fxOk = exchangeRateSchema().safeParse(
        draftFx[seg.id] ?? seg.usd_exchange_rate,
      )
      if (!daysOk.success || !fxOk.success) {
        toast.error(
          'Datos inválidos',
          `${seg.name}: revisa días de crédito y tipo de cambio.`,
        )
        return
      }
    }
    await withScreenLock(
      () => saveMutation.mutateAsync(segments),
      'Guardando parámetros comerciales...',
    )
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="font-heading text-2xl font-semibold md:text-3xl">Empresa</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Parámetros comerciales por segmento: días de crédito por defecto y tipo de cambio
          USD→MXN (RF-1).
        </p>
      </header>

      <AppCard className="space-y-4 p-5 md:p-6">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400">
            <Building2 className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="font-heading text-base font-semibold">
              Parámetros Comerciales por Segmento
            </h2>
            <p className="text-xs text-slate-500 dark:text-zinc-400">
              Los días de crédito se heredan al alta de clientes/contratos. El tipo de cambio
              USD→MXN se usa cuando un contrato en USD impacta `mrr_delta_mxn`.
            </p>
          </div>
        </div>

        {isLoading ? (
          <Skeleton className="h-40 w-full rounded-2xl" />
        ) : (
          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            {segments.map((seg) => (
              <AppCard key={seg.id} className="space-y-3 p-4">
                <p className="font-heading text-sm font-semibold">{seg.name}</p>
                <label className="block text-sm">
                  <span className="text-[11px] text-slate-500">
                    Días de crédito por defecto
                  </span>
                  <AppInput
                    numericMode="integer"
                    mono
                    withLabelSpacing
                    disabled={!canEditCompany || saveMutation.isPending}
                    value={String(draftDays[seg.id] ?? seg.default_credit_days)}
                    onChange={(e) => {
                      const sanitized = sanitizeNumericTyping(e.target.value, 'integer')
                      const parsed = Number.parseInt(sanitized || '0', 10)
                      setDraftDays((prev) => ({
                        ...prev,
                        [seg.id]: Number.isFinite(parsed) ? Math.max(0, parsed) : 0,
                      }))
                    }}
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
                    <AppCurrencyInput
                      numericMode="decimal4"
                      disabled={!canEditCompany || saveMutation.isPending}
                      value={draftFx[seg.id] ?? seg.usd_exchange_rate}
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
                            prev[seg.id] ?? seg.usd_exchange_rate,
                          ),
                        }))
                      }}
                      className="pl-7 pr-16 text-left"
                    />
                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 font-mono text-[11px] text-slate-400">
                      MXN
                    </span>
                  </div>
                </label>
              </AppCard>
            ))}
          </div>
        )}

        {!isLoading && segments.length === 0 ? (
          <p className="text-sm text-slate-500">No hay segmentos en tu alcance.</p>
        ) : null}

        <div className="flex justify-end">
          <AppButton
            type="button"
            disabled={!canEditCompany || !hasChanges}
            isLoading={saveMutation.isPending}
            onClick={() => void onSave()}
          >
            <Check className="h-4 w-4" />
            Guardar parámetros
          </AppButton>
        </div>
      </AppCard>
    </div>
  )
}
