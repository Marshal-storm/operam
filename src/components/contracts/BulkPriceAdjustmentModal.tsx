import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, ArrowRight, Calculator, CheckCircle2, Percent } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import type { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppDrawer } from '@/components/ui/AppDrawer'
import { AppInput, AppTextarea } from '@/components/ui/AppInput'
import { AppSelect, type AppSelectOption } from '@/components/ui/AppSelect'
import { AppTableHeadRow } from '@/components/ui/AppTableWrapper'
import { useToast } from '@/context/ToastContext'
import { useBulkPriceAdjustment } from '@/hooks/useContracts'
import { cn, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import { bulkPriceAdjustmentSchema } from '@/lib/validationSchemas'
import type { BulkPriceAdjustmentInput, BulkPriceAdjustmentPreview } from '@/types/contract'

const FIELD_LABEL =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400'

const ALL = 'all'

type FormValues = z.infer<typeof bulkPriceAdjustmentSchema>

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Segmentos desde MySQL; con alcance fijo llega solo el segmento bloqueado. */
  segmentOptions: AppSelectOption[]
  lockedSegmentId: number | null
  issuerOptions: AppSelectOption[]
}

const DIRECTION_OPTIONS: AppSelectOption[] = [
  { value: 'increase', label: 'Incremento', tone: 'success' },
  { value: 'decrease', label: 'Decremento', tone: 'danger' },
]

function money(value: string): string {
  return formatCurrencyMx(value, { fractionDigits: 2 })
}

function signedMoney(value: string): string {
  return value.startsWith('-') ? money(value) : `+${money(value)}`
}

function deltaClass(value: string): string {
  if (value.startsWith('-')) return 'text-[#EF4444]'
  return Number(value) > 0 ? 'text-[#10B981]' : 'text-slate-500'
}

function idOrUndefined(value: string): number | undefined {
  const n = Number(value)
  return value !== ALL && Number.isFinite(n) && n > 0 ? n : undefined
}

function defaultValues(lockedSegmentId: number | null): FormValues {
  return {
    segment_id: lockedSegmentId ? String(lockedSegmentId) : ALL,
    issuer_id: ALL,
    service_id: ALL,
    anniversary_from: '',
    anniversary_to: '',
    direction: 'increase',
    percentage: '',
    reason: '',
  }
}

/**
 * Ajuste Masivo de Precios (RF-3.6) en dos pasos UI-9:
 * 1) filtros + porcentaje + motivo → vista previa del servidor (sin escrituras);
 * 2) KPIs + tabla de impacto → confirmación atómica (lote AJ-YYYY-XXX).
 */
