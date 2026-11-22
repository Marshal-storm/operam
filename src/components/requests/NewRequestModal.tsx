import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo } from 'react'
import {
  Controller,
  useFieldArray,
  useForm,
  useWatch,
  type Control,
  type FieldPath,
} from 'react-hook-form'
import { z } from 'zod'
import { RequestImpactPreview } from '@/components/requests/RequestImpactPreview'
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
import { AppSelect, type AppSelectOption } from '@/components/ui/AppSelect'
import { optimizeUserPackages, resolveTierPriceDetailed } from '@/lib/pricingCalculator'
import { formatCurrencyMx } from '@/lib/utils'
import { fetchPriceLists, fetchServices } from '@/services/catalogService'
import { fetchContractsPaginated } from '@/services/contractsService'
import type { CatalogService, PriceListItem } from '@/types/catalog'
import type { ContractListItem } from '@/types/contract'
import type { ServiceRequestCreateInput } from '@/types/request'

const FIELD_LABEL =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400'

const lineSchema = z.object({
  service_id: z.string().min(1, 'Selecciona un servicio'),
  quantity: z
    .number({ error: 'Indica la cantidad' })
    .int('La cantidad debe ser entera')
    .min(1, 'La cantidad debe ser ≥ 1'),
  custom_unit_price: z.string().optional(),
})

const formSchema = z.object({
  contract_id: z.string().min(1, 'Selecciona un contrato activo'),
  notes: z.string().max(2000).optional(),
  items: z.array(lineSchema).min(1, 'Agrega al menos un servicio'),
})

type FormValues = z.infer<typeof formSchema>

function mulMoney(qty: number, unit: string): string {
  const cents = Math.round(Number(unit) * 100) * qty
  return (cents / 100).toFixed(2)
}

function addMoney(a: string, b: string): string {
  return ((Math.round(Number(a) * 100) + Math.round(Number(b) * 100)) / 100).toFixed(2)
}

function mapServiceType(t: string): 'recurring' | 'consumption' | 'one_time' {
  if (t === 'recurring') return 'recurring'
  if (t === 'consumption') return 'consumption'
  return 'one_time'
}

type PreviewLine = {
  serviceName: string
  serviceType: 'recurring' | 'consumption' | 'one_time'
  quantity: number
  unitPrice: string
  subtotal: string
  mrrImpact: string
  rf2Applied: boolean
  rf2Savings: string
  error?: string
}

function buildPreview(
  contract: ContractListItem | undefined,
  items: FormValues['items'],
  services: CatalogService[],
  priceItems: PriceListItem[],
): { lines: PreviewLine[]; total: string; mrr: string; rf2: boolean; rf2Savings: string } {
  if (!contract) {
    return { lines: [], total: '0.00', mrr: '0.00', rf2: false, rf2Savings: '0.00' }
  }

  const freq = contract.billing_frequency === 'annual' ? 'annual' : 'monthly'
  const fx = Number(contract.exchange_rate || '1')
  const lines: PreviewLine[] = []
  let total = '0.00'
  let mrr = '0.00'
  let rf2 = false
  let rf2Savings = '0.00'

  for (const item of items) {
    const serviceId = Number(item.service_id)
    const service = services.find((s) => s.id === serviceId)
    if (!service || !item.service_id) continue

    const type = mapServiceType(service.service_type)
    const resolved = resolveTierPriceDetailed(
      priceItems,
      serviceId,
      freq,
      item.quantity,
      service.service_type,
    )

    let unitPrice = resolved.ok ? resolved.unitPrice : '0.00'
    let error: string | undefined
    if (!resolved.ok) {
      if (type === 'one_time' && item.custom_unit_price && Number(item.custom_unit_price) > 0) {
        unitPrice = Number(item.custom_unit_price).toFixed(2)
      } else {
        error =
          resolved.reason === 'out_of_range'
            ? `Cantidad fuera de rango (máx. ${resolved.maxQuantity ?? '—'})`
            : 'Sin precio en la lista del contrato'
      }
    } else if (type === 'one_time' && item.custom_unit_price && Number(item.custom_unit_price) > 0) {
      unitPrice = Number(item.custom_unit_price).toFixed(2)
    }

    let subtotal = mulMoney(item.quantity, unitPrice)
    let lineMrr = '0.00'
    let lineRf2 = false
    let lineSavings = '0.00'

    if (type === 'recurring' && !error) {
      if (
        service.allow_package_conversion &&
        service.package_service_id &&
        service.package_factor >= 2
      ) {
        const pkgResolved = resolveTierPriceDetailed(
          priceItems,
          service.package_service_id,
          freq,
          1,
          'recurring',
        )
        if (pkgResolved.ok) {
          const opt = optimizeUserPackages(
            item.quantity,
            unitPrice,
            pkgResolved.unitPrice,
            service.package_factor,
          )
          if (opt.packsCount > 0) {
            lineRf2 = true
            rf2 = true
            lineSavings = opt.savings
            rf2Savings = addMoney(rf2Savings, opt.savings)
            const packSub = mulMoney(opt.packsCount, pkgResolved.unitPrice)
            const singleSub = mulMoney(opt.leftoverUsers, unitPrice)
            subtotal = addMoney(packSub, singleSub)
          }
        }
      }
      let monthly = subtotal
      if (freq === 'annual') {
        monthly = (Math.round(Number(subtotal) * 100) / 12 / 100).toFixed(2)
      }
      lineMrr = (Math.round(Number(monthly) * fx * 100) / 100).toFixed(2)
      mrr = addMoney(mrr, lineMrr)
    }

    total = addMoney(total, subtotal)
    lines.push({
      serviceName: service.name,
      serviceType: type,
      quantity: item.quantity,
      unitPrice,
      subtotal,
      mrrImpact: lineMrr,
      rf2Applied: lineRf2,
      rf2Savings: lineSavings,
      error,
    })
  }

  const hasRecurring = lines.some((l) => l.serviceType === 'recurring' && !l.error)
  if (!hasRecurring) mrr = '0.00'

  return { lines, total, mrr, rf2, rf2Savings }
}

