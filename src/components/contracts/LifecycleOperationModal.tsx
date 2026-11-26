import { zodResolver } from '@hookform/resolvers/zod'
import { useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { MrrImpactPreview } from '@/components/contracts/MrrImpactPreview'
import { AppButton } from '@/components/ui/AppButton'
import { AppDrawer } from '@/components/ui/AppDrawer'
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
import { APP_TIME_ZONE } from '@/lib/utils'
import { moneySchema, positiveIntSchema } from '@/lib/validationSchemas'
import type {
  ContractDetail,
  ContractLifecycleInput,
  ContractLifecycleOperation,
  ContractLine,
} from '@/types/contract'

function todayInMexicoCity(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: APP_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

/** Aritmética de centavos (presentación / preview; el servidor es la fuente de verdad). */
function moneyAdd(a: string, b: string): string {
  return ((Math.round(Number(a) * 100) + Math.round(Number(b) * 100)) / 100).toFixed(2)
}
function moneySub(a: string, b: string): string {
  return ((Math.round(Number(a) * 100) - Math.round(Number(b) * 100)) / 100).toFixed(2)
}
function moneyMul(a: string, b: string): string {
  return ((Math.round(Number(a) * 100) * Math.round(Number(b) * 100)) / 10000).toFixed(2)
}
function moneyDiv12(a: string): string {
  return (Math.round(Number(a) * 100) / 12 / 100).toFixed(2)
}

const baseSchema = z.object({
  effective_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Fecha inválida'),
  reason: z.string().trim().min(3, 'El motivo debe tener al menos 3 caracteres'),
  contract_line_id: z.string().optional(),
  service_id: z.string().optional(),
  quantity: z
    .string()
    .optional()
    .superRefine((val, ctx) => {
      if (val == null || val.trim() === '') return
      const parsed = positiveIntSchema().safeParse(val)
      if (!parsed.success) {
        ctx.addIssue({
          code: 'custom',
          message: parsed.error.issues[0]?.message ?? 'Ingresa un entero mayor a 0',
        })
      }
    }),
  unit_price: z
    .string()
    .optional()
    .superRefine((val, ctx) => {
      if (val == null || val.trim() === '') return
      const parsed = moneySchema().safeParse(val)
      if (!parsed.success) {
        ctx.addIssue({
          code: 'custom',
          message:
            parsed.error.issues[0]?.message ??
            'Ingresa un monto numérico válido (ej. 1500.00)',
        })
      }
    }),
  deactivate: z.string().optional(),
  billing_frequency: z.string().optional(),
  issuer_id: z.string().optional(),
  expansion_mode: z.enum(['add_service', 'increase_qty']).optional(),
})

type FormValues = z.infer<typeof baseSchema>

export type LifecycleServiceOption = {
  value: string
  label: string
  service_type?: string
}

export type LifecycleOperationModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  operation: ContractLifecycleOperation | null
  contract: ContractDetail
  /** Solo recurrentes activos en la lista de precios (expansión). */
  recurringServiceOptions: LifecycleServiceOption[]
  /** Emisores activos distintos al actual. */
  issuerOptions: Array<{ value: string; label: string }>
  /** Línea preseleccionada (atajos de fila). */
  preselectedLineId?: number | null
  isSubmitting?: boolean
  onConfirm: (input: ContractLifecycleInput) => Promise<void>
}

const TITLES: Record<ContractLifecycleOperation, string> = {
  expansion: 'Expandir contrato',
  contraction: 'Contraer contrato',
  price_adjustment: 'Ajustar precio',
  frequency_change: 'Cambiar frecuencia',
  issuer_transfer: 'Transferir de emisor',
  churn: 'Cancelar contrato (Churn)',
  reactivation: 'Reactivar contrato',
}

const DESCRIPTIONS: Record<ContractLifecycleOperation, string> = {
  expansion: 'Agrega un servicio recurrente nuevo o aumenta la cantidad de una línea recurrente.',
  contraction: 'Reduce cantidad o da de baja una línea sin cancelar el contrato.',
  price_adjustment: 'Modifica el precio unitario congelado de una línea recurrente.',
  frequency_change: 'Alterna facturación Mensual ↔ Anual y recalcula el MRR.',
  issuer_transfer: 'Cambia el emisor facturador. Operación 100% neutral para el MRR.',
  churn: 'Cancela el contrato y lleva el MRR a $0.00.',
  reactivation: 'Reactiva el contrato y restaura el MRR de líneas recurrentes.',
}

/**
 * Modal unificado de operaciones de ciclo de vida con preview UI-9.
 */
export function LifecycleOperationModal({
  open,
  onOpenChange,
  operation,
  contract,
  recurringServiceOptions,
  issuerOptions,
  preselectedLineId = null,
  isSubmitting = false,
  onConfirm,
}: LifecycleOperationModalProps) {
  const activeRecurring = useMemo(
    () => contract.lines.filter((l) => l.active && l.service_type === 'recurring'),
    [contract.lines],
  )

  const form = useForm<FormValues>({
    resolver: zodResolver(baseSchema),
    values: {
      effective_date: todayInMexicoCity(),
      reason: '',
      contract_line_id: preselectedLineId ? String(preselectedLineId) : '',
      service_id: '',
      quantity: '1',
      unit_price: '',
      deactivate: 'false',
      billing_frequency:
        contract.billing_frequency === 'monthly' ? 'annual' : 'monthly',
      issuer_id: '',
      expansion_mode: preselectedLineId ? 'increase_qty' : 'add_service',
    },
  })

  const watched = form.watch()

  const preview = useMemo(() => {
    if (!operation) {
      return { before: contract.mrr_mxn, delta: '0.00', after: contract.mrr_mxn, neutral: false }
    }
    return computePreview(operation, contract, watched, activeRecurring)
  }, [operation, contract, watched, activeRecurring])

  const selectedLine = useMemo(
    () => findLine(activeRecurring, watched.contract_line_id),
    [activeRecurring, watched.contract_line_id],
  )

  /** Aviso de cupón quemado cuando la línea tocada aún tiene descuento del alta. */
  const showCouponBurnNotice = useMemo(() => {
    if (!operation) return false
    const opsWithLine: ContractLifecycleOperation[] = [
      'expansion',
      'contraction',
      'price_adjustment',
      'frequency_change',
    ]
    if (!opsWithLine.includes(operation)) return false
    if (operation === 'frequency_change') {
      return activeRecurring.some((l) => Number(l.discount_amount) > 0)
    }
    if (operation === 'expansion' && watched.expansion_mode === 'add_service') {
      return false
    }
    return selectedLine != null && Number(selectedLine.discount_amount) > 0
  }, [operation, activeRecurring, selectedLine, watched.expansion_mode])

  const contractionHint = useMemo(() => {
    if (operation !== 'contraction' || !selectedLine || watched.deactivate === 'true') {
      return null
    }
    const current = selectedLine.quantity
    const next = Math.max(0, Number(watched.quantity ?? 0))
    if (!Number.isFinite(next) || next >= current) {
      return `Cantidad actual: ${current}`
    }
    const reduced = current - next
    return `Cantidad actual: ${current} · Quedarán ${next} unidades · se reducen ${reduced}`
  }, [operation, selectedLine, watched.deactivate, watched.quantity])

  if (!operation) return null

  const lineOptions = activeRecurring.map((l) => ({
    value: String(l.id),
    label: `${l.description} (×${l.quantity})`,
  }))

  async function handleSubmit(values: FormValues) {
    if (!operation) return
    const input = buildPayload(operation, values)
    await onConfirm(input)
    form.reset()
    onOpenChange(false)
  }

  const isDanger = operation === 'churn'
  const formId = 'lifecycle-operation-form'

  const formFields = (
    <form id={formId} className="min-w-0 space-y-4" onSubmit={form.handleSubmit(handleSubmit)}>
      {operation === 'expansion' ? (
        <>
          <Controller
            control={form.control}
            name="expansion_mode"
            render={({ field }) => (
              <div className="min-w-0">
                <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  Modo
                </label>
                <AppSelect
                  value={field.value ?? 'add_service'}
                  onValueChange={field.onChange}
                  options={[
                    { value: 'add_service', label: 'Agregar servicio nuevo' },
                    { value: 'increase_qty', label: 'Aumentar cantidad de línea' },
                  ]}
                />
              </div>
            )}
          />
          {watched.expansion_mode === 'add_service' ? (
            <>
              <FieldSelect
                label="Servicio recurrente"
                name="service_id"
                control={form.control}
                options={recurringServiceOptions}
                placeholder="Selecciona un servicio recurrente"
              />
              {recurringServiceOptions.length === 0 ? (
                <p className="text-xs text-slate-500">
                  No hay servicios recurrentes activos en la lista de precios del contrato.
                </p>
              ) : null}
              <p className="text-xs leading-relaxed text-slate-500 dark:text-zinc-400">
                Los cargos únicos o por consumo (como horas de desarrollo o timbres extra) se
                gestionan desde el módulo de Solicitudes.
              </p>
              <FieldInput
                label="Cantidad"
                name="quantity"
                control={form.control}
                numericMode="integer"
                mono
              />
            </>
          ) : (
            <>
              <FieldSelect
                label="Línea"
                name="contract_line_id"
                control={form.control}
                options={lineOptions}
                placeholder="Selecciona una línea"
              />
              <FieldInput
                label="Nueva cantidad"
                name="quantity"
                control={form.control}
                numericMode="integer"
                mono
              />
            </>
          )}
        </>
      ) : null}

      {operation === 'contraction' ? (
        <>
          <FieldSelect
            label="Línea"
            name="contract_line_id"
            control={form.control}
            options={lineOptions}
            placeholder="Selecciona una línea"
          />
          {selectedLine ? (
            <p className="text-sm font-medium text-slate-700 dark:text-zinc-200">
              Cantidad actual:{' '}
              <span className="font-mono tabular-nums">{selectedLine.quantity}</span>
            </p>
          ) : null}
          <Controller
            control={form.control}
            name="deactivate"
            render={({ field }) => (
              <div className="min-w-0">
                <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  Acción
                </label>
                <AppSelect
                  value={field.value ?? 'false'}
                  onValueChange={field.onChange}
                  options={[
                    { value: 'false', label: 'Reducir cantidad' },
                    { value: 'true', label: 'Dar de baja la línea' },
                  ]}
                />
              </div>
            )}
          />
          {watched.deactivate !== 'true' ? (
            <div className="min-w-0">
              <FieldInput
                label="Nueva cantidad (unidades que quedarán)"
                name="quantity"
                control={form.control}
                numericMode="integer"
                mono
              />
              {contractionHint ? (
                <p className="mt-1.5 text-xs text-slate-500 dark:text-zinc-400">
                  {contractionHint}
                </p>
              ) : null}
            </div>
          ) : null}
        </>
      ) : null}

      {operation === 'price_adjustment' ? (
        <>
          <FieldSelect
            label="Línea"
            name="contract_line_id"
            control={form.control}
            options={lineOptions}
            placeholder="Selecciona una línea"
          />
          <FieldInput
            label="Nuevo precio unitario"
            name="unit_price"
            control={form.control}
            numericMode="decimal"
            mono
            placeholder="0.00"
          />
        </>
      ) : null}

      {operation === 'frequency_change' ? (
        <Controller
          control={form.control}
          name="billing_frequency"
          render={({ field }) => (
            <div className="min-w-0">
              <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                Nueva frecuencia
              </label>
              <AppSelect
                value={field.value ?? 'monthly'}
                onValueChange={field.onChange}
                options={[
                  { value: 'monthly', label: 'Mensual' },
                  { value: 'annual', label: 'Anual' },
                ]}
              />
            </div>
          )}
        />
      ) : null}

      {operation === 'issuer_transfer' ? (
        <FieldSelect
          label="Emisor destino"
          name="issuer_id"
          control={form.control}
          options={issuerOptions}
          placeholder="Selecciona emisor"
        />
      ) : null}

      <div className="min-w-0">
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

      <div className="min-w-0">
        <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
          Motivo
        </label>
        <Controller
          control={form.control}
          name="reason"
          render={({ field, fieldState }) => (
            <AppTextarea
              value={field.value}
              onChange={field.onChange}
              error={Boolean(fieldState.error)}
              placeholder="Describe el motivo de la operación…"
            />
          )}
        />
        {form.formState.errors.reason ? (
          <p className="mt-1 text-xs text-red-500">{form.formState.errors.reason.message}</p>
        ) : null}
      </div>

      {showCouponBurnNotice ? (
        <div
          className={
            'rounded-xl border px-3 py-2.5 text-xs leading-relaxed ' +
            'border-amber-400/40 bg-amber-50/80 text-amber-800 ' +
            'dark:border-amber-400/25 dark:bg-amber-500/10 dark:text-amber-200'
          }
        >
          El cupón promocional solo aplicó en el alta inicial. Al modificar esta línea se
          recalculará a precio neto sin cupón.
        </div>
      ) : null}

      <MrrImpactPreview
        mrrBefore={preview.before}
        mrrDelta={preview.delta}
        mrrAfter={preview.after}
        neutral={preview.neutral}
      />
    </form>
  )

  // Confirmación crítica de cancelación (Churn) permanece en AppModal (z-[70])
  if (isDanger) {
    return (
      <AppModal open={open} onOpenChange={onOpenChange}>
        <AppModalContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
          <AppModalHeader>
            <AppModalTitle>{TITLES[operation]}</AppModalTitle>
            <AppModalDescription>{DESCRIPTIONS[operation]}</AppModalDescription>
          </AppModalHeader>

          {formFields}

          <AppModalFooter>
            <AppButton
              type="button"
              variant="ghost"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
            >
              Cancelar
            </AppButton>
            <AppButton type="submit" form={formId} variant="danger" isLoading={isSubmitting}>
              Confirmar cancelación
            </AppButton>
          </AppModalFooter>
        </AppModalContent>
      </AppModal>
    )
  }

  return (
    <AppDrawer
      open={open}
      onClose={() => onOpenChange(false)}
      size="lg"
      title={TITLES[operation]}
      description={DESCRIPTIONS[operation]}
      footer={
        <>
          <AppButton
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isSubmitting}
          >
            Cancelar
          </AppButton>
          <AppButton type="submit" form={formId} variant="primary" isLoading={isSubmitting}>
            Confirmar
          </AppButton>
        </>
      }
    >
      {formFields}
    </AppDrawer>
  )
}

