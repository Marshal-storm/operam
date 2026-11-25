import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Gift, Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import {
  AppCard,
  AppCardContent,
  AppCardHeader,
  AppCardTitle,
} from '@/components/ui/AppCard'
import { AppInput, AppTextarea } from '@/components/ui/AppInput'
import { AppSelect } from '@/components/ui/AppSelect'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { useToast } from '@/context/ToastContext'
import { useContracts } from '@/hooks/useContracts'
import { useSegments } from '@/hooks/useSegments'
import {
  formatPriceListLabel,
  optimizeUserPackages,
  resolveTierPriceDetailed,
} from '@/lib/pricingCalculator'
import { cn, formatCurrencyMx, formatDateEsMx } from '@/lib/utils'
import {
  exchangeRateSchema,
  nonNegativeIntSchema,
  positiveIntSchema,
} from '@/lib/validationSchemas'
import { previewBenefitRules } from '@/services/benefitRulesService'
import {
  fetchInstances,
  fetchPriceLists,
  fetchPromotions,
  fetchServices,
} from '@/services/catalogService'
import { fetchClient, fetchClientsPaginated } from '@/services/clientsService'
import type { CatalogService, PriceList, Promotion } from '@/types/catalog'
import type { Client } from '@/types/client'

type DraftLine = {
  key: string
  serviceId: string
  quantity: number
}

