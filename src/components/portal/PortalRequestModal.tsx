import { zodResolver } from '@hookform/resolvers/zod'
import { AlertTriangle, Send } from 'lucide-react'
import { useEffect, useMemo } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { PORTAL_SHEET_CLASS } from '@/components/portal/portalTokens'
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
import { AppSelect, type AppSelectOption } from '@/components/ui/AppSelect'
import { useToast } from '@/context/ToastContext'
import { useCreatePortalRequest, usePortalStore } from '@/hooks/usePortalData'
import { PORTAL_REQUEST_TYPE_LABEL } from '@/lib/portalLabels'
import {
  PORTAL_PLAN_CHANGE_TYPES,
  portalRequestSchema,
  type PortalRequestValues,
} from '@/lib/validationSchemas'
import type { PortalContract, PortalRequestType } from '@/types/portal'

/** Mensaje oficial del backend (RF-8.1) para cambios de plan con adeudo exigible. */
export const PORTAL_NOT_CURRENT_MESSAGE =
  'Tu contrato tiene un saldo exigible pendiente. Ponte al corriente para solicitar cambios en tu plan.'

const FIELD_LABEL =
  'text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500 dark:text-zinc-400'

const TYPE_ORDER: PortalRequestType[] = [
  'add_users',
  'remove_users',
  'change_frequency',
  'cancel_service',
  'extra_service',
]

const NOTES_PLACEHOLDER: Record<PortalRequestType, string> = {
  add_users: 'Opcional: ¿para qué área o personas son los usuarios?',
  remove_users: '¿Cuántos usuarios deseas dar de baja y desde cuándo?',
  change_frequency: '¿A qué frecuencia deseas cambiar (mensual, trimestral, anual)?',
  cancel_service: '¿Qué servicio deseas cancelar y por qué motivo?',
  extra_service: 'Opcional: detalles para tu ejecutivo de cuenta',
}

export function isPlanChangeType(type: PortalRequestType): boolean {
  return (PORTAL_PLAN_CHANGE_TYPES as readonly string[]).includes(type)
}

type PortalRequestModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Contratos activos del cliente. */
  contracts: PortalContract[]
  initialType?: PortalRequestType
}

/**
 * "Nueva solicitud" del portal (RF-8.1): contrato, tipo y notas. Con adeudo exigible los cambios
 * de plan quedan bloqueados (el backend también lo exige con 422); los servicios extra siguen
 * disponibles.
 */