function FieldInput({
  label,
  name,
  control,
  mono,
  placeholder,
  numericMode,
}: {
  label: string
  name: keyof FormValues
  control: ReturnType<typeof useForm<FormValues>>['control']
  mono?: boolean
  placeholder?: string
  numericMode?: 'decimal' | 'integer' | 'decimal4'
}) {
  return (
    <div>
      <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
        {label}
      </label>
      <Controller
        control={control}
        name={name}
        render={({ field }) => (
          <AppInput
            mono={mono}
            placeholder={placeholder}
            numericMode={numericMode}
            value={field.value ?? ''}
            onChange={field.onChange}
          />
        )}
      />
    </div>
  )
}

function FieldSelect({
  label,
  name,
  control,
  options,
  placeholder,
}: {
  label: string
  name: keyof FormValues
  control: ReturnType<typeof useForm<FormValues>>['control']
  options: Array<{ value: string; label: string }>
  placeholder?: string
}) {
  return (
    <div className="min-w-0">
      <label className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
        {label}
      </label>
      <Controller
        control={control}
        name={name}
        render={({ field }) => (
          <AppSelect
            value={field.value || undefined}
            onValueChange={field.onChange}
            options={options}
            placeholder={placeholder}
          />
        )}
      />
    </div>
  )
}