function FieldSelect({
  label,
  name,
  control,
  options,
  placeholder,
  disabled,
}: {
  label: string
  name: FieldPath<FormValues>
  control: Control<FormValues>
  options: AppSelectOption[]
  placeholder?: string
  disabled?: boolean
}) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <div>
          <label className={FIELD_LABEL}>{label}</label>
          <AppSelect
            value={typeof field.value === 'string' ? field.value : ''}
            onValueChange={field.onChange}
            options={options}
            placeholder={placeholder}
            disabled={disabled}
            error={Boolean(fieldState.error)}
          />
          {fieldState.error ? (
            <p className="mt-1 text-xs text-red-500">{fieldState.error.message}</p>
          ) : null}
        </div>
      )}
    />
  )
}

export type NewRequestModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (input: ServiceRequestCreateInput) => Promise<void>
}

/**
 * Modal «Nueva solicitud» — RHF + Zod + preview UI-9 en vivo.
 */
export function NewRequestModal({ open, onOpenChange, onSubmit }: NewRequestModalProps) {
  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      contract_id: '',
      notes: '',
      items: [{ service_id: '', quantity: 1, custom_unit_price: '' }],
    },
  })

  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'items' })
  const watched = useWatch({ control: form.control })

  const contractsQuery = useQuery({
    queryKey: ['contracts', 'active-for-requests'],
    queryFn: () => fetchContractsPaginated({ page: 1, per_page: 100, status: 'active' }),
    enabled: open,
    staleTime: 30_000,
  })

  const contractId = watched.contract_id ? Number(watched.contract_id) : null
  const selectedContract = useMemo(
    () => contractsQuery.data?.items.find((c) => c.id === contractId),
    [contractsQuery.data?.items, contractId],
  )

  const priceListsQuery = useQuery({
    queryKey: ['config', 'price-lists'],
    queryFn: fetchPriceLists,
    enabled: open,
    staleTime: 60_000,
  })

  const servicesQuery = useQuery({
    queryKey: ['config', 'services', 'active'],
    queryFn: () => fetchServices({ page: 1, per_page: 100, status: 'active' }),
    enabled: open,
    staleTime: 60_000,
  })

  const priceList = useMemo(() => {
    if (!selectedContract?.price_list_id) return null
    return priceListsQuery.data?.find((p) => p.id === selectedContract.price_list_id) ?? null
  }, [priceListsQuery.data, selectedContract?.price_list_id])

  const servicesInList = useMemo(() => {
    if (!selectedContract || !priceList) return []
    const serviceIds = new Set(priceList.items.map((i) => i.service_id))
    return (servicesQuery.data?.items ?? []).filter(
      (s) =>
        s.is_active &&
        s.segment_id === selectedContract.segment_id &&
        serviceIds.has(s.id),
    )
  }, [selectedContract, priceList, servicesQuery.data?.items])

  const preview = useMemo(() => {
    const rawItems = (watched.items ?? []).map((item) => ({
      service_id: String(item?.service_id ?? ''),
      quantity: Math.max(1, Number(item?.quantity ?? 1)),
      custom_unit_price: item?.custom_unit_price,
    }))
    return buildPreview(
      selectedContract,
      rawItems,
      servicesInList,
      priceList?.items ?? [],
    )
  }, [selectedContract, watched.items, servicesInList, priceList?.items])

  useEffect(() => {
    if (!open) {
      form.reset({
        contract_id: '',
        notes: '',
        items: [{ service_id: '', quantity: 1, custom_unit_price: '' }],
      })
    }
  }, [open, form])

  const contractOptions = useMemo(
    () =>
      (contractsQuery.data?.items ?? []).map((c) => ({
        value: String(c.id),
        label: `${c.folio} — ${c.client_name}`,
      })),
    [contractsQuery.data?.items],
  )

  const serviceOptions = useMemo(
    () =>
      servicesInList.map((s) => {
        const typeLabel =
          s.service_type === 'recurring'
            ? 'Recurrente'
            : s.service_type === 'consumption'
              ? 'Consumo'
              : 'Pago único'
        return {
          value: String(s.id),
          label: `${s.name} (${typeLabel})`,
        }
      }),
    [servicesInList],
  )

  const hasPreviewError = preview.lines.some((l) => l.error)

  const handleSubmit = form.handleSubmit(async (values) => {
    if (hasPreviewError) return
    const input: ServiceRequestCreateInput = {
      contract_id: Number(values.contract_id),
      notes: values.notes?.trim() || undefined,
      items: values.items.map((item) => {
        const row: ServiceRequestCreateInput['items'][number] = {
          service_id: Number(item.service_id),
          quantity: item.quantity,
        }
        if (item.custom_unit_price && Number(item.custom_unit_price) > 0) {
          row.custom_unit_price = Number(item.custom_unit_price).toFixed(2)
        }
        return row
      }),
    }
    await onSubmit(input)
    onOpenChange(false)
  })

  return (
    <AppModal open={open} onOpenChange={onOpenChange}>
      <AppModalContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
        <AppModalHeader>
          <AppModalTitle>Nueva solicitud</AppModalTitle>
          <AppModalDescription>
            Pedido de horas, timbres, compras sueltas o ampliación recurrente sobre un
            contrato activo.
          </AppModalDescription>
        </AppModalHeader>

        <form className="space-y-4" onSubmit={handleSubmit}>
          <Controller
            control={form.control}
            name="contract_id"
            render={({ field, fieldState }) => (
              <div>
                <label className={FIELD_LABEL}>Contrato activo</label>
                <AppSelect
                  value={field.value}
                  onValueChange={(v) => {
                    field.onChange(v)
                    form.setValue('items', [
                      { service_id: '', quantity: 1, custom_unit_price: '' },
                    ])
                  }}
                  options={contractOptions}
                  placeholder="Selecciona un contrato"
                  error={Boolean(fieldState.error)}
                />
                {fieldState.error ? (
                  <p className="mt-1 text-xs text-red-500">{fieldState.error.message}</p>
                ) : null}
              </div>
            )}
          />

          {selectedContract ? (
            <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200/80 bg-white/60 px-3 py-2.5 text-sm dark:border-white/[0.09] dark:bg-white/[0.03]">
              <span className="font-medium">{selectedContract.client_name}</span>
              <AppBadge label={selectedContract.segment_name} tone="neutral" />
              {selectedContract.contract_is_current !== false &&
              Number(selectedContract.contract_days_overdue ?? 0) <= 0 ? (
                <AppBadge label="Al corriente" tone="success" />
              ) : (
                <AppBadge
                  label={`Adeudo ${formatCurrencyMx(selectedContract.contract_overdue_balance ?? '0', { fractionDigits: 2 })}`}
                  tone="warning"
                />
              )}
              <span className="font-mono text-xs tabular-nums text-slate-500">
                Lista: {selectedContract.price_list_name ?? '—'}
              </span>
            </div>
          ) : null}

          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <p className={FIELD_LABEL}>Servicios</p>
              <AppButton
                type="button"
                variant="ghost"
                size="sm"
                disabled={!selectedContract}
                onClick={() =>
                  append({ service_id: '', quantity: 1, custom_unit_price: '' })
                }
              >
                <Plus className="mr-1 h-3.5 w-3.5" />
                Agregar
              </AppButton>
            </div>

            {fields.map((field, index) => {
              const serviceId = watched.items?.[index]?.service_id
              const service = servicesInList.find((s) => String(s.id) === serviceId)
              const isOneTime = service?.service_type === 'single'
              const linePreview = preview.lines[index]

              return (
                <div
                  key={field.id}
                  className="space-y-2 rounded-2xl border border-slate-200/70 p-3 dark:border-white/[0.08]"
                >
                  <div className="flex flex-wrap items-end gap-2">
                    <div className="min-w-[200px] flex-1">
                      <FieldSelect
                        label="Servicio"
                        name={`items.${index}.service_id`}
                        control={form.control}
                        options={serviceOptions}
                        placeholder={
                          selectedContract
                            ? 'Selecciona servicio'
                            : 'Elige contrato primero'
                        }
                        disabled={!selectedContract}
                      />
                    </div>
                    <div className="w-28">
                      <Controller
                        control={form.control}
                        name={`items.${index}.quantity`}
                        render={({ field: f }) => (
                          <div>
                            <label className={FIELD_LABEL}>Cantidad</label>
                            <AppInput
                              type="number"
                              min={1}
                              mono
                              value={String(f.value ?? 1)}
                              onChange={(e) => f.onChange(Number(e.target.value) || 1)}
                            />
                          </div>
                        )}
                      />
                    </div>
                    {fields.length > 1 ? (
                      <AppButton
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="mb-0.5 text-red-500"
                        onClick={() => remove(index)}
                        aria-label="Quitar línea"
                      >
                        <Trash2 className="h-4 w-4" />
                      </AppButton>
                    ) : null}
                  </div>

                  {isOneTime ? (
                    <Controller
                      control={form.control}
                      name={`items.${index}.custom_unit_price`}
                      render={({ field: f }) => (
                        <div>
                          <label className={FIELD_LABEL}>
                            Precio unitario personalizado (opcional)
                          </label>
                          <AppInput
                            mono
                            placeholder={linePreview?.unitPrice || '0.00'}
                            value={f.value ?? ''}
                            onChange={f.onChange}
                          />
                        </div>
                      )}
                    />
                  ) : null}

                  {linePreview && !linePreview.error ? (
                    <p className="font-mono text-xs tabular-nums text-slate-500">
                      {formatCurrencyMx(linePreview.unitPrice, { fractionDigits: 2 })} ×{' '}
                      {linePreview.quantity} ={' '}
                      {formatCurrencyMx(linePreview.subtotal, { fractionDigits: 2 })}
                    </p>
                  ) : null}
                  {linePreview?.error ? (
                    <p className="text-xs text-amber-600 dark:text-amber-400">
                      {linePreview.error}
                    </p>
                  ) : null}
                </div>
              )
            })}
          </div>

          <Controller
            control={form.control}
            name="notes"
            render={({ field }) => (
              <div>
                <label className={FIELD_LABEL}>Notas / descripción del pedido</label>
                <AppTextarea
                  placeholder='Ej. "4 Formatos PDF"'
                  value={field.value ?? ''}
                  onChange={field.onChange}
                />
              </div>
            )}
          />

          <RequestImpactPreview
            requestTotal={preview.total}
            mrrImpact={preview.mrr}
            noMrrImpact={Number(preview.mrr) <= 0}
            rf2Applied={preview.rf2}
            rf2Savings={preview.rf2Savings}
          />

          <AppModalFooter>
            <AppButton type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancelar
            </AppButton>
            <AppButton
              type="submit"
              variant="primary"
              disabled={
                form.formState.isSubmitting ||
                !selectedContract ||
                hasPreviewError ||
                preview.lines.length === 0
              }
              isLoading={form.formState.isSubmitting}
            >
              Crear solicitud
            </AppButton>
          </AppModalFooter>
        </form>
      </AppModalContent>
    </AppModal>
  )
}