export function BulkPriceAdjustmentModal({
  open,
  onOpenChange,
  segmentOptions,
  lockedSegmentId,
  issuerOptions,
}: Props) {
  const toast = useToast()
  const { services, preview, apply } = useBulkPriceAdjustment(open)
  const [step, setStep] = useState<'form' | 'preview'>('form')
  const [result, setResult] = useState<BulkPriceAdjustmentPreview | null>(null)
  const [submitted, setSubmitted] = useState<(BulkPriceAdjustmentInput & { reason: string }) | null>(null)

  const {
    control,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(bulkPriceAdjustmentSchema),
    defaultValues: defaultValues(lockedSegmentId),
  })

  function close() {
    reset(defaultValues(lockedSegmentId))
    setStep('form')
    setResult(null)
    setSubmitted(null)
    onOpenChange(false)
  }

  const serviceOptions = useMemo<AppSelectOption[]>(
    () => [
      { value: ALL, label: 'Todos los recurrentes' },
      ...services.map((s) => ({ value: String(s.id), label: `${s.code} · ${s.name}` })),
    ],
    [services],
  )

  const segmentSelectOptions = useMemo<AppSelectOption[]>(
    () => (lockedSegmentId ? segmentOptions : [{ value: ALL, label: 'Todos' }, ...segmentOptions]),
    [lockedSegmentId, segmentOptions],
  )

  const issuerSelectOptions = useMemo<AppSelectOption[]>(
    () => [{ value: ALL, label: 'Todos' }, ...issuerOptions],
    [issuerOptions],
  )

  async function onPreview(values: FormValues) {
    const input = {
      segment_id: lockedSegmentId ?? idOrUndefined(values.segment_id),
      issuer_id: idOrUndefined(values.issuer_id),
      service_id: idOrUndefined(values.service_id),
      anniversary_from: values.anniversary_from || undefined,
      anniversary_to: values.anniversary_to || undefined,
      percentage: values.direction === 'decrease' ? `-${values.percentage}` : values.percentage,
      reason: values.reason.trim(),
    }
    try {
      const { reason: _reason, ...previewInput } = input
      const data = await preview.mutateAsync(previewInput)
      setSubmitted(input)
      setResult(data)
      setStep('preview')
      if (data.summary.affected_contracts_count === 0) {
        toast.info('Sin contratos afectados', 'Ningún contrato activo con líneas recurrentes cumple los filtros.')
      }
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onApply() {
    if (!submitted) return
    try {
      const outcome = await apply.mutateAsync(submitted)
      toast.success(
        `Ajuste ${outcome.batch_code} aplicado`,
        `${outcome.summary.affected_contracts_count} contrato(s) · impacto neto ${signedMoney(outcome.summary.total_mrr_delta_mxn)} MRR`,
      )
      close()
    } catch (error) {
      toast.apiError(error)
    }
  }

  const summary = result?.summary
  const isBusy = preview.isPending || apply.isPending

  return (
    <AppDrawer
      open={open}
      onClose={() => {
        if (!isBusy) close()
      }}
      size="xl"
      title="Ajuste masivo de precios"
      description={
        step === 'form'
          ? 'Paso 1 de 2 · Define el alcance y el porcentaje. Solo se ajustan líneas recurrentes activas de contratos activos.'
          : 'Paso 2 de 2 · Revisa el impacto antes de aplicar. Los cupones de las líneas ajustadas se consumen.'
      }
      footer={
        step === 'form' ? (
          <>
            <AppButton type="button" variant="outline" onClick={close} disabled={isBusy}>
              Cancelar
            </AppButton>
            <AppButton
              type="submit"
              form="bulk-price-adjustment-form"
              isLoading={preview.isPending}
            >
              {!preview.isPending ? <Calculator className="h-4 w-4" /> : null}
              Calcular vista previa (UI-9)
            </AppButton>
          </>
        ) : (
          <>
            <AppButton type="button" variant="outline" onClick={() => setStep('form')} disabled={isBusy}>
              <ArrowLeft className="h-4 w-4" />
              Volver a editar
            </AppButton>
            <AppButton
              type="button"
              onClick={() => void onApply()}
              isLoading={apply.isPending}
              disabled={isBusy || !summary || summary.affected_contracts_count === 0}
            >
              {!apply.isPending ? <CheckCircle2 className="h-4 w-4" /> : null}
              Confirmar y aplicar ajuste masivo
            </AppButton>
          </>
        )
      }
    >
      {step === 'form' ? (
        <form
          id="bulk-price-adjustment-form"
          className="min-w-0 space-y-4"
          onSubmit={handleSubmit(onPreview)}
        >
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div className="min-w-0">
              <span className={FIELD_LABEL}>Segmento</span>
              <Controller
                control={control}
                name="segment_id"
                render={({ field }) => (
                  <AppSelect
                    value={field.value}
                    onValueChange={field.onChange}
                    options={segmentSelectOptions}
                    disabled={lockedSegmentId != null}
                    aria-label="Segmento"
                  />
                )}
              />
            </div>
            <div className="min-w-0">
              <span className={FIELD_LABEL}>Emisor</span>
              <Controller
                control={control}
                name="issuer_id"
                render={({ field }) => (
                  <AppSelect
                    value={field.value}
                    onValueChange={field.onChange}
                    options={issuerSelectOptions}
                    aria-label="Emisor"
                  />
                )}
              />
            </div>
            <div className="min-w-0">
              <span className={FIELD_LABEL}>Servicio recurrente</span>
              <Controller
                control={control}
                name="service_id"
                render={({ field }) => (
                  <AppSelect
                    value={field.value}
                    onValueChange={field.onChange}
                    options={serviceOptions}
                    aria-label="Servicio recurrente"
                  />
                )}
              />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="min-w-0">
              <span className={FIELD_LABEL}>Próximo aniversario desde</span>
              <Controller
                control={control}
                name="anniversary_from"
                render={({ field }) => <AppInput type="date" {...field} />}
              />
            </div>
            <div className="min-w-0">
              <span className={FIELD_LABEL}>Próximo aniversario hasta</span>
              <Controller
                control={control}
                name="anniversary_to"
                render={({ field }) => <AppInput type="date" {...field} error={Boolean(errors.anniversary_to)} />}
              />
              {errors.anniversary_to ? (
                <p className="mt-1 text-xs text-red-500">{errors.anniversary_to.message}</p>
              ) : null}
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="min-w-0">
              <span className={FIELD_LABEL}>Tipo de ajuste</span>
              <Controller
                control={control}
                name="direction"
                render={({ field }) => (
                  <AppSelect
                    value={field.value}
                    onValueChange={field.onChange}
                    options={DIRECTION_OPTIONS}
                    aria-label="Tipo de ajuste"
                  />
                )}
              />
            </div>
            <div className="min-w-0">
              <span className={FIELD_LABEL}>Porcentaje</span>
              <div className="relative">
                <Controller
                  control={control}
                  name="percentage"
                  render={({ field }) => (
                    <AppInput
                      {...field}
                      numericMode="decimal"
                      placeholder="4.50"
                      className="pr-9 text-right font-mono tabular-nums"
                      error={Boolean(errors.percentage)}
                    />
                  )}
                />
                <Percent className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              </div>
              {errors.percentage ? (
                <p className="mt-1 text-xs text-red-500">{errors.percentage.message}</p>
              ) : null}
            </div>
          </div>

          <div className="min-w-0">
            <span className={FIELD_LABEL}>Motivo</span>
            <Controller
              control={control}
              name="reason"
              render={({ field }) => (
                <AppTextarea
                  {...field}
                  rows={2}
                  placeholder="Ej. Actualización anual por inflación 2027"
                  error={Boolean(errors.reason)}
                />
              )}
            />
            {errors.reason ? <p className="mt-1 text-xs text-red-500">{errors.reason.message}</p> : null}
          </div>
        </form>
      ) : summary && result ? (
        <div className="min-w-0 space-y-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <AppCard className="p-4">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-400">
                Contratos afectados
              </p>
              <p className="mt-1 font-heading text-2xl font-semibold tabular-nums tracking-[-0.4px]">
                {summary.affected_contracts_count.toLocaleString('es-MX')}
              </p>
              <p className="text-xs text-slate-500 dark:text-zinc-400">
                {summary.affected_lines_count.toLocaleString('es-MX')} línea(s) recurrente(s)
              </p>
            </AppCard>
            <AppCard className="p-4">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-400">
                MRR actual → Nuevo MRR
              </p>
              <p className="mt-1 font-mono text-sm tabular-nums text-slate-500 dark:text-zinc-400">
                {money(summary.current_total_mrr_mxn)}
              </p>
              <p className="flex items-center gap-1 font-mono text-base font-semibold tabular-nums">
                <ArrowRight className="h-4 w-4 shrink-0 text-slate-400" />
                {money(summary.new_total_mrr_mxn)}
              </p>
            </AppCard>
            <AppCard className="p-4">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-400">
                Impacto neto
              </p>
              <p
                className={cn(
                  'mt-1 font-mono text-2xl font-semibold tabular-nums',
                  deltaClass(summary.total_mrr_delta_mxn),
                )}
              >
                {signedMoney(summary.total_mrr_delta_mxn)}
              </p>
              <AppBadge
                label={`${summary.percentage.startsWith('-') ? '' : '+'}${summary.percentage}%`}
                tone={summary.percentage.startsWith('-') ? 'danger' : 'success'}
              />
            </AppCard>
          </div>

          {result.items.length > 0 ? (
            <div className="overflow-x-auto rounded-2xl border border-slate-200/80 dark:border-white/[0.08]">
              <table className="hidden w-full text-sm md:table">
                <thead>
                  <AppTableHeadRow>
                    <th className="px-3 py-2 font-medium">FOLIO</th>
                    <th className="px-3 py-2 font-medium">CLIENTE</th>
                    <th className="px-3 py-2 font-medium">PRÓXIMO CORTE</th>
                    <th className="px-3 py-2 text-right font-medium">MRR ACTUAL</th>
                    <th className="px-3 py-2 text-right font-medium">NUEVO MRR</th>
                    <th className="px-3 py-2 text-right font-medium">Δ MRR</th>
                  </AppTableHeadRow>
                </thead>
                <tbody>
                  {result.items.map((item) => (
                    <tr key={item.contract_id} className="border-b border-slate-100 dark:border-white/[0.04]">
                      <td className="px-3 py-2 font-mono text-xs font-semibold tabular-nums">{item.folio}</td>
                      <td className="max-w-[200px] truncate px-3 py-2" title={item.client_name}>
                        {item.client_name}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-xs">
                        {item.next_billing_date ? formatDateEsMx(item.next_billing_date) : '—'}
                      </td>
                      <td className="px-3 py-2 text-right font-mono text-xs tabular-nums">{money(item.old_mrr_mxn)}</td>
                      <td className="px-3 py-2 text-right font-mono text-xs tabular-nums">{money(item.new_mrr_mxn)}</td>
                      <td
                        className={cn(
                          'px-3 py-2 text-right font-mono text-xs font-semibold tabular-nums',
                          deltaClass(item.mrr_delta_mxn),
                        )}
                      >
                        {signedMoney(item.mrr_delta_mxn)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="space-y-2 p-3 md:hidden">
                {result.items.map((item) => (
                  <div
                    key={item.contract_id}
                    className="rounded-xl border border-slate-200/80 p-3 text-sm dark:border-white/[0.08]"
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-mono text-xs font-semibold tabular-nums">{item.folio}</span>
                      <span className={cn('font-mono text-xs font-semibold tabular-nums', deltaClass(item.mrr_delta_mxn))}>
                        {signedMoney(item.mrr_delta_mxn)}
                      </span>
                    </div>
                    <p className="truncate">{item.client_name}</p>
                    <p className="font-mono text-xs tabular-nums text-slate-500">
                      {money(item.old_mrr_mxn)} → {money(item.new_mrr_mxn)}
                    </p>
                  </div>
                ))}
              </div>
            </div>
          ) : (
            <AppCard className="p-6 text-center text-sm text-slate-500">
              Ningún contrato activo con líneas recurrentes cumple los filtros.
            </AppCard>
          )}
        </div>
      ) : null}
    </AppDrawer>
  )
}