function findLine(lines: ContractLine[], idStr?: string): ContractLine | undefined {
  if (!idStr) return undefined
  const id = Number(idStr)
  return lines.find((l) => l.id === id)
}

/** Preview alineado al backend: discount_amount = 0 → subtotal = qty × unit; annual/12 + FX. */
function lineMrrNetOfCoupon(
  quantity: number,
  unitPrice: string,
  billingFrequency: string,
  fx: number,
): string {
  let subtotal = moneyMul(String(Math.max(0, quantity)), unitPrice)
  if (Number(subtotal) < 0) subtotal = '0.00'
  let mrr = subtotal
  if (billingFrequency === 'annual') mrr = moneyDiv12(subtotal)
  if (fx !== 1) mrr = moneyMul(mrr, String(fx))
  if (Number(mrr) < 0) return '0.00'
  return mrr
}

function computePreview(
  operation: ContractLifecycleOperation,
  contract: ContractDetail,
  values: FormValues,
  activeRecurring: ContractLine[],
): { before: string; delta: string; after: string; neutral: boolean } {
  const before = contract.mrr_mxn
  const fx = Number(contract.exchange_rate) || 1

  if (operation === 'issuer_transfer') {
    return { before, delta: '0.00', after: before, neutral: true }
  }

  if (operation === 'churn') {
    return { before, delta: moneySub('0.00', before), after: '0.00', neutral: false }
  }

  if (operation === 'reactivation') {
    const restored = activeRecurring.reduce((acc, l) => moneyAdd(acc, l.mrr_mxn), '0.00')
    // Si líneas están inactivas (churn), estimar desde subtotal.
    const estimated =
      restored === '0.00'
        ? contract.lines
            .filter((l) => l.service_type === 'recurring')
            .reduce((acc, l) => {
              const freq = l.billing_frequency || contract.billing_frequency
              let mrr = l.subtotal
              if (freq === 'annual') mrr = moneyDiv12(mrr)
              if (fx !== 1) mrr = moneyMul(mrr, String(fx))
              return moneyAdd(acc, mrr)
            }, '0.00')
        : restored
    return { before: '0.00', delta: estimated, after: estimated, neutral: false }
  }

  if (operation === 'frequency_change') {
    const target = (values.billing_frequency ?? 'monthly') as 'monthly' | 'annual'
    const from = contract.billing_frequency === 'annual' ? 'annual' : 'monthly'
    if (target === from) {
      return { before, delta: '0.00', after: before, neutral: false }
    }
    // Cupón quemado en todas las recurrentes + conversión ×12/÷12 del unit_price.
    let after = '0.00'
    for (const line of activeRecurring) {
      const convertedUnit =
        from === 'monthly' && target === 'annual'
          ? moneyMul(line.unit_price, '12')
          : from === 'annual' && target === 'monthly'
            ? moneyDiv12(line.unit_price)
            : line.unit_price
      after = moneyAdd(after, lineMrrNetOfCoupon(line.quantity, convertedUnit, target, fx))
    }
    return { before, delta: moneySub(after, before), after, neutral: false }
  }

  if (operation === 'expansion') {
    if (values.expansion_mode === 'increase_qty') {
      const line = findLine(activeRecurring, values.contract_line_id)
      if (!line) return { before, delta: '0.00', after: before, neutral: false }
      const newQty = Math.max(0, Number(values.quantity ?? 0))
      if (newQty <= line.quantity) return { before, delta: '0.00', after: before, neutral: false }
      // Cupón quemado: discount_amount = 0 → subtotal = qty × unit_price.
      const newMrr = lineMrrNetOfCoupon(newQty, line.unit_price, line.billing_frequency, fx)
      const after = moneyAdd(moneySub(before, line.mrr_mxn), newMrr)
      return { before, delta: moneySub(after, before), after, neutral: false }
    }
    // add_service: preview aproximado (servidor aplica RF-2 + lista; sin cupón).
    const qty = Math.max(1, Number(values.quantity ?? 1))
    const approxUnit = '0.00'
    const approx = moneyMul(String(qty), approxUnit)
    return { before, delta: approx, after: moneyAdd(before, approx), neutral: false }
  }

  if (operation === 'contraction') {
    const line = findLine(activeRecurring, values.contract_line_id)
    if (!line) return { before, delta: '0.00', after: before, neutral: false }
    if (values.deactivate === 'true') {
      const after = moneySub(before, line.mrr_mxn)
      return { before, delta: moneySub('0.00', line.mrr_mxn), after, neutral: false }
    }
    const newQty = Math.max(0, Number(values.quantity ?? 0))
    if (newQty >= line.quantity) return { before, delta: '0.00', after: before, neutral: false }
    // Cupón quemado al contraer cantidad.
    const newMrr = lineMrrNetOfCoupon(newQty, line.unit_price, line.billing_frequency, fx)
    const after = moneyAdd(moneySub(before, line.mrr_mxn), newMrr)
    return { before, delta: moneySub(after, before), after, neutral: false }
  }

  if (operation === 'price_adjustment') {
    const line = findLine(activeRecurring, values.contract_line_id)
    if (!line) return { before, delta: '0.00', after: before, neutral: false }
    const unit = values.unit_price?.trim()
    if (!unit || Number.isNaN(Number(unit))) {
      return { before, delta: '0.00', after: before, neutral: false }
    }
    // Cupón quemado: discount_amount = 0 → subtotal = qty × nuevo unit_price.
    const newMrr = lineMrrNetOfCoupon(line.quantity, unit, line.billing_frequency, fx)
    const after = moneyAdd(moneySub(before, line.mrr_mxn), newMrr)
    return { before, delta: moneySub(after, before), after, neutral: false }
  }

  return { before, delta: '0.00', after: before, neutral: false }
}

