import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
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
import { fetchContractsPaginated } from '@/services/contractsService'
import type { IssueInvoiceInput } from '@/types/collection'

const FIELD_LABEL =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400'

const formSchema = z
  .object({
    contract_id: z.string().min(1, 'Selecciona un contrato activo'),
    mode: z.enum(['recurring_cutoff', 'custom']),
    concept: z.string().max(255).optional(),
    amount: z.string().optional(),
    issue_date: z.string().min(1, 'Indica la fecha de emisión'),
    due_date: z.string().min(1, 'Indica la fecha de vencimiento'),
    notes: z.string().max(2000).optional(),
  })
  .superRefine((values, ctx) => {
    if (values.mode === 'custom') {
      if (!values.concept?.trim()) {
        ctx.addIssue({ code: 'custom', message: 'El concepto es obligatorio', path: ['concept'] })
      }
      const n = Number(values.amount ?? '0')
      if (!Number.isFinite(n) || n <= 0) {
        ctx.addIssue({
          code: 'custom',
          message: 'El monto debe ser mayor a 0',
          path: ['amount'],
        })
      }
    }
  })

type FormValues = z.infer<typeof formSchema>

type NewInvoiceModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (data: IssueInvoiceInput) => Promise<void>
  isSubmitting?: boolean
}

