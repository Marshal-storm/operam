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
import type { ConsumeBalanceInput } from '@/types/balance'

const schema = z.object({
  service_id: z.string().min(1, 'Selecciona un servicio'),
  units: positiveIntSchema({ message: 'Ingresa un entero mayor a 0' }),
  external_reference: z.string().max(120).optional().or(z.literal('')),
  notes: z.string().max(2000).optional().or(z.literal('')),
})

type FormValues = z.infer<typeof schema>

type ServiceOption = { value: string; label: string }

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  contractId: number
  serviceOptions: ServiceOption[]
  isSubmitting?: boolean
  onSubmit: (input: ConsumeBalanceInput) => Promise<void>
}

/**
 * Modal de registro manual de consumo (Soporte / Admin) — RF-4.5.
 */
export function RegisterConsumptionModal({
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
      units: '1',
      external_reference: '',
      notes: '',
    },
  })

  useEffect(() => {
    if (open) {
      form.reset({
        service_id: serviceOptions[0]?.value ?? '',
        units: '1',
        external_reference: '',
        notes: '',
      })
    }
  }, [open, serviceOptions, form])

  async function handleSubmit(values: FormValues) {
    await onSubmit({
      contract_id: contractId,
      service_id: Number(values.service_id),
      units: Number(values.units),
      external_reference: values.external_reference?.trim() || undefined,
      notes: values.notes?.trim() || undefined,
    })
    form.reset()
    onOpenChange(false)
  }

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="max-w-lg">
        <AppModalHeader>
          <AppModalTitle>Registrar consumo</AppModalTitle>
          <AppModalDescription>
            Descuenta unidades en orden incluido → prepago. El ticket/referencia evita dobles cargos.
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
                {form.formState.errors.service_id ? (
                  <p className="mt-1 text-xs text-red-500">
                    {form.formState.errors.service_id.message}
                  </p>
                ) : null}
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
          <div>
            <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
              Ticket / Referencia externa
            </label>
            <AppInput
              mono
              placeholder="UUID CFDI o folio de ticket (opcional)"
              {...form.register('external_reference')}
            />
          </div>
          <div>
            <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
              Notas / evidencia
            </label>
            <AppTextarea rows={3} {...form.register('notes')} />
          </div>
          <AppModalFooter>
            <AppButton type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancelar
            </AppButton>
            <AppButton type="submit" isLoading={isSubmitting}>
              Registrar consumo
            </AppButton>
          </AppModalFooter>
        </form>
      </AppModalContent>
    </AppModal>
  )
}
