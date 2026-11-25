import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
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
import { useSegments } from '@/hooks/useSegments'
import { moneySchema } from '@/lib/validationSchemas'
import {
  COST_CATEGORY_LABELS,
  type SegmentCost,
  type SegmentCostCategory,
  type SegmentCostWriteInput,
} from '@/types/profitability'

const FIELD_LABEL =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500'

const schema = z.object({
  segment_id: z.string().min(1, 'Selecciona un segmento'),
  month: z.string().regex(/^\d{4}-\d{2}$/, 'Mes inválido (YYYY-MM)'),
  category: z.enum([
    'servers',
    'support_payroll',
    'implementation_payroll',
    'pac_folios',
    'other',
  ]),
  concept: z.string().trim().min(2, 'Concepto obligatorio').max(160),
  amount: moneySchema(),
  notes: z.string().max(4000).optional(),
})

type FormValues = z.infer<typeof schema>

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  editing: SegmentCost | null
  defaultMonth: string
  defaultSegmentId: number | null
  isSaving: boolean
  onSubmit: (input: SegmentCostWriteInput) => Promise<void> | void
}

const CATEGORY_OPTIONS = (
  Object.entries(COST_CATEGORY_LABELS) as Array<[SegmentCostCategory, string]>
).map(([value, label]) => ({ value, label }))

/**
 * Modal RHF+Zod para capturar/editar costos mensuales por segmento.
 */
export function SegmentCostModal({
  open,
  onOpenChange,
  editing,
  defaultMonth,
  defaultSegmentId,
  isSaving,
  onSubmit,
}: Props) {
  const { segments, isSegmentLocked, lockedSegmentId } = useSegments()

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      segment_id: '',
      month: defaultMonth,
      category: 'other',
      concept: '',
      amount: '',
      notes: '',
    },
  })

  useEffect(() => {
    if (!open) return
    const lockedId =
      isSegmentLocked && lockedSegmentId != null ? String(lockedSegmentId) : ''
    form.reset(
      editing
        ? {
            segment_id: String(editing.segment_id),
            month: editing.month,
            category: (editing.category as SegmentCostCategory) || 'other',
            concept: editing.concept,
            amount: editing.amount,
            notes: editing.notes ?? '',
          }
        : {
            segment_id:
              lockedId ||
              (defaultSegmentId != null
                ? String(defaultSegmentId)
                : String(segments[0]?.id ?? '')),
            month: defaultMonth,
            category: 'other',
            concept: '',
            amount: '',
            notes: '',
          },
    )
  }, [
    open,
    editing,
    defaultMonth,
    defaultSegmentId,
    segments,
    isSegmentLocked,
    lockedSegmentId,
    form,
  ])

  const handleSubmit = form.handleSubmit(async (values) => {
    await onSubmit({
      segment_id: Number(values.segment_id),
      month: values.month,
      category: values.category,
      concept: values.concept.trim(),
      amount: values.amount,
      notes: values.notes?.trim() ? values.notes.trim() : null,
    })
  })

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent>
        <AppModalHeader>
          <AppModalTitle>
            {editing ? 'Editar costo mensual' : 'Registrar costo mensual'}
          </AppModalTitle>
          <AppModalDescription>
            Captura costos operativos del segmento (servidores, nómina, PAC, otros).
          </AppModalDescription>
        </AppModalHeader>

        <form className="space-y-3" onSubmit={handleSubmit}>
          <div>
            <span className={FIELD_LABEL}>Segmento</span>
            <Controller
              control={form.control}
              name="segment_id"
              render={({ field }) => (
                <AppSelect
                  value={field.value || undefined}
                  onValueChange={field.onChange}
                  disabled={isSegmentLocked}
                  placeholder="Selecciona segmento…"
                  options={segments.map((s) => ({
                    value: String(s.id),
                    label: s.name,
                  }))}
                  error={Boolean(form.formState.errors.segment_id)}
                  aria-label="Segmento"
                />
              )}
            />
            {form.formState.errors.segment_id && (
              <p className="mt-1 text-xs text-red-500">
                {form.formState.errors.segment_id.message}
              </p>
            )}
          </div>

          <div>
            <span className={FIELD_LABEL}>Mes</span>
            <AppInput
              type="month"
              withLabelSpacing
              error={Boolean(form.formState.errors.month)}
              {...form.register('month')}
            />
            {form.formState.errors.month && (
              <p className="mt-1 text-xs text-red-500">
                {form.formState.errors.month.message}
              </p>
            )}
          </div>

          <div>
            <span className={FIELD_LABEL}>Categoría</span>
            <Controller
              control={form.control}
              name="category"
              render={({ field }) => (
                <AppSelect
                  value={field.value}
                  onValueChange={field.onChange}
                  options={CATEGORY_OPTIONS}
                  aria-label="Categoría"
                />
              )}
            />
          </div>

          <div>
            <span className={FIELD_LABEL}>Concepto</span>
            <AppInput
              withLabelSpacing
              placeholder="Ej. Servidores GCP, Nómina soporte…"
              error={Boolean(form.formState.errors.concept)}
              {...form.register('concept')}
            />
            {form.formState.errors.concept && (
              <p className="mt-1 text-xs text-red-500">
                {form.formState.errors.concept.message}
              </p>
            )}
          </div>

          <div>
            <span className={FIELD_LABEL}>Monto (MXN)</span>
            <Controller
              control={form.control}
              name="amount"
              render={({ field }) => (
                <AppCurrencyInput
                  withLabelSpacing
                  placeholder="0.00"
                  value={field.value}
                  onChange={field.onChange}
                  error={Boolean(form.formState.errors.amount)}
                />
              )}
            />
            {form.formState.errors.amount && (
              <p className="mt-1 text-xs text-red-500">
                {form.formState.errors.amount.message}
              </p>
            )}
          </div>

          <div>
            <span className={FIELD_LABEL}>Notas (opcional)</span>
            <AppTextarea withLabelSpacing rows={3} {...form.register('notes')} />
          </div>

          <AppModalFooter>
            <AppButton
              type="button"
              variant="ghost"
              onClick={() => onOpenChange(false)}
              disabled={isSaving}
            >
              Cancelar
            </AppButton>
            <AppButton type="submit" isLoading={isSaving}>
              {editing ? 'Guardar cambios' : 'Registrar costo'}
            </AppButton>
          </AppModalFooter>
        </form>
      </AppModalContent>
    </AppModal>
  )
}
