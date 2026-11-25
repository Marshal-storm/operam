import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import { AppButton } from '@/components/ui/AppButton'
import { AppCurrencyInput, AppInput, AppTextarea } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalFooter,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppSelect } from '@/components/ui/AppSelect'
import { formatCurrencyMx } from '@/lib/utils'
import { moneySchema } from '@/lib/validationSchemas'
import type { InvoiceListItem, RecordPaymentInput } from '@/types/collection'

const FIELD_LABEL =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400'

const formSchema = z.object({
  amount: moneySchema(),
  payment_method: z.enum(['transfer', 'card_integropay', 'cash', 'check', 'other']),
  payment_date: z.string().min(1, 'Indica la fecha'),
  reference: z.string().max(120).optional(),
  notes: z.string().max(2000).optional(),
})

type FormValues = z.infer<typeof formSchema>

type RecordPaymentModalProps = {
  open: boolean
  invoice: InvoiceListItem | null
  onOpenChange: (open: boolean) => void
  onSubmit: (data: RecordPaymentInput) => Promise<void>
  isSubmitting?: boolean
}

function todayYmd(): string {
  const n = new Date()
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`
}

function moneySub(a: string, b: string): string {
  const cents = Math.round(Number(a) * 100) - Math.round(Number(b) * 100)
  return (cents / 100).toFixed(2)
}

/**
 * Modal UI-9: Saldo actual → Monto a aplicar → Nuevo saldo.
 */
export function RecordPaymentModal({
  open,
  invoice,
  onOpenChange,
  onSubmit,
  isSubmitting = false,
}: RecordPaymentModalProps) {
  const {
    control,
    handleSubmit,
    reset,
    setValue,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      amount: '',
      payment_method: 'transfer',
      payment_date: todayYmd(),
      reference: '',
      notes: '',
    },
  })

  const amount = useWatch({ control, name: 'amount' })
  const balance = invoice?.balance ?? '0.00'
  const applied =
    amount && Number(amount) > 0 ? Number(amount).toFixed(2) : '0.00'
  const newBalance =
    Number(applied) > 0 && Number(applied) <= Number(balance)
      ? moneySub(balance, applied)
      : balance

  useEffect(() => {
    if (!open || !invoice) return
    reset({
      amount: '',
      payment_method: 'transfer',
      payment_date: todayYmd(),
      reference: '',
      notes: '',
    })
  }, [open, invoice, reset])

  if (!invoice) return null

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="max-w-md">
        <AppModalHeader>
          <AppModalTitle>Registrar pago</AppModalTitle>
          <AppModalDescription>
            {invoice.folio} · {invoice.client_name}
          </AppModalDescription>
        </AppModalHeader>

        <form
          className="space-y-4"
          onSubmit={handleSubmit(async (values) => {
            const n = Number(values.amount)
            if (!Number.isFinite(n) || n <= 0) return
            if (n > Number(balance)) return
            await onSubmit({
              amount: n.toFixed(2),
              payment_method: values.payment_method,
              payment_date: values.payment_date,
              reference: values.reference?.trim() || undefined,
              notes: values.notes?.trim() || undefined,
            })
          })}
        >
          <div className="grid grid-cols-3 gap-2 rounded-2xl border border-slate-200/80 bg-slate-50/80 p-3 text-center dark:border-white/[0.08] dark:bg-white/[0.03]">
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                Saldo actual
              </p>
              <p className="mt-1 font-mono text-sm tabular-nums">
                {formatCurrencyMx(Number(balance))}
              </p>
            </div>
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                A aplicar
              </p>
              <p className="mt-1 font-mono text-sm tabular-nums text-[#0066FF] dark:text-[#0A84FF]">
                {formatCurrencyMx(Number(applied))}
              </p>
            </div>
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                Nuevo saldo
              </p>
              <p className="mt-1 font-mono text-sm tabular-nums">
                {formatCurrencyMx(Number(newBalance))}
              </p>
            </div>
          </div>

          <div>
            <div className="mb-1.5 flex items-center justify-between gap-2">
              <span className={FIELD_LABEL + ' mb-0'}>Monto</span>
              <AppButton
                type="button"
                variant="ghost"
                className="h-7 px-2 text-xs"
                onClick={() => setValue('amount', balance)}
              >
                Liquidar saldo total
              </AppButton>
            </div>
            <Controller
              control={control}
              name="amount"
              render={({ field }) => <AppCurrencyInput {...field} placeholder="0.00" />}
            />
            {errors.amount ? (
              <p className="mt-1 text-xs text-red-500">{errors.amount.message}</p>
            ) : null}
            {amount && Number(amount) > Number(balance) ? (
              <p className="mt-1 text-xs text-red-500">
                El pago no puede exceder el saldo pendiente.
              </p>
            ) : null}
          </div>

          <div>
            <span className={FIELD_LABEL}>Método</span>
            <Controller
              control={control}
              name="payment_method"
              render={({ field }) => (
                <AppSelect
                  value={field.value}
                  onValueChange={field.onChange}
                  options={[
                    { value: 'transfer', label: 'Transferencia' },
                    { value: 'card_integropay', label: 'Tarjeta IntegroPAY' },
                    { value: 'cash', label: 'Efectivo' },
                    { value: 'check', label: 'Cheque' },
                    { value: 'other', label: 'Otro' },
                  ]}
                  aria-label="Método de pago"
                />
              )}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <span className={FIELD_LABEL}>Fecha</span>
              <Controller
                control={control}
                name="payment_date"
                render={({ field }) => <AppInput type="date" {...field} />}
              />
            </div>
            <div>
              <span className={FIELD_LABEL}>Referencia</span>
              <Controller
                control={control}
                name="reference"
                render={({ field }) => (
                  <AppInput {...field} placeholder="SPEI / autorización" />
                )}
              />
            </div>
          </div>

          <div>
            <span className={FIELD_LABEL}>Notas</span>
            <Controller
              control={control}
              name="notes"
              render={({ field }) => <AppTextarea {...field} rows={2} />}
            />
          </div>

          <AppModalFooter>
            <AppButton
              type="button"
              variant="ghost"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
            >
              Cancelar
            </AppButton>
            <AppButton type="submit" variant="primary" isLoading={isSubmitting}>
              Registrar pago
            </AppButton>
          </AppModalFooter>
        </form>
      </AppModalContent>
    </AppModal>
  )
}