function buildPayload(
  operation: ContractLifecycleOperation,
  values: FormValues,
): ContractLifecycleInput {
  const base: ContractLifecycleInput = {
    operation,
    effective_date: values.effective_date,
    reason: values.reason.trim(),
  }

  switch (operation) {
    case 'expansion':
      if (values.expansion_mode === 'increase_qty') {
        return {
          ...base,
          contract_line_id: Number(values.contract_line_id),
          quantity: Number(values.quantity),
        }
      }
      return {
        ...base,
        service_id: Number(values.service_id),
        quantity: Number(values.quantity ?? 1),
      }
    case 'contraction':
      return {
        ...base,
        contract_line_id: Number(values.contract_line_id),
        deactivate: values.deactivate === 'true',
        quantity: values.deactivate === 'true' ? undefined : Number(values.quantity),
      }
    case 'price_adjustment':
      return {
        ...base,
        contract_line_id: Number(values.contract_line_id),
        unit_price: values.unit_price?.trim(),
      }
    case 'frequency_change':
      return {
        ...base,
        billing_frequency: (values.billing_frequency as 'monthly' | 'annual') ?? 'monthly',
      }
    case 'issuer_transfer':
      return {
        ...base,
        issuer_id: Number(values.issuer_id),
      }
    default:
      return base
  }
}
