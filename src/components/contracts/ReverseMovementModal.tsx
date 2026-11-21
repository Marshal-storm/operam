import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { MrrImpactPreview } from '@/components/contracts/MrrImpactPreview'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppInput, AppTextarea } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalFooter,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { APP_TIME_ZONE, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import type { ContractDetail, ContractMovement } from '@/types/contract'

function todayInMexicoCity(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: APP_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

/** Preview de centavos en cliente; el servidor (BCMath) es la fuente de verdad. */
function invertDelta(delta: string): string {
  const cents = Math.round(Number(delta) * 100)
  return ((-cents) / 100).toFixed(2)
}

function moneyAdd(a: string, b: string): string {
  return ((Math.round(Number(a) * 100) + Math.round(Number(b) * 100)) / 100).toFixed(2)
}

const CATEGORY_LABEL: Record<string, string> = {
  new: 'Alta',
  expansion: 'Expansión',
  contraction: 'Contracción',
  price_adjustment: 'Ajuste de precio',
  frequency_change: 'Cambio de frecuencia',
  transfer: 'Transferencia',
  churn: 'Churn',
  reactivation: 'Reactivación',
}

const schema = z.object({
  effective_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Fecha inválida'),
  reason: z.string().trim().min(5, 'El motivo debe tener al menos 5 caracteres'),
})

type FormValues = z.infer<typeof schema>

export type ReverseMovementModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  contract: ContractDetail
  movement: ContractMovement | null
  isSubmitting?: boolean
  onConfirm: (input: { reason: string; effective_date: string }) => Promise<void>
}

/**
 * Modal de confirmación de reversión contable (RNF-3) con preview UI-9.
 */
export function ReverseMovementModal({
  open,
  onOpenChange,
  contract,
  movement,
  isSubmitting = false,
  onConfirm,
}: ReverseMovementModalProps) {
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      effective_date: todayInMexicoCity(),
      reason: '',
    },
    values: open
      ? {
          effective_date: todayInMexicoCity(),
          reason: '',
        }
      : undefined,
  })

  const preview = useMemo(() => {
    if (!movement) {
      return { inverseDelta: '0.00', mrrAfter: contract.mrr_mxn }
    }
    const inverseDelta = invertDelta(movement.mrr_delta_mxn)
    return {
      inverseDelta,
      mrrAfter: moneyAdd(contract.mrr_mxn, inverseDelta),
    }
  }, [movement, contract.mrr_mxn])

  const handleSubmit = form.handleSubmit(async (values) => {
    await onConfirm({
      reason: values.reason.trim(),
      effective_date: values.effective_date,
    })
    form.reset({ effective_date: todayInMexicoCity(), reason: '' })
    onOpenChange(false)
  })

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="max-w-lg">
        <AppModalHeader>
          <AppModalTitle>Revertir movimiento</AppModalTitle>
          <AppModalDescription>
            Se agregará un asiento inverso inmutable. El registro original no se borra ni se
            modifica (RNF-3).
          </AppModalDescription>
        </AppModalHeader>

        {movement ? (
          <form className="space-y-4" onSubmit={(e) => void handleSubmit(e)}>
            <div className="rounded-2xl border border-slate-200/80 bg-slate-50/70 p-3 dark:border-white/[0.09] dark:bg-white/[0.03]">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">
                Movimiento original #{movement.id}
              </p>
              <div className="mt-2 flex flex-wrap items-center gap-2">
                <AppBadge
                  label={CATEGORY_LABEL[movement.mrr_category] ?? movement.mrr_category}
                  tone="info"
                />
                <span className="text-sm text-slate-600 dark:text-zinc-300">
                  {formatDateEsMx(movement.effective_date)}
                </span>
              </div>
              <p className="mt-2 font-mono text-sm tabular-nums">
                Delta original:{' '}
                <span
                  className={
                    Number(movement.mrr_delta_mxn) < 0
                      ? 'text-red-600 dark:text-red-400'
                      : 'text-emerald-600 dark:text-emerald-400'
                  }
                >
                  {Number(movement.mrr_delta_mxn) > 0 ? '+' : ''}
                  {formatCurrencyMx(movement.mrr_delta_mxn, { fractionDigits: 2 })}
                </span>
              </p>
              {movement.reason ? (
                <p className="mt-1 line-clamp-2 text-xs text-slate-500">{movement.reason}</p>
              ) : null}
            </div>

            <div>
              <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                Motivo de reversión
              </label>
              <Controller
                control={form.control}
                name="reason"
                render={({ field, fieldState }) => (
                  <AppTextarea
                    value={field.value}
                    onChange={field.onChange}
                    error={Boolean(fieldState.error)}
                    placeholder="Describe por qué se emite este asiento inverso…"
                    rows={3}
                  />
                )}
              />
              {form.formState.errors.reason ? (
                <p className="mt-1 text-xs text-red-500">{form.formState.errors.reason.message}</p>
              ) : null}
            </div>

            <div>
              <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                Fecha efectiva
              </label>
              <Controller
                control={form.control}
                name="effective_date"
                render={({ field, fieldState }) => (
                  <AppInput
                    type="date"
                    value={field.value}
                    onChange={field.onChange}
                    error={Boolean(fieldState.error)}
                  />
                )}
              />
              {form.formState.errors.effective_date ? (
                <p className="mt-1 text-xs text-red-500">
                  {form.formState.errors.effective_date.message}
                </p>
              ) : null}
            </div>

            <MrrImpactPreview
              mrrBefore={contract.mrr_mxn}
              mrrDelta={preview.inverseDelta}
              mrrAfter={preview.mrrAfter}
            />
            <p className="text-xs text-slate-500 dark:text-zinc-400">
              El delta de reversión es el signo inverso del asiento original. El MRR del contrato se
              actualizará al nuevo valor mostrado.
            </p>

            <AppModalFooter>
              <AppButton
                type="button"
                variant="ghost"
                disabled={isSubmitting}
                onClick={() => onOpenChange(false)}
              >
                Cancelar
              </AppButton>
              <AppButton type="submit" variant="danger" isLoading={isSubmitting}>
                Confirmar reversión
              </AppButton>
            </AppModalFooter>
          </form>
        ) : null}
      </AppModalContent>
    </AppModal>
  )
}