function addDays(ymd: string, days: number): string {
  const dt = new Date(`${ymd}T12:00:00`)
  if (Number.isNaN(dt.getTime())) return ymd
  dt.setDate(dt.getDate() + Math.max(0, days))
  const y = dt.getFullYear()
  const m = String(dt.getMonth() + 1).padStart(2, '0')
  const d = String(dt.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function todayYmd(): string {
  const n = new Date()
  const y = n.getFullYear()
  const m = String(n.getMonth() + 1).padStart(2, '0')
  const d = String(n.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/**
 * Modal para emitir FAC-XXXX (corte MRR o cargo personalizado).
 */
export function NewInvoiceModal({
  open,
  onOpenChange,
  onSubmit,
  isSubmitting = false,
}: NewInvoiceModalProps) {
  const contractsQuery = useQuery({
    queryKey: ['contracts', 'collections-picker', { status: 'active' }],
    queryFn: () =>
      fetchContractsPaginated({ page: 1, per_page: 100, status: 'active' }),
    enabled: open,
    staleTime: 30_000,
  })

  const contracts = contractsQuery.data?.items ?? []
  const contractOptions = useMemo(
    () =>
      contracts.map((c) => ({
        value: String(c.id),
        label: `${c.folio} · ${c.client_name}`,
      })),
    [contracts],
  )

  const {
    control,
    handleSubmit,
    reset,
    setValue,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      contract_id: '',
      mode: 'recurring_cutoff',
      concept: '',
      amount: '',
      issue_date: todayYmd(),
      due_date: todayYmd(),
      notes: '',
    },
  })

  const contractId = useWatch({ control, name: 'contract_id' })
  const mode = useWatch({ control, name: 'mode' })
  const issueDate = useWatch({ control, name: 'issue_date' })
  const amount = useWatch({ control, name: 'amount' })

  const selected = contracts.find((c) => String(c.id) === contractId)

  useEffect(() => {
    if (!open) return
    reset({
      contract_id: '',
      mode: 'recurring_cutoff',
      concept: '',
      amount: '',
      issue_date: todayYmd(),
      due_date: todayYmd(),
      notes: '',
    })
  }, [open, reset])

  useEffect(() => {
    if (!selected || !issueDate) return
    setValue('due_date', addDays(issueDate, selected.credit_days ?? 0))
  }, [selected, issueDate, setValue])

  const previewTotal =
    mode === 'recurring_cutoff'
      ? selected?.mrr_mxn ?? '0.00'
      : (amount && Number(amount) > 0 ? Number(amount).toFixed(2) : '0.00')

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="max-w-lg">
        <AppModalHeader>
          <AppModalTitle>Emitir documento</AppModalTitle>
          <AppModalDescription>
            Genera un FAC-XXXX para un contrato activo. La fecha de vencimiento usa los días de
            crédito del contrato (editable para pruebas de morosidad).
          </AppModalDescription>
        </AppModalHeader>

        <form
          className="space-y-4"
          onSubmit={handleSubmit(async (values) => {
            await onSubmit({
              contract_id: Number(values.contract_id),
              mode: values.mode,
              concept: values.concept?.trim() || undefined,
              amount: values.mode === 'custom' ? Number(values.amount).toFixed(2) : undefined,
              invoice_type: values.mode === 'custom' ? 'one_time' : 'recurring',
              issue_date: values.issue_date,
              due_date: values.due_date,
              notes: values.notes?.trim() || undefined,
            })
          })}
        >
          <div>
            <span className={FIELD_LABEL}>Contrato</span>
            <Controller
              control={control}
              name="contract_id"
              render={({ field }) => (
                <AppSelect
                  value={field.value}
                  onValueChange={field.onChange}
                  options={contractOptions}
                  placeholder="Selecciona contrato activo"
                  aria-label="Contrato"
                />
              )}
            />
            {errors.contract_id ? (
              <p className="mt-1 text-xs text-red-500">{errors.contract_id.message}</p>
            ) : null}
          </div>

          <div>
            <span className={FIELD_LABEL}>Tipo de cargo</span>
            <Controller
              control={control}
              name="mode"
              render={({ field }) => (
                <AppSelect
                  value={field.value}
                  onValueChange={field.onChange}
                  options={[
                    {
                      value: 'recurring_cutoff',
                      label: 'Corte del contrato (MRR actual)',
                    },
                    { value: 'custom', label: 'Cargo personalizado' },
                  ]}
                  aria-label="Tipo de cargo"
                />
              )}
            />
          </div>

          {mode === 'custom' ? (
            <>
              <div>
                <span className={FIELD_LABEL}>Concepto</span>
                <Controller
                  control={control}
                  name="concept"
                  render={({ field }) => (
                    <AppInput {...field} placeholder="Ej. Cargo especial · capacitación" />
                  )}
                />
                {errors.concept ? (
                  <p className="mt-1 text-xs text-red-500">{errors.concept.message}</p>
                ) : null}
              </div>
              <div>
                <span className={FIELD_LABEL}>Monto</span>
                <Controller
                  control={control}
                  name="amount"
                  render={({ field }) => (
                    <AppCurrencyInput {...field} placeholder="0.00" />
                  )}
                />
                {errors.amount ? (
                  <p className="mt-1 text-xs text-red-500">{errors.amount.message}</p>
                ) : null}
              </div>
            </>
          ) : null}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <span className={FIELD_LABEL}>Emisión</span>
              <Controller
                control={control}
                name="issue_date"
                render={({ field }) => <AppInput type="date" {...field} />}
              />
            </div>
            <div>
              <span className={FIELD_LABEL}>
                Vencimiento
                {selected ? ` (+${selected.credit_days}d crédito)` : ''}
              </span>
              <Controller
                control={control}
                name="due_date"
                render={({ field }) => <AppInput type="date" {...field} />}
              />
            </div>
          </div>

          <div>
            <span className={FIELD_LABEL}>Notas (opcional)</span>
            <Controller
              control={control}
              name="notes"
              render={({ field }) => (
                <AppTextarea {...field} rows={2} placeholder="Observaciones internas" />
              )}
            />
          </div>

          <div className="rounded-2xl border border-slate-200/80 bg-slate-50/80 px-4 py-3 dark:border-white/[0.08] dark:bg-white/[0.03]">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">
              Vista previa del total
            </p>
            <p className="mt-1 font-mono text-xl tabular-nums text-[#090D16] dark:text-white">
              {formatCurrencyMx(Number(previewTotal))}
            </p>
            {selected ? (
              <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">
                {selected.folio} · {selected.client_name}
              </p>
            ) : null}
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
              Emitir documento
            </AppButton>
          </AppModalFooter>
        </form>
      </AppModalContent>
    </AppModal>
  )
}