export function PortalRequestModal({ open, onOpenChange, contracts, initialType }: PortalRequestModalProps) {
  const toast = useToast()
  const navigate = useNavigate()
  const createRequest = useCreatePortalRequest()

  const defaultContract = contracts.find((contract) => !contract.is_current) ?? contracts[0] ?? null
  const defaults = useMemo<PortalRequestValues>(
    () => ({
      contract_id: defaultContract ? String(defaultContract.id) : '',
      request_type: initialType ?? 'add_users',
      service_id: '',
      quantity: '',
      notes: '',
    }),
    [defaultContract, initialType],
  )

  const form = useForm<PortalRequestValues>({
    resolver: zodResolver(portalRequestSchema),
    defaultValues: defaults,
  })

  useEffect(() => {
    if (open) form.reset(defaults)
    // Solo al abrir: no reiniciar lo capturado si cambian las referencias de datos.
  }, [open])

  const contractId = useWatch({ control: form.control, name: 'contract_id' })
  const requestType = useWatch({ control: form.control, name: 'request_type' })
  const contract = contracts.find((entry) => String(entry.id) === contractId) ?? null
  const store = usePortalStore(contract?.id ?? null)

  const blocked = contract !== null && !contract.is_current && isPlanChangeType(requestType)
  const needsService = requestType === 'add_users' || requestType === 'extra_service'
  const showQuantity = needsService || requestType === 'remove_users'

  const serviceOptions = useMemo<AppSelectOption[]>(() => {
    if (requestType === 'add_users') {
      const seen = new Set<number>()
      return (contract?.lines ?? [])
        .filter((line) => line.service_type === 'recurring' && line.service_id !== null)
        .filter((line) => {
          if (seen.has(line.service_id as number)) return false
          seen.add(line.service_id as number)
          return true
        })
        .map((line) => ({ value: String(line.service_id), label: line.description }))
    }
    if (requestType === 'extra_service') {
      return (store.data?.items ?? []).map((item) => ({ value: String(item.id), label: item.name }))
    }
    return []
  }, [requestType, contract, store.data])

  async function onSubmit(values: PortalRequestValues) {
    try {
      const created = await createRequest.mutateAsync({
        contract_id: Number(values.contract_id),
        request_type: values.request_type,
        service_id: needsService ? Number(values.service_id) : undefined,
        quantity: values.quantity !== '' ? Number(values.quantity) : undefined,
        notes: values.notes.trim(),
      })
      toast.success('Solicitud enviada', `Folio ${created.folio}. Te avisaremos por correo cuando la revisemos.`)
      onOpenChange(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  const errors = form.formState.errors

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className={PORTAL_SHEET_CLASS}>
        <div aria-hidden className="mx-auto -mt-1 h-1.5 w-10 rounded-full bg-slate-300 sm:hidden dark:bg-white/20" />
        <AppModalHeader>
          <AppModalTitle>Nueva solicitud</AppModalTitle>
          <AppModalDescription>Tu ejecutivo de cuenta la revisa y te avisamos por correo.</AppModalDescription>
        </AppModalHeader>

        <form className="space-y-4" onSubmit={form.handleSubmit(onSubmit)} noValidate>
          {contracts.length > 1 ? (
            <div>
              <p className={FIELD_LABEL}>Contrato</p>
              <Controller
                control={form.control}
                name="contract_id"
                render={({ field }) => (
                  <AppSelect
                    aria-label="Contrato"
                    withLabelSpacing
                    value={field.value || undefined}
                    onValueChange={(value) => {
                      field.onChange(value)
                      form.setValue('service_id', '')
                    }}
                    triggerClassName="h-12 rounded-2xl"
                    error={Boolean(errors.contract_id)}
                    options={contracts.map((entry) => ({
                      value: String(entry.id),
                      label: entry.folio,
                      tone: entry.is_current ? 'success' : 'warning',
                    }))}
                  />
                )}
              />
            </div>
          ) : null}

          <div>
            <p className={FIELD_LABEL}>Tipo de solicitud</p>
            <Controller
              control={form.control}
              name="request_type"
              render={({ field }) => (
                <AppSelect
                  aria-label="Tipo de solicitud"
                  withLabelSpacing
                  value={field.value}
                  onValueChange={(value) => {
                    field.onChange(value)
                    form.setValue('service_id', '')
                    form.clearErrors()
                  }}
                  triggerClassName="h-12 rounded-2xl"
                  options={TYPE_ORDER.map((type) => ({
                    value: type,
                    label: PORTAL_REQUEST_TYPE_LABEL[type],
                    tone: isPlanChangeType(type) && contract && !contract.is_current ? 'warning' : undefined,
                  }))}
                />
              )}
            />
          </div>

          {blocked ? (
            <div role="alert" className="space-y-3 rounded-2xl bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-200">
              <p className="flex items-start gap-2">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                {PORTAL_NOT_CURRENT_MESSAGE}
              </p>
              <AppButton
                type="button"
                variant="outline"
                className="h-11 w-full rounded-full"
                onClick={() => {
                  onOpenChange(false)
                  navigate('/portal/pagos')
                }}
              >
                Ir a ponerme al corriente
              </AppButton>
            </div>
          ) : (
            <>
              {needsService ? (
                <div>
                  <p className={FIELD_LABEL}>Servicio</p>
                  <Controller
                    control={form.control}
                    name="service_id"
                    render={({ field }) => (
                      <AppSelect
                        aria-label="Servicio"
                        withLabelSpacing
                        value={field.value || undefined}
                        onValueChange={field.onChange}
                        placeholder={serviceOptions.length === 0 ? 'Sin servicios disponibles' : 'Selecciona un servicio'}
                        disabled={serviceOptions.length === 0}
                        triggerClassName="h-12 rounded-2xl"
                        error={Boolean(errors.service_id)}
                        options={serviceOptions}
                      />
                    )}
                  />
                  {errors.service_id ? <p className="mt-1.5 text-xs text-red-500">{errors.service_id.message}</p> : null}
                </div>
              ) : null}

              {showQuantity ? (
                <div>
                  <label htmlFor="portal-request-quantity" className={FIELD_LABEL}>
                    {requestType === 'remove_users' ? 'Usuarios a dar de baja (opcional)' : 'Cantidad'}
                  </label>
                  <AppInput
                    id="portal-request-quantity"
                    withLabelSpacing
                    mono
                    numericMode="integer"
                    autoComplete="off"
                    placeholder="0"
                    error={Boolean(errors.quantity)}
                    className="h-12 rounded-2xl"
                    {...form.register('quantity')}
                  />
                  {errors.quantity ? <p className="mt-1.5 text-xs text-red-500">{errors.quantity.message}</p> : null}
                </div>
              ) : null}

              <div>
                <label htmlFor="portal-request-notes" className={FIELD_LABEL}>
                  Notas
                </label>
                <AppTextarea
                  id="portal-request-notes"
                  withLabelSpacing
                  maxLength={2000}
                  placeholder={NOTES_PLACEHOLDER[requestType]}
                  error={Boolean(errors.notes)}
                  className="rounded-2xl"
                  {...form.register('notes')}
                />
                {errors.notes ? <p className="mt-1.5 text-xs text-red-500">{errors.notes.message}</p> : null}
              </div>
            </>
          )}

          <AppModalFooter>
            <AppButton type="button" variant="ghost" className="h-11 rounded-full" onClick={() => onOpenChange(false)}>
              Cancelar
            </AppButton>
            <AppButton
              type="submit"
              className="h-11 rounded-full px-6"
              disabled={blocked || contract === null}
              isLoading={createRequest.isPending}
            >
              <Send className="h-4 w-4" aria-hidden /> Enviar solicitud
            </AppButton>
          </AppModalFooter>
        </form>
      </AppModalContent>
    </AppModal>
  )
}
