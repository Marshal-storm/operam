import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
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
import { AppSelect } from '@/components/ui/AppSelect'
import { positiveIntSchema } from '@/lib/validationSchemas'
import type { GrantBalanceInput } from '@/types/balance'

const schema = z.object({
  service_id: z.string().min(1, 'Selecciona un servicio'),
  bucket: z.enum(['included', 'prepaid']),
  units: positiveIntSchema({ message: 'Ingresa un entero mayor a 0' }),
  period_start: z.string().optional().or(z.literal('')),
  period_end: z.string().optional().or(z.literal('')),
  notes: z.string().max(255).optional().or(z.literal('')),
})

type FormValues = z.infer<typeof schema>

type ServiceOption = { value: string; label: string }

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  contractId: number
  serviceOptions: ServiceOption[]
  isSubmitting?: boolean
  onSubmit: (input: GrantBalanceInput) => Promise<void>
}

/**
 * Modal de acreditación / otorgamiento de bolsa (Admin) — RF-4.
 */
export function GrantBalanceModal({
  open,
  onOpenChange,
  contractId,
  serviceOptions,
  isSubmitting = false,
  onSubmit,
}: Props) {
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      service_id: '',
      bucket: 'prepaid',
      units: '100',
      period_start: '',
      period_end: '',
      notes: '',
    },
  })

  const bucket = form.watch('bucket')

  useEffect(() => {
    if (open) {
      form.reset({
        service_id: serviceOptions[0]?.value ?? '',
        bucket: 'prepaid',
        units: '100',
        period_start: '',
        period_end: '',
        notes: '',
      })
    }
  }, [open, serviceOptions, form])

  async function handleSubmit(values: FormValues) {
    await onSubmit({
      contract_id: contractId,
      service_id: Number(values.service_id),
      bucket: values.bucket,
      units: Number(values.units),
      movement_type: 'grant',
      period_start: values.period_start?.trim() || undefined,
      period_end: values.period_end?.trim() || undefined,
      notes: values.notes?.trim() || undefined,
    })
    form.reset()
    onOpenChange(false)
  }

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="max-w-lg">
        <AppModalHeader>
          <AppModalTitle>Acreditar bolsa</AppModalTitle>
          <AppModalDescription>
            Otorga unidades incluidas del periodo o prepago sin caducidad.
          </AppModalDescription>
        </AppModalHeader>
        <form className="space-y-4" onSubmit={form.handleSubmit((v) => void handleSubmit(v))}>
          <Controller
            control={form.control}
            name="service_id"
            render={({ field }) => (
              <div>
                <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  Servicio
                </label>
                <AppSelect
                  value={field.value}
                  onValueChange={field.onChange}
                  options={serviceOptions}
                  placeholder="Selecciona servicio"
                />
              </div>
            )}
          />
          <Controller
            control={form.control}
            name="bucket"
            render={({ field }) => (
              <div>
                <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  Bolsa
                </label>
                <AppSelect
                  value={field.value}
                  onValueChange={field.onChange}
                  options={[
                    { value: 'included', label: 'Incluida (periodo)' },
                    { value: 'prepaid', label: 'Prepagada (sin caducidad)' },
                  ]}
                />
              </div>
            )}
          />
          <div>
            <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
              Unidades
            </label>
            <AppInput numericMode="integer" mono {...form.register('units')} />
            {form.formState.errors.units ? (
              <p className="mt-1 text-xs text-red-500">{form.formState.errors.units.message}</p>
            ) : null}
          </div>
          {bucket === 'included' ? (
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  Inicio de periodo
                </label>
                <AppInput type="date" className="rounded-full" {...form.register('period_start')} />
              </div>
              <div>
                <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  Reinicio / fin
                </label>
                <AppInput type="date" className="rounded-full" {...form.register('period_end')} />
              </div>
            </div>
          ) : null}
          <div>
            <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
              Notas
            </label>
            <AppTextarea rows={3} {...form.register('notes')} />
          </div>
          <AppModalFooter>
            <AppButton type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancelar
            </AppButton>
            <AppButton type="submit" isLoading={isSubmitting}>
              Acreditar
            </AppButton>
          </AppModalFooter>
        </form>
      </AppModalContent>
    </AppModal>
  )
}