function todayInMexicoCity(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Mexico_City',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

/** Próximo corte: aniversario +1 mes (mensual) o +1 año (anual). Alineado con ContractsService. */
function computeNextCutoffDate(
  anniversaryDate: string,
  frequency: 'monthly' | 'annual',
): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(anniversaryDate)
  if (!match) return null
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  const base = new Date(Date.UTC(year, month - 1, day))
  if (Number.isNaN(base.getTime())) return null
  if (frequency === 'annual') {
    base.setUTCFullYear(base.getUTCFullYear() + 1)
  } else {
    base.setUTCMonth(base.getUTCMonth() + 1)
  }
  const y = base.getUTCFullYear()
  const m = String(base.getUTCMonth() + 1).padStart(2, '0')
  const d = String(base.getUTCDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function moneyMul(a: string, b: string): string {
  const centsA = Math.round(Number(a) * 100)
  const centsB = Math.round(Number(b) * 100)
  return ((centsA * centsB) / 10000).toFixed(2)
}

function moneyAdd(a: string, b: string): string {
  return ((Math.round(Number(a) * 100) + Math.round(Number(b) * 100)) / 100).toFixed(2)
}

function moneySub(a: string, b: string): string {
  return ((Math.round(Number(a) * 100) - Math.round(Number(b) * 100)) / 100).toFixed(2)
}

function moneyDiv(a: string, divisor: number): string {
  return (Math.round(Number(a) * 100) / divisor / 100).toFixed(2)
}

/**
 * Formulario Nuevo Contrato — Página 26 del diseño (`/contratos/nuevo`).
 */
export function NewContractPage() {
  const navigate = useNavigate()
  const toast = useToast()
  const [searchParams] = useSearchParams()
  const { createContract, isCreating } = useContracts({ page: 1, per_page: 10 })
  const { segments } = useSegments()

  const presetClientId = searchParams.get('client_id')

  const [clientId, setClientId] = useState(presetClientId ?? '')
  const [selectedClient, setSelectedClient] = useState<Client | null>(null)
  const [priceListId, setPriceListId] = useState('')
  const [frequency, setFrequency] = useState<'monthly' | 'annual'>('monthly')
  const [instanceId, setInstanceId] = useState('')
  const [anniversaryDate, setAnniversaryDate] = useState(todayInMexicoCity())
  const [creditDays, setCreditDays] = useState(30)
  const [stampOnIssue, setStampOnIssue] = useState(true)
  const [promotionId, setPromotionId] = useState('')
  const [exchangeRate, setExchangeRate] = useState('1.0000')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([
    { key: crypto.randomUUID(), serviceId: '', quantity: 1 },
  ])

  const clientsQuery = useQuery({
    queryKey: ['clients', 'picker', 'active'],
    queryFn: () => fetchClientsPaginated({ page: 1, per_page: 100, active: true }),
    staleTime: 30_000,
  })

  const priceListsQuery = useQuery({
    queryKey: ['price-lists'],
    queryFn: fetchPriceLists,
    staleTime: 60_000,
  })

  const servicesQuery = useQuery({
    queryKey: ['services', 'all-active'],
    queryFn: () => fetchServices({ page: 1, per_page: 100, status: 'active' }),
    staleTime: 60_000,
  })

  const promotionsQuery = useQuery({
    queryKey: ['promotions'],
    queryFn: fetchPromotions,
    staleTime: 60_000,
  })

  const instancesQuery = useQuery({
    queryKey: ['instances', selectedClient?.issuer_id, selectedClient?.segment_id],
    queryFn: () =>
      fetchInstances({
        page: 1,
        per_page: 100,
        issuer_id: selectedClient?.issuer_id,
        segment: selectedClient?.segment_name ?? undefined,
      }),
    enabled: Boolean(selectedClient),
    staleTime: 30_000,
  })

  // Precarga cliente desde ?client_id=
  useEffect(() => {
    const id = Number(presetClientId)
    if (!id) return
    void fetchClient(id)
      .then((client) => {
        setClientId(String(client.id))
        setSelectedClient(client)
        setCreditDays(client.credit_days)
        setStampOnIssue(client.stamp_on_issue)
      })
      .catch(() => {
        toast.apiError(new Error('No se pudo precargar el cliente de la URL.'))
      })
  }, [presetClientId, toast])

  const priceLists = priceListsQuery.data ?? []
  const services = servicesQuery.data?.items ?? []
  const promotions = promotionsQuery.data ?? []

  const clientOptions = useMemo(() => {
    const items = clientsQuery.data?.items ?? []
    const map = new Map(items.map((c) => [c.id, c]))
    if (selectedClient && !map.has(selectedClient.id)) {
      map.set(selectedClient.id, selectedClient)
    }
    return [...map.values()].map((c) => ({
      value: String(c.id),
      label: `${c.name} · ${c.rfc}`,
    }))
  }, [clientsQuery.data, selectedClient])

  const segmentLists = useMemo(() => {
    if (!selectedClient) return [] as PriceList[]
    return priceLists.filter(
      (pl) => pl.segment_id === selectedClient.segment_id && pl.is_active !== false,
    )
  }, [priceLists, selectedClient])

  const selectedList = segmentLists.find((pl) => String(pl.id) === priceListId) ?? null

  useEffect(() => {
    if (!selectedClient) return
    const defaults = priceLists.filter(
      (pl) => pl.segment_id === selectedClient.segment_id && pl.is_active !== false,
    )
    const preferred = defaults.find((pl) => pl.is_default) ?? defaults[0]
    if (preferred && (!priceListId || !defaults.some((pl) => String(pl.id) === priceListId))) {
      setPriceListId(String(preferred.id))
    }
    const seg = segments.find((s) => s.id === selectedClient.segment_id)
    if (seg?.usd_exchange_rate) {
      setExchangeRate(seg.usd_exchange_rate)
    }
  }, [selectedClient, priceLists, priceListId, segments])

  const serviceById = useMemo(() => {
    const map = new Map<number, CatalogService>()
    for (const s of services) map.set(s.id, s)
    return map
  }, [services])

  const availableServices = useMemo(() => {
    if (!selectedList) return [] as CatalogService[]
    const ids = new Set(selectedList.items.map((i) => i.service_id))
    // Solo activos (is_active) y presentes en la lista de precios seleccionada.
    return services.filter((s) => {
      const isActive = Boolean(Number(s.is_active))
      return isActive && ids.has(s.id)
    })
  }, [selectedList, services])

  const activePromos = useMemo(() => {
    if (!selectedClient) return [] as Promotion[]
    const today = todayInMexicoCity()
    return promotions.filter((p) => {
      if (!p.is_active) return false
      if (p.segment_id != null && p.segment_id !== selectedClient.segment_id) return false
      if (p.valid_from > today) return false
      if (p.valid_until && p.valid_until < today) return false
      if (p.max_uses != null && p.current_uses >= p.max_uses) return false
      return true
    })
  }, [promotions, selectedClient])

  type PreviewLine = {
    key: string
    draftKey: string
    label: string
    serviceType: string
    quantity: number
    unitPrice: string
    subtotal: string
    mrrMxn: string
    rf2Note?: string
    rf2Savings?: string
    isOneTime: boolean
    outOfRange: boolean
    maxQuantity: number | null
  }

  const preview = useMemo(() => {
    const fx = selectedList?.currency === 'USD' ? exchangeRate : '1.0000'
    const out: PreviewLine[] = []
    let rf2SavingsTotal = '0.00'
    let recurringSubtotal = '0.00'
    let oneTimeSubtotal = '0.00'
    let hasOutOfRange = false

    for (const line of lines) {
      const serviceId = Number(line.serviceId)
      if (!serviceId || line.quantity < 1 || !selectedList) continue
      const service = serviceById.get(serviceId)
      if (service && !Boolean(Number(service.is_active))) continue
      if (!service) continue

      const priced = resolveTierPriceDetailed(
        selectedList.items,
        serviceId,
        frequency,
        line.quantity,
        service.service_type,
      )

      if (!priced.ok) {
        hasOutOfRange = true
        out.push({
          key: `${line.key}-oor`,
          draftKey: line.key,
          label: service.name,
          serviceType: service.service_type,
          quantity: line.quantity,
          unitPrice: '0.00',
          subtotal: '0.00',
          mrrMxn: '0.00',
          isOneTime: service.service_type !== 'recurring',
          outOfRange: true,
          maxQuantity: priced.maxQuantity,
        })
        continue
      }

      const unit = priced.unitPrice

      let expanded: Array<{
        label: string
        qty: number
        unitPrice: string
        converted: boolean
        savings: string
        packageFactor?: number
      }> = []

      const packageFactor = Math.max(2, Number(service.package_factor) || 5)
      if (
        service.allow_package_conversion &&
        service.package_service_id &&
        packageFactor >= 2 &&
        line.quantity >= packageFactor
      ) {
        const packResolved = resolveTierPriceDetailed(
          selectedList.items,
          service.package_service_id,
          frequency,
          1,
          'recurring',
        )
        if (!packResolved.ok) {
          hasOutOfRange = true
          out.push({
            key: `${line.key}-pack-oor`,
            draftKey: line.key,
            label: service.package_service_name ?? 'Paquete',
            serviceType: service.service_type,
            quantity: line.quantity,
            unitPrice: '0.00',
            subtotal: '0.00',
            mrrMxn: '0.00',
            isOneTime: false,
            outOfRange: true,
            maxQuantity: packResolved.maxQuantity,
          })
          continue
        }
        const packPrice = packResolved.unitPrice
        const opt = optimizeUserPackages(line.quantity, unit, packPrice, packageFactor)
        rf2SavingsTotal = moneyAdd(rf2SavingsTotal, opt.savings)
        if (opt.packsCount > 0) {
          expanded.push({
            label: service.package_service_name ?? 'Paquete',
            qty: opt.packsCount,
            unitPrice: packPrice,
            converted: true,
            savings: opt.savings,
            packageFactor,
          })
        }
        if (opt.leftoverUsers > 0) {
          expanded.push({
            label: service.name,
            qty: opt.leftoverUsers,
            unitPrice: unit,
            converted: true,
            savings: '0.00',
            packageFactor,
          })
        }
      } else {
        expanded = [
          {
            label: service.name,
            qty: line.quantity,
            unitPrice: unit,
            converted: false,
            savings: '0.00',
          },
        ]
      }

      const isRecurring = service.service_type === 'recurring'
      for (const exp of expanded) {
        const subtotal = moneyMul(String(exp.qty), exp.unitPrice)
        let mrr = '0.00'
        if (isRecurring) {
          mrr =
            frequency === 'annual'
              ? moneyMul(moneyDiv(subtotal, 12), fx)
              : moneyMul(subtotal, fx)
          recurringSubtotal = moneyAdd(recurringSubtotal, subtotal)
        } else {
          // Consumo / pago único: suman a factura inicial, $0.00 al MRR.
          oneTimeSubtotal = moneyAdd(oneTimeSubtotal, subtotal)
        }

        let rf2Note: string | undefined
        if (exp.converted && Number(exp.savings) > 0) {
          const factor = exp.packageFactor ?? packageFactor
          const packs = Math.floor(line.quantity / factor)
          const singles = line.quantity % factor
          rf2Note = `Conversión automática RF-2: ${packs} Paquetes de ${factor}${
            singles > 0 ? ` + ${singles} Usuarios individuales` : ''
          } · Ahorro de ${formatCurrencyMx(exp.savings, { fractionDigits: 2 })}`
        }

        out.push({
          key: `${line.key}-${exp.label}-${exp.qty}`,
          draftKey: line.key,
          label: exp.label,
          serviceType: service.service_type,
          quantity: exp.qty,
          unitPrice: exp.unitPrice,
          subtotal,
          mrrMxn: mrr,
          rf2Note,
          rf2Savings: exp.savings,
          isOneTime: !isRecurring,
          outOfRange: false,
          maxQuantity: null,
        })
      }
    }

    let promoDiscount = '0.00'
    const promo = activePromos.find((p) => String(p.id) === promotionId)
    if (promo && Number(recurringSubtotal) > 0) {
      if (promo.discount_type === 'percentage') {
        promoDiscount = (
          (Math.round(Number(recurringSubtotal) * 100) * Number(promo.discount_value)) /
          100 /
          100
        ).toFixed(2)
      } else if (promo.discount_type === 'fixed_amount') {
        promoDiscount =
          Number(promo.discount_value) > Number(recurringSubtotal)
            ? recurringSubtotal
            : Number(promo.discount_value).toFixed(2)
      }
    }

    const discountRatio =
      Number(recurringSubtotal) > 0 ? Number(promoDiscount) / Number(recurringSubtotal) : 0

    let mrrImpact = '0.00'
    for (const line of out) {
      if (line.isOneTime || line.outOfRange) continue
      const discountedMrr = (
        Math.round(Number(line.mrrMxn) * 100 * (1 - discountRatio)) / 100
      ).toFixed(2)
      line.mrrMxn = discountedMrr
      mrrImpact = moneyAdd(mrrImpact, discountedMrr)
    }

    const invoiceBeforeDiscount = moneyAdd(recurringSubtotal, oneTimeSubtotal)
    const invoiceTotal = moneySub(invoiceBeforeDiscount, promoDiscount)

    return {
      lines: out,
      rf2SavingsTotal,
      promoDiscount,
      promo,
      recurringSubtotal,
      oneTimeSubtotal,
      invoiceTotal,
      mrrImpact,
      hasOutOfRange,
      currency: selectedList?.currency ?? 'MXN',
      fx,
    }
  }, [
    lines,
    selectedList,
    serviceById,
    frequency,
    exchangeRate,
    activePromos,
    promotionId,
  ])

  const nextCutoffDate = useMemo(
    () => computeNextCutoffDate(anniversaryDate, frequency),
    [anniversaryDate, frequency],
  )

  const validBenefitLines = useMemo(
    () =>
      lines
        .filter((l) => Number(l.serviceId) > 0 && l.quantity >= 1)
        .map((l) => ({
          service_id: Number(l.serviceId),
          quantity: l.quantity,
        })),
    [lines],
  )

  const benefitPreviewQuery = useQuery({
    queryKey: ['benefit-rules', 'preview', validBenefitLines],
    queryFn: () => previewBenefitRules({ lines: validBenefitLines }),
    enabled: validBenefitLines.length > 0,
    staleTime: 10_000,
  })

  const canSubmit =
    Boolean(clientId) &&
    Boolean(priceListId) &&
    Boolean(anniversaryDate) &&
    lines.some((l) => Number(l.serviceId) > 0 && l.quantity >= 1) &&
    !preview.hasOutOfRange &&
    !isCreating

  async function handleClientChange(value: string) {
    setClientId(value)
    setInstanceId('')
    setPromotionId('')
    const id = Number(value)
    if (!id) {
      setSelectedClient(null)
      return
    }
    try {
      const client = await fetchClient(id)
      setSelectedClient(client)
      setCreditDays(client.credit_days)
      setStampOnIssue(client.stamp_on_issue)
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function handleSubmit() {
    if (!canSubmit) return
    const daysOk = nonNegativeIntSchema().safeParse(String(creditDays))
    if (!daysOk.success) {
      toast.error('Datos inválidos', 'Revisa los días de crédito.')
      return
    }
    if (selectedList?.currency === 'USD') {
      const fxOk = exchangeRateSchema().safeParse(exchangeRate)
      if (!fxOk.success) {
        toast.error(
          'Datos inválidos',
          fxOk.error.issues[0]?.message ?? 'Tipo de cambio inválido.',
        )
        return
      }
    }
    for (const line of lines.filter((l) => Number(l.serviceId) > 0)) {
      const qtyOk = positiveIntSchema().safeParse(String(line.quantity))
      if (!qtyOk.success) {
        toast.error('Datos inválidos', 'Las cantidades deben ser enteros mayores a 0.')
        return
      }
    }
    try {
      const created = await createContract({
        client_id: Number(clientId),
        instance_id: instanceId ? Number(instanceId) : null,
        price_list_id: Number(priceListId),
        billing_frequency: frequency,
        anniversary_date: anniversaryDate,
        credit_days: creditDays,
        stamp_on_issue: stampOnIssue,
        promotion_id: promotionId ? Number(promotionId) : null,
        exchange_rate: selectedList?.currency === 'USD' ? exchangeRate : undefined,
        notes: notes.trim() || undefined,
        lines: lines
          .filter((l) => Number(l.serviceId) > 0 && l.quantity >= 1)
          .map((l) => ({
            service_id: Number(l.serviceId),
            quantity: l.quantity,
          })),
      })
      toast.success('Contrato creado', `${created.folio} registrado e impactó el MRR.`)
      navigate('/contratos')
    } catch (error) {
      toast.apiError(error)
    }
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <AppButton
            type="button"
            variant="ghost"
            className="rounded-xl"
            onClick={() => navigate('/contratos')}
          >
            <ArrowLeft className="h-4 w-4" />
          </AppButton>
          <div>
            <h1 className="font-heading text-2xl font-semibold tracking-[-0.4px] md:text-3xl">
              Nuevo contrato
            </h1>
            <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
              Condiciones comerciales, líneas congeladas e impacto inmediato en MRR
            </p>
          </div>
        </div>
      </header>

      <div className="grid gap-5 lg:grid-cols-12">
        <div className="space-y-5 lg:col-span-8">
          <AppCard>
            <AppCardHeader>
              <AppCardTitle>Cliente y condiciones comerciales</AppCardTitle>
            </AppCardHeader>
            <AppCardContent className="space-y-4">
              <div>
                <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Cliente
                </label>
                <AppSelect
                  value={clientId || undefined}
                  onValueChange={(v) => void handleClientChange(v)}
                  options={clientOptions}
                  placeholder="Buscar cliente…"
                />
                {selectedClient ? (
                  <div className="mt-2 flex flex-wrap gap-2">
                    <AppBadge
                      label={selectedClient.segment_name ?? 'Segmento'}
                      tone="neutral"
                    />
                    <AppBadge
                      label={selectedClient.issuer_name ?? 'Emisor'}
                      tone="info"
                    />
                  </div>
                ) : null}
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Lista de precios
                  </label>
                  <AppSelect
                    value={priceListId || undefined}
                    onValueChange={setPriceListId}
                    options={segmentLists.map((pl) => ({
                      value: String(pl.id),
                      label: formatPriceListLabel(pl.name, pl.currency),
                    }))}
                    placeholder="Selecciona lista…"
                    disabled={!selectedClient}
                  />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Frecuencia de cobro
                  </label>
                  <AppSelect
                    value={frequency}
                    onValueChange={(v) => setFrequency(v as 'monthly' | 'annual')}
                    options={[
                      { value: 'monthly', label: 'Mensual' },
                      { value: 'annual', label: 'Anual' },
                    ]}
                  />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Instancia técnica (opcional)
                  </label>
                  <AppSelect
                    value={instanceId || undefined}
                    onValueChange={(v) => setInstanceId(v === '__none__' ? '' : v)}
                    options={[
                      { value: '__none__', label: 'Sin instancia' },
                      ...(instancesQuery.data?.items ?? []).map((ins) => ({
                        value: String(ins.id),
                        label: ins.name,
                      })),
                    ]}
                    placeholder="Opcional…"
                    disabled={!selectedClient}
                  />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Fecha de inicio / aniversario
                  </label>
                  <AppInput
                    type="date"
                    value={anniversaryDate}
                    onChange={(e) => setAnniversaryDate(e.target.value)}
                  />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Días de crédito
                  </label>
                  <AppInput
                    numericMode="integer"
                    mono
                    value={String(creditDays)}
                    onChange={(e) =>
                      setCreditDays(Math.max(0, Number(e.target.value || '0') || 0))
                    }
                  />
                </div>
                <div className="flex items-end pb-1">
                  <AppSwitch
                    checked={stampOnIssue}
                    onCheckedChange={setStampOnIssue}
                    caption="Timbrar al emitir"
                  />
                </div>
                {selectedList?.currency === 'USD' ? (
                  <div className="sm:col-span-2">
                    <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                      Tipo de cambio USD → MXN
                    </label>
                    <AppInput
                      mono
                      numericMode="decimal4"
                      value={exchangeRate}
                      onChange={(e) => setExchangeRate(e.target.value)}
                    />
                  </div>
                ) : null}
              </div>
            </AppCardContent>
          </AppCard>

          <AppCard>
            <AppCardHeader className="flex flex-row items-center justify-between gap-3">
              <AppCardTitle>Servicios y líneas del contrato</AppCardTitle>
              <AppButton
                type="button"
                variant="outline"
                className="rounded-xl"
                onClick={() =>
                  setLines((prev) => [
                    ...prev,
                    { key: crypto.randomUUID(), serviceId: '', quantity: 1 },
                  ])
                }
                disabled={!selectedList}
              >
                <Plus className="mr-1 h-4 w-4" /> Agregar servicio
              </AppButton>
            </AppCardHeader>
            <AppCardContent className="space-y-4">
              {lines.map((line, index) => {
                const service = serviceById.get(Number(line.serviceId))
                const previewLinesForDraft = preview.lines.filter((p) => p.draftKey === line.key)
                const outOfRangeLine = previewLinesForDraft.find((p) => p.outOfRange)
                const previewLine = previewLinesForDraft.find((p) => p.rf2Note) ?? previewLinesForDraft[0]
                return (
                  <div
                    key={line.key}
                    className={cn(
                      'rounded-2xl border p-4',
                      outOfRangeLine
                        ? 'border-[#EF4444]/40 dark:border-[#EF4444]/35'
                        : 'border-slate-200/80 dark:border-white/[0.08]',
                    )}
                  >
                    <div className="grid gap-3 sm:grid-cols-12">
                      <div className="sm:col-span-7">
                        <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                          Servicio {index + 1}
                        </label>
                        <AppSelect
                          value={line.serviceId || undefined}
                          onValueChange={(v) =>
                            setLines((prev) =>
                              prev.map((l) =>
                                l.key === line.key ? { ...l, serviceId: v } : l,
                              ),
                            )
                          }
                          options={availableServices.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                          }))}
                          placeholder="Selecciona producto…"
                          disabled={!selectedList}
                        />
                      </div>
                      <div className="sm:col-span-3">
                        <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                          Cantidad
                        </label>
                        <AppInput
                          numericMode="integer"
                          mono
                          value={String(line.quantity)}
                          error={Boolean(outOfRangeLine)}
                          onChange={(e) =>
                            setLines((prev) =>
                              prev.map((l) =>
                                l.key === line.key
                                  ? {
                                      ...l,
                                      quantity: Math.max(1, Number(e.target.value || '1') || 1),
                                    }
                                  : l,
                              ),
                            )
                          }
                        />
                      </div>
                      <div className="flex items-end sm:col-span-2">
                        <AppButton
                          type="button"
                          variant="ghost"
                          className="rounded-xl text-red-500"
                          disabled={lines.length <= 1}
                          onClick={() =>
                            setLines((prev) => prev.filter((l) => l.key !== line.key))
                          }
                        >
                          <Trash2 className="h-4 w-4" />
                        </AppButton>
                      </div>
                    </div>
                    {outOfRangeLine ? (
                      <div className="mt-3 rounded-xl border border-[#EF4444]/25 bg-[#EF4444]/5 px-3 py-2 text-sm text-[#EF4444] dark:border-[#EF4444]/30 dark:bg-[#EF4444]/10">
                        Cantidad fuera de los rangos configurados en esta lista (máx.{' '}
                        {outOfRangeLine.maxQuantity ?? '—'})
                      </div>
                    ) : null}
                    {service?.allow_package_conversion && line.quantity >= 5 && !outOfRangeLine ? (
                      <div className="mt-3 rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
                        {previewLine?.rf2Note ??
                          'Conversión automática RF-2 aplicada al confirmar (paquetes + sueltos).'}
                      </div>
                    ) : null}
                  </div>
                )
              })}
              {benefitPreviewQuery.data?.message ? (
                <div className="flex items-start gap-2.5 rounded-2xl border border-[#0A84FF]/25 bg-[#0A84FF]/5 px-4 py-3 text-sm text-[#0066FF] dark:border-[#0A84FF]/30 dark:bg-[#0A84FF]/10 dark:text-[#60A5FA]">
                  <Gift className="mt-0.5 h-4 w-4 shrink-0" />
                  <p>{benefitPreviewQuery.data.message}</p>
                </div>
              ) : null}
            </AppCardContent>
          </AppCard>

          <AppCard>
            <AppCardHeader>
              <AppCardTitle>Promoción y notas</AppCardTitle>
            </AppCardHeader>
            <AppCardContent className="space-y-4">
              <div>
                <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Promoción vigente (opcional)
                </label>
                <AppSelect
                  value={promotionId || undefined}
                  onValueChange={(v) => setPromotionId(v === '__none__' ? '' : v)}
                  options={[
                    { value: '__none__', label: 'Sin promoción' },
                    ...activePromos.map((p) => ({
                      value: String(p.id),
                      label: `${p.code} — ${p.name}`,
                    })),
                  ]}
                  placeholder="Sin promoción"
                  disabled={!selectedClient}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Notas internas
                </label>
                <AppTextarea
                  rows={3}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Observaciones comerciales o de implementación…"
                />
              </div>
            </AppCardContent>
          </AppCard>
        </div>

        {/* Panel sticky UI-9 */}
        <div className="lg:col-span-4">
          <div className="sticky top-20 space-y-4">
            <AppCard>
              <AppCardHeader>
                <AppCardTitle>Resumen de impacto en MRR</AppCardTitle>
              </AppCardHeader>
              <AppCardContent className="space-y-4">
                <div className="space-y-2">
                  {preview.lines.length === 0 ? (
                    <p className="text-sm text-slate-500">Agrega líneas para ver el desglose.</p>
                  ) : (
                    preview.lines.map((line) => (
                      <div
                        key={line.key}
                        className="flex items-start justify-between gap-2 text-sm"
                      >
                        <div className="min-w-0">
                          <p className="truncate font-medium">
                            {line.quantity}× {line.label}
                          </p>
                          {line.outOfRange ? (
                            <p className="text-xs text-[#EF4444]">
                              Cantidad fuera de los rangos configurados en esta lista (máx.{' '}
                              {line.maxQuantity ?? '—'})
                            </p>
                          ) : line.isOneTime ? (
                            <p className="text-xs text-amber-600 dark:text-amber-400">
                              Cobro único / consumo — no suma al MRR
                            </p>
                          ) : null}
                        </div>
                        <span
                          className={cn(
                            'shrink-0 font-mono tabular-nums',
                            line.outOfRange && 'text-[#EF4444]',
                          )}
                        >
                          {line.outOfRange
                            ? '—'
                            : formatCurrencyMx(line.subtotal, { fractionDigits: 2 })}
                        </span>
                      </div>
                    ))
                  )}
                </div>

                {Number(preview.rf2SavingsTotal) > 0 ? (
                  <div className="flex justify-between text-sm text-emerald-600 dark:text-emerald-400">
                    <span>Ahorro RF-2</span>
                    <span className="font-mono tabular-nums">
                      −{formatCurrencyMx(preview.rf2SavingsTotal, { fractionDigits: 2 })}
                    </span>
                  </div>
                ) : null}

                <div className="space-y-1.5 border-t border-slate-200/80 pt-3 text-sm dark:border-white/[0.08]">
                  <div className="flex justify-between">
                    <span className="text-slate-600 dark:text-zinc-400">Subtotal recurrente</span>
                    <span className="font-mono tabular-nums">
                      {formatCurrencyMx(preview.recurringSubtotal, { fractionDigits: 2 })}
                    </span>
                  </div>
                  {Number(preview.oneTimeSubtotal) > 0 ? (
                    <div className="flex justify-between">
                      <span className="text-slate-600 dark:text-zinc-400">
                        Cobros únicos / consumo
                      </span>
                      <span className="font-mono tabular-nums">
                        {formatCurrencyMx(preview.oneTimeSubtotal, { fractionDigits: 2 })}
                      </span>
                    </div>
                  ) : null}
                  {Number(preview.promoDiscount) > 0 ? (
                    <div className="flex justify-between text-amber-600 dark:text-amber-400">
                      <span>Descuento promoción</span>
                      <span className="font-mono tabular-nums">
                        −{formatCurrencyMx(preview.promoDiscount, { fractionDigits: 2 })}
                      </span>
                    </div>
                  ) : null}
                  <div className="flex justify-between pt-1 font-semibold">
                    <span>Total factura inicial ({preview.currency})</span>
                    <span className="font-mono tabular-nums">
                      {formatCurrencyMx(preview.invoiceTotal, { fractionDigits: 2 })}
                    </span>
                  </div>
                  {preview.currency === 'USD' ? (
                    <p className="text-xs text-slate-500">
                      TC {preview.fx} · impacto MRR ya convertido a MXN
                    </p>
                  ) : null}
                </div>

                {preview.hasOutOfRange ? (
                  <p className="rounded-xl border border-[#F59E0B]/30 bg-[#F59E0B]/10 px-3 py-2 text-xs text-[#F59E0B]">
                    Ajusta la cantidad o amplía el escalón en la lista de precios antes de crear el
                    contrato.
                  </p>
                ) : null}

                <div
                  className={cn(
                    'rounded-2xl border p-4',
                    'border-blue-500/25 bg-blue-500/5 dark:border-[#0A84FF]/30 dark:bg-[#0A84FF]/10',
                  )}
                >
                  <p className="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-[#0A84FF]">
                    Impacto en MRR del mes
                  </p>
                  <p className="mt-2 font-mono text-2xl font-semibold tabular-nums tracking-tight text-blue-700 dark:text-white">
                    +{formatCurrencyMx(preview.mrrImpact, { fractionDigits: 2 })} MXN / mes
                  </p>
                  <p className="mt-2 text-xs text-slate-600 dark:text-zinc-400">
                    Solo líneas recurrentes (consumo / único aportan $0.00). Categoría: Nuevo
                    contrato · Próximo corte{' '}
                    {nextCutoffDate ? formatDateEsMx(nextCutoffDate) : '—'}.
                  </p>
                </div>

                <AppButton
                  type="button"
                  className="w-full rounded-xl"
                  disabled={!canSubmit}
                  isLoading={isCreating}
                  onClick={() => void handleSubmit()}
                >
                  Crear contrato
                </AppButton>
              </AppCardContent>
            </AppCard>
          </div>
        </div>
      </div>
    </div>
  )
}
