import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { Pause, Pencil, Play, Plus, Sparkles, Ticket, X } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppCurrencyInput, AppInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppSelect } from '@/components/ui/AppSelect'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/context/ToastContext'
import { paginateRows, useClientTableState } from '@/hooks/useClientTableState'
import { usePromotionsConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { cn, formatCurrencyMx } from '@/lib/utils'
import {
  moneySchema,
  nonNegativeIntSchema,
  positiveIntSchema,
} from '@/lib/validationSchemas'
import { fetchServices, type PromotionWriteInput } from '@/services/catalogService'
import { fetchClientsPaginated } from '@/services/clientsService'
import type { DiscountType, Promotion, PromotionMode } from '@/types/catalog'

type ScopeMode = 'all' | 'segments' | 'clients'

function fallbackBenefitLabel(promo: Promotion): string {
  if (promo.benefit_label) return promo.benefit_label
  if (promo.discount_type === 'percentage') {
    return `${promo.discount_value.replace(/\.00$/, '')}% de descuento`
  }
  if (promo.discount_type === 'free_months') {
    const n = promo.discount_value.replace(/\.00$/, '')
    return `${n} mes${n === '1' ? '' : 'es'} gratis`
  }
  return `${formatCurrencyMx(promo.discount_value)} de descuento fijo`
}

function appliesLabel(aplica: Promotion['applies_to']): string {
  if (aplica === 'recurring') return 'planes de suscripción'
  if (aplica === 'single') return 'pagos únicos'
  return 'suscripciones y pagos únicos'
}

function formatScopeSummary(promo: Promotion): string {
  if (promo.scopes && promo.scopes.length > 0) {
    const segNames = promo.scopes
      .map((s) => s.segment_name)
      .filter((n): n is string => Boolean(n))
    const clientNames = promo.scopes
      .map((s) => s.client_name)
      .filter((n): n is string => Boolean(n))
    const parts: string[] = []
    if (segNames.length > 0) {
      parts.push(
        segNames.length <= 2
          ? `Segmentos: ${segNames.join(', ')}`
          : `${segNames.length} segmentos`,
      )
    }
    if (clientNames.length > 0) {
      parts.push(
        clientNames.length <= 2
          ? `Clientes: ${clientNames.join(', ')}`
          : `${clientNames.length} clientes específicos`,
      )
    }
    if (parts.length > 0) return parts.join(' · ')
  }
  return promo.segment_name || 'Todos los segmentos'
}

const formSchema = z
  .object({
    code: z.string().min(2, 'Mínimo 2 caracteres').max(40),
    name: z.string().min(3, 'Mínimo 3 caracteres').max(120),
    promo_mode: z.enum(['price_discount', 'extra_units']),
    segment_id: z.string().optional(),
    service_id: z.string().optional(),
    min_quantity: z.string().optional(),
    extra_units_pct: z.string().optional(),
    discount_type: z.enum(['percentage', 'fixed_amount', 'free_months']),
    discount_value: z.string(),
    applies_to: z.enum(['recurring', 'single', 'both']),
    valid_from: z.string().min(8, 'Indica fecha de inicio'),
    valid_until: z.string().optional(),
    max_uses: z
      .string()
      .optional()
      .superRefine((val, ctx) => {
        if (val == null || val.trim() === '') return
        const parsed = nonNegativeIntSchema().safeParse(val)
        if (!parsed.success) {
          ctx.addIssue({
            code: 'custom',
            message: parsed.error.issues[0]?.message ?? 'Ingresa un entero válido',
          })
        }
      }),
    is_active: z.boolean(),
    visible_in_portal: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (values.promo_mode === 'extra_units') {
      if (!values.service_id) {
        ctx.addIssue({
          code: 'custom',
          message: 'Selecciona el servicio consumible.',
          path: ['service_id'],
        })
      }
      const minCheck = positiveIntSchema({
        message: 'Ingresa una cantidad mínima ≥ 1',
        min: 1,
      }).safeParse(values.min_quantity ?? '')
      if (!minCheck.success) {
        ctx.addIssue({
          code: 'custom',
          message: minCheck.error.issues[0]?.message ?? 'Cantidad mínima inválida',
          path: ['min_quantity'],
        })
      }
      const pctNum = Number(values.extra_units_pct ?? '')
      if (
        !values.extra_units_pct?.trim() ||
        !Number.isFinite(pctNum) ||
        pctNum <= 0
      ) {
        ctx.addIssue({
          code: 'custom',
          message: 'Indica un porcentaje mayor a 0',
          path: ['extra_units_pct'],
        })
      }
    } else {
      const moneyCheck = moneySchema({ allowZero: true }).safeParse(
        values.discount_value,
      )
      if (!moneyCheck.success) {
        ctx.addIssue({
          code: 'custom',
          message: moneyCheck.error.issues[0]?.message ?? 'Valor inválido',
          path: ['discount_value'],
        })
      }
    }
  })

type FormValues = z.infer<typeof formSchema>

/**
 * Promociones admin — cupones de descuento y promociones de unidades extra (%) con alcances.
 */
export function PromotionsPage() {
  const toast = useToast()
  const {
    promotions,
    segments,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createPromotion,
    updatePromotion,
    togglePromotion,
  } = usePromotionsConfig()

  const { page, setPage, pageSize, setPageSize } = useClientTableState()
  const [search, setSearch] = useState('')
  const [estado, setEstado] = useState('Todas')
  const { canCreate, canEdit, canDelete } = usePermissions()
  const {
    segment,
    setSegment,
    segmentOptions,
    isSegmentLocked,
    lockedSegmentId,
  } = useSegmentLock()
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<Promotion | null>(null)
  const [scopeMode, setScopeMode] = useState<ScopeMode>('all')
  const [selectedSegmentIds, setSelectedSegmentIds] = useState<number[]>([])
  const [selectedClientIds, setSelectedClientIds] = useState<number[]>([])

  const servicesQuery = useQuery({
    queryKey: ['config', 'services', 'promo-consumables'],
    queryFn: () => fetchServices({ page: 1, per_page: 200, status: 'active' }),
    enabled: modalOpen,
    staleTime: 60_000,
  })

  const clientsQuery = useQuery({
    queryKey: ['clients', 'promo-scopes'],
    queryFn: () => fetchClientsPaginated({ page: 1, per_page: 200, active: true }),
    enabled: modalOpen,
    staleTime: 60_000,
  })

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      code: '',
      name: '',
      promo_mode: 'price_discount',
      segment_id: '',
      service_id: '',
      min_quantity: '1000',
      extra_units_pct: '25',
      discount_type: 'percentage',
      discount_value: '10.00',
      applies_to: 'recurring',
      valid_from: new Date().toISOString().slice(0, 10),
      valid_until: '',
      max_uses: '',
      is_active: true,
      visible_in_portal: true,
    },
  })

  const watchedMode = useWatch({ control: form.control, name: 'promo_mode' })
  const watchedMinQty = useWatch({ control: form.control, name: 'min_quantity' })
  const watchedExtraPct = useWatch({ control: form.control, name: 'extra_units_pct' })

  const consumableServiceOptions = useMemo(() => {
    const all = servicesQuery.data?.items ?? []
    const consumables = all.filter((s) => s.service_type === 'consumption')
    const list = consumables.length > 0 ? consumables : all
    return list.map((s) => ({
      value: String(s.id),
      label: `${s.code} — ${s.name}`,
    }))
  }, [servicesQuery.data?.items])

  const availableSegmentScopeOptions = useMemo(
    () =>
      segments
        .filter((s) => !selectedSegmentIds.includes(s.id))
        .map((s) => ({ value: String(s.id), label: s.name })),
    [segments, selectedSegmentIds],
  )

  const allClients = clientsQuery.data?.items ?? []

  const availableClientScopeOptions = useMemo(
    () =>
      allClients
        .filter((c) => !selectedClientIds.includes(c.id))
        .map((c) => ({ value: String(c.id), label: `${c.name} · ${c.rfc}` })),
    [allClients, selectedClientIds],
  )

  const extraUnitsPreview = useMemo(() => {
    const minQty = Math.max(1, Number(watchedMinQty) || 0)
    const pct = Number(watchedExtraPct) || 0
    const bonus = pct > 0 ? Math.floor((minQty * pct) / 100) : 0
    const total = minQty + bonus
    return `Al comprar ${minQty.toLocaleString('es-MX')} unidades recibirá +${bonus.toLocaleString('es-MX')} gratis = ${total.toLocaleString('es-MX')} totales`
  }, [watchedMinQty, watchedExtraPct])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return promotions.filter((p) => {
      if (
        q &&
        !p.code.toLowerCase().includes(q) &&
        !p.name.toLowerCase().includes(q) &&
        !(p.benefit_label ?? '').toLowerCase().includes(q)
      ) {
        return false
      }
      if (estado === 'Activas' && !p.is_active) return false
      if (estado === 'Pausadas' && p.is_active) return false
      if (segment !== 'Todos') {
        const hasScopeMatch = (p.scopes ?? []).some(
          (s) => s.segment_name && s.segment_name === segment,
        )
        const hasLegacyMatch = !p.segment_name || p.segment_name === segment
        if (!hasScopeMatch && !hasLegacyMatch) return false
      }
      return true
    })
  }, [promotions, search, estado, segment])

  const pageRows = paginateRows(filtered, page, pageSize)

  const hasActiveFilters =
    search.trim() !== '' ||
    estado !== 'Todas' ||
    (!isSegmentLocked && segment !== 'Todos')

  function clearFilters() {
    setSearch('')
    setEstado('Todas')
    if (!isSegmentLocked) setSegment('Todos')
    setPage(1)
  }

  function openCreate() {
    setEditing(null)
    setScopeMode(isSegmentLocked && lockedSegmentId ? 'segments' : 'all')
    setSelectedSegmentIds(isSegmentLocked && lockedSegmentId ? [lockedSegmentId] : [])
    setSelectedClientIds([])
    form.reset({
      code: '',
      name: '',
      promo_mode: 'price_discount',
      segment_id: isSegmentLocked && lockedSegmentId ? String(lockedSegmentId) : '',
      service_id: '',
      min_quantity: '1000',
      extra_units_pct: '25',
      discount_type: 'percentage',
      discount_value: '10.00',
      applies_to: 'recurring',
      valid_from: new Date().toISOString().slice(0, 10),
      valid_until: '',
      max_uses: '',
      is_active: true,
      visible_in_portal: true,
    })
    setModalOpen(true)
  }

  function openEdit(promo: Promotion) {
    setEditing(promo)
    const segIds = (promo.scopes ?? [])
      .map((s) => s.segment_id)
      .filter((id): id is number => id != null && id > 0)
    const cliIds = (promo.scopes ?? [])
      .map((s) => s.client_id)
      .filter((id): id is number => id != null && id > 0)

    if (cliIds.length > 0) {
      setScopeMode('clients')
    } else if (segIds.length > 0) {
      setScopeMode('segments')
    } else {
      setScopeMode('all')
    }
    setSelectedSegmentIds(segIds)
    setSelectedClientIds(cliIds)

    form.reset({
      code: promo.code,
      name: promo.name,
      promo_mode: promo.promo_mode ?? 'price_discount',
      segment_id: promo.segment_id != null ? String(promo.segment_id) : '',
      service_id: promo.service_id != null ? String(promo.service_id) : '',
      min_quantity: promo.min_quantity != null ? String(promo.min_quantity) : '1000',
      extra_units_pct: promo.extra_units_pct ?? '25',
      discount_type: promo.discount_type,
      discount_value: promo.discount_value,
      applies_to: promo.applies_to,
      valid_from: promo.valid_from.slice(0, 10),
      valid_until: promo.valid_until?.slice(0, 10) ?? '',
      max_uses: promo.max_uses != null ? String(promo.max_uses) : '',
      is_active: promo.is_active,
      visible_in_portal: promo.visible_in_portal ?? true,
    })
    setModalOpen(true)
  }

  async function onSubmit(values: FormValues) {
    const isExtra = values.promo_mode === 'extra_units'

    if (isExtra && scopeMode === 'segments' && selectedSegmentIds.length === 0) {
      toast.error('Alcance incompleto', 'Agrega al menos un segmento para esta promoción.')
      return
    }
    if (isExtra && scopeMode === 'clients' && selectedClientIds.length === 0) {
      toast.error('Alcance incompleto', 'Agrega al menos un cliente para esta promoción.')
      return
    }

    let valor = values.discount_value || '0.00'
    if (!valor.includes('.')) valor = `${valor}.00`

    const payload: PromotionWriteInput = {
      code: values.code.trim().toUpperCase(),
      name: values.name.trim(),
      promo_mode: values.promo_mode,
      valid_from: values.valid_from,
      valid_until: values.valid_until || null,
      max_uses: values.max_uses ? Number(values.max_uses) : null,
      is_active: values.is_active,
      visible_in_portal: isExtra ? values.visible_in_portal : true,
    }

    if (isExtra) {
      payload.service_id = Number(values.service_id)
      payload.min_quantity = Number(values.min_quantity)
      payload.extra_units_pct = values.extra_units_pct?.trim() ?? '0'
      payload.segment_id = null
      payload.discount_type = 'percentage'
      payload.discount_value = '0.00'
      payload.applies_to = 'single'
      if (scopeMode === 'segments') {
        payload.segment_ids = selectedSegmentIds
        payload.client_ids = []
      } else if (scopeMode === 'clients') {
        payload.client_ids = selectedClientIds
        payload.segment_ids = []
      } else {
        payload.segment_ids = []
        payload.client_ids = []
      }
    } else {
      payload.segment_id = values.segment_id ? Number(values.segment_id) : null
      payload.discount_type = values.discount_type as DiscountType
      payload.discount_value = valor
      payload.applies_to = values.applies_to
      payload.segment_ids = []
      payload.client_ids = []
    }

    try {
      if (editing) {
        await updatePromotion({ id: editing.id, data: payload })
        toast.success('Promoción actualizada', payload.code)
      } else {
        await createPromotion({ ...payload, current_uses: 0 })
        toast.success('Promoción creada', payload.code)
        setPage(1)
      }
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onTogglePromotion(promo: Promotion) {
    try {
      const next = !promo.is_active
      await togglePromotion({ id: promo.id, is_active: next })
      toast.success(next ? 'Promoción activada' : 'Promoción pausada', promo.code)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (isLoading) return <Skeleton className="h-48 w-full rounded-2xl" />
  if (isApiError) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error</h2>
        <p className="text-sm text-slate-500">{errorMessage}</p>
        <AppButton className="mt-4" onClick={() => void refetch()}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Promociones</h1>
          <p className="mt-1 text-sm text-slate-500">
            Administra cupones de descuento y bonificaciones de unidades extra (%)
          </p>
        </div>
        {canCreate('config_promotions') && (
          <AppButton onClick={openCreate}>
            <Plus className="h-4 w-4" /> Nueva promoción
          </AppButton>
        )}
      </header>

      <AppFilterBar
        searchValue={search}
        onSearchChange={(value) => {
          setSearch(value)
          setPage(1)
        }}
        onSearchClear={() => {
          setSearch('')
          setPage(1)
        }}
        searchPlaceholder="Buscar por código, nombre o beneficio…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'status',
            label: 'ESTADO',
            value: estado,
            onValueChange: (value) => {
              setEstado(value)
              setPage(1)
            },
            options: [
              { value: 'Todas', label: 'Todas' },
              { value: 'Activas', label: 'Activas', tone: 'success' },
              { value: 'Pausadas', label: 'Pausadas', tone: 'warning' },
            ],
          },
          {
            id: 'segment',
            label: 'SEGMENTO',
            value: segment,
            onValueChange: (value) => {
              if (isSegmentLocked) return
              setSegment(value)
              setPage(1)
            },
            options: segmentOptions,
            disabled: isSegmentLocked,
          },
        ]}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {pageRows.map((promo) => {
          const max = promo.max_uses
          const used = promo.current_uses
          const pct =
            max != null && max > 0 ? Math.min(100, Math.round((used / max) * 100)) : null
          const isExtra = promo.promo_mode === 'extra_units'

          return (
            <AppCard
              key={promo.id}
              className={cn(
                'relative overflow-hidden rounded-3xl p-5',
                promo.is_active
                  ? 'border-emerald-500/40 bg-emerald-50/40 dark:bg-emerald-950/20'
                  : 'border-amber-500/50 bg-amber-50/50 dark:bg-amber-950/25',
              )}
            >
              <div className="flex items-start gap-3">
                <span
                  className={cn(
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl',
                    promo.is_active
                      ? 'bg-emerald-500/15 text-emerald-600'
                      : 'bg-amber-500/15 text-amber-600',
                  )}
                >
                  <Ticket className="h-5 w-5" />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <p className="font-mono text-xl font-bold tracking-wide tabular-nums">
                      {promo.code}
                    </p>
                    <AppBadge
                      label={promo.is_active ? 'Activa' : 'Pausada'}
                      tone={promo.is_active ? 'success' : 'warning'}
                    />
                  </div>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    <AppBadge
                      label={fallbackBenefitLabel(promo)}
                      tone={isExtra ? 'info' : 'neutral'}
                    />
                    {isExtra && promo.visible_in_portal ? (
                      <AppBadge label="Portal Cliente" tone="success" />
                    ) : null}
                  </div>
                  <p className="mt-2 text-sm font-medium">{promo.name}</p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {isExtra
                      ? promo.service_name
                        ? `Consumible: ${promo.service_name}`
                        : 'Unidades extra en consumible'
                      : `Aplica en ${appliesLabel(promo.applies_to)}`}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">
                    {formatScopeSummary(promo)}
                  </p>
                </div>
              </div>

              <div className="mt-4 space-y-1">
                <div className="flex justify-between text-xs text-slate-500">
                  <span>Usos</span>
                  <span className="font-mono tabular-nums">
                    {used}
                    {max != null ? ` / ${max}` : ' · sin límite'}
                  </span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-black/5 dark:bg-white/10">
                  <div
                    className={cn(
                      'h-full rounded-full',
                      promo.is_active ? 'bg-emerald-500' : 'bg-amber-500',
                    )}
                    style={{ width: `${pct ?? Math.min(100, used * 2)}%` }}
                  />
                </div>
              </div>

              {/* Botones siempre vivos — nunca disabled por estado pausado */}
              <div className="mt-4 flex gap-2">
                {canEdit('config_promotions') && (
                  <AppButton
                    type="button"
                    variant="outline"
                    size="sm"
                    className="flex-1"
                    onClick={() => openEdit(promo)}
                  >
                    <Pencil className="h-3.5 w-3.5" /> Editar
                  </AppButton>
                )}
                {(canEdit('config_promotions') || canDelete('config_promotions')) && (
                  <AppButton
                    type="button"
                    variant="outline"
                    size="sm"
                    className="flex-1"
                    onClick={() => void onTogglePromotion(promo)}
                  >
                    {promo.is_active ? (
                      <>
                        <Pause className="h-3.5 w-3.5" /> Pausar
                      </>
                    ) : (
                      <>
                        <Play className="h-3.5 w-3.5" /> Reactivar
                      </>
                    )}
                  </AppButton>
                )}
              </div>
            </AppCard>
          )
        })}
      </div>

      <PaginationBar
        page={page}
        total={filtered.length}
        pageSize={pageSize}
        onPageChange={setPage}
        onPageSizeChange={setPageSize}
      />

      <AppModal open={modalOpen} onOpenChange={setModalOpen}>
        <AppModalContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
          <AppModalHeader>
            <AppModalTitle>{editing ? 'Editar promoción' : 'Nueva promoción'}</AppModalTitle>
            <AppModalDescription>
              Modo de beneficio, alcance y vigencia en MySQL.
            </AppModalDescription>
          </AppModalHeader>
          <form className="space-y-3.5" onSubmit={form.handleSubmit(onSubmit)}>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label className="block text-sm">
                Código
                <AppInput
                  withLabelSpacing
                  mono
                  className="uppercase"
                  error={Boolean(form.formState.errors.code)}
                  {...form.register('code')}
                />
                {form.formState.errors.code ? (
                  <span className="mt-1 block text-xs text-red-500">
                    {form.formState.errors.code.message}
                  </span>
                ) : null}
              </label>
              <label className="block text-sm">
                Modo de promoción
                <AppSelect
                  withLabelSpacing
                  value={watchedMode}
                  onValueChange={(v) =>
                    form.setValue('promo_mode', v as PromotionMode, {
                      shouldValidate: true,
                    })
                  }
                  options={[
                    { value: 'price_discount', label: 'Descuento en precio' },
                    { value: 'extra_units', label: 'Unidades extra (%)' },
                  ]}
                />
              </label>
            </div>

            <label className="block text-sm">
              Nombre
              <AppInput
                withLabelSpacing
                error={Boolean(form.formState.errors.name)}
                {...form.register('name')}
              />
              {form.formState.errors.name ? (
                <span className="mt-1 block text-xs text-red-500">
                  {form.formState.errors.name.message}
                </span>
              ) : null}
            </label>

            {watchedMode === 'extra_units' ? (
              <AppCard className="space-y-3.5 p-4">
                <label className="block text-sm">
                  Servicio consumible
                  <AppSelect
                    withLabelSpacing
                    placeholder="Selecciona consumible…"
                    value={form.watch('service_id') || undefined}
                    onValueChange={(v) =>
                      form.setValue('service_id', v, { shouldValidate: true })
                    }
                    error={Boolean(form.formState.errors.service_id)}
                    options={consumableServiceOptions}
                  />
                  {form.formState.errors.service_id ? (
                    <span className="mt-1 block text-xs text-red-500">
                      {form.formState.errors.service_id.message}
                    </span>
                  ) : null}
                </label>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <label className="block text-sm">
                    Cantidad mínima
                    <AppInput
                      withLabelSpacing
                      mono
                      numericMode="integer"
                      error={Boolean(form.formState.errors.min_quantity)}
                      {...form.register('min_quantity')}
                    />
                    {form.formState.errors.min_quantity ? (
                      <span className="mt-1 block text-xs text-red-500">
                        {form.formState.errors.min_quantity.message}
                      </span>
                    ) : null}
                  </label>

                  <label className="block text-sm">
                    Unidades extra (%)
                    <AppInput
                      withLabelSpacing
                      mono
                      numericMode="decimal"
                      error={Boolean(form.formState.errors.extra_units_pct)}
                      {...form.register('extra_units_pct')}
                    />
                    {form.formState.errors.extra_units_pct ? (
                      <span className="mt-1 block text-xs text-red-500">
                        {form.formState.errors.extra_units_pct.message}
                      </span>
                    ) : null}
                  </label>
                </div>

                <div className="flex items-start gap-2.5 rounded-2xl border border-[#0A84FF]/25 bg-[#0A84FF]/5 px-3.5 py-2.5 text-xs text-[#0066FF] dark:border-[#0A84FF]/30 dark:bg-[#0A84FF]/10 dark:text-[#60A5FA]">
                  <Sparkles className="mt-0.5 h-4 w-4 shrink-0" />
                  <p className="font-medium tabular-nums">{extraUnitsPreview}</p>
                </div>

                <label className="block text-sm">
                  Alcance de la promoción
                  <AppSelect
                    withLabelSpacing
                    value={scopeMode}
                    onValueChange={(v) => {
                      const next = v as ScopeMode
                      setScopeMode(next)
                      if (next === 'all') {
                        setSelectedSegmentIds([])
                        setSelectedClientIds([])
                      }
                    }}
                    options={[
                      { value: 'all', label: 'Todos los segmentos y clientes' },
                      { value: 'segments', label: 'Por segmento(s)' },
                      { value: 'clients', label: 'Clientes específicos' },
                    ]}
                  />
                </label>

                {scopeMode === 'segments' ? (
                  <div className="space-y-2">
                    <AppSelect
                      placeholder="Agregar segmento…"
                      value={undefined}
                      onValueChange={(v) => {
                        const id = Number(v)
                        if (id > 0 && !selectedSegmentIds.includes(id)) {
                          setSelectedSegmentIds((prev) => [...prev, id])
                        }
                      }}
                      options={availableSegmentScopeOptions}
                      disabled={availableSegmentScopeOptions.length === 0}
                    />
                    <div className="flex flex-wrap gap-1.5">
                      {selectedSegmentIds.length === 0 ? (
                        <p className="text-xs text-slate-500">
                          Selecciona uno o más segmentos.
                        </p>
                      ) : (
                        selectedSegmentIds.map((segId) => {
                          const seg = segments.find((s) => s.id === segId)
                          return (
                            <span
                              key={segId}
                              className="inline-flex items-center gap-1 rounded-full bg-slate-100 py-0.5 pl-2.5 pr-1 text-xs dark:bg-white/[0.08]"
                            >
                              <span className="whitespace-nowrap font-medium">
                                {seg?.name ?? `Segmento #${segId}`}
                              </span>
                              <AppButton
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-5 w-5 rounded-full p-0"
                                aria-label="Quitar segmento"
                                onClick={() =>
                                  setSelectedSegmentIds((prev) =>
                                    prev.filter((id) => id !== segId),
                                  )
                                }
                              >
                                <X className="h-3 w-3" />
                              </AppButton>
                            </span>
                          )
                        })
                      )}
                    </div>
                  </div>
                ) : null}

                {scopeMode === 'clients' ? (
                  <div className="space-y-2">
                    <AppSelect
                      placeholder="Buscar y agregar cliente…"
                      searchable
                      value={undefined}
                      onValueChange={(v) => {
                        const id = Number(v)
                        if (id > 0 && !selectedClientIds.includes(id)) {
                          setSelectedClientIds((prev) => [...prev, id])
                        }
                      }}
                      options={availableClientScopeOptions}
                      disabled={availableClientScopeOptions.length === 0}
                    />
                    <div className="flex flex-wrap gap-1.5">
                      {selectedClientIds.length === 0 ? (
                        <p className="text-xs text-slate-500">
                          Selecciona uno o más clientes.
                        </p>
                      ) : (
                        selectedClientIds.map((cid) => {
                          const cli = allClients.find((c) => c.id === cid)
                          const fallbackName = editing?.scopes?.find(
                            (s) => s.client_id === cid,
                          )?.client_name
                          return (
                            <span
                              key={cid}
                              className="inline-flex items-center gap-1 rounded-full bg-slate-100 py-0.5 pl-2.5 pr-1 text-xs dark:bg-white/[0.08]"
                            >
                              <span className="whitespace-nowrap font-medium">
                                {cli?.name ?? fallbackName ?? `Cliente #${cid}`}
                              </span>
                              <AppButton
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-5 w-5 rounded-full p-0"
                                aria-label="Quitar cliente"
                                onClick={() =>
                                  setSelectedClientIds((prev) =>
                                    prev.filter((id) => id !== cid),
                                  )
                                }
                              >
                                <X className="h-3 w-3" />
                              </AppButton>
                            </span>
                          )
                        })
                      )}
                    </div>
                  </div>
                ) : null}

                <label className="flex items-center gap-2.5 text-sm">
                  <AppSwitch
                    checked={form.watch('visible_in_portal')}
                    onCheckedChange={(next) =>
                      form.setValue('visible_in_portal', next, {
                        shouldValidate: true,
                      })
                    }
                    label="Visible en Portal Cliente"
                  />
                  Visible en Portal Cliente
                </label>
              </AppCard>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-3">
                  <label className="block text-sm">
                    Tipo
                    <AppSelect
                      withLabelSpacing
                      value={form.watch('discount_type')}
                      onValueChange={(v) =>
                        form.setValue('discount_type', v as FormValues['discount_type'], {
                          shouldValidate: true,
                        })
                      }
                      options={[
                        { value: 'percentage', label: 'Porcentaje' },
                        { value: 'fixed_amount', label: 'Monto fijo' },
                        { value: 'free_months', label: 'Meses gratis' },
                      ]}
                    />
                  </label>
                  <label className="block text-sm">
                    Valor
                    {form.watch('discount_type') === 'fixed_amount' ? (
                      <AppCurrencyInput
                        withLabelSpacing
                        error={Boolean(form.formState.errors.discount_value)}
                        {...form.register('discount_value')}
                      />
                    ) : (
                      <AppInput
                        withLabelSpacing
                        mono
                        numericMode="decimal"
                        error={Boolean(form.formState.errors.discount_value)}
                        {...form.register('discount_value')}
                      />
                    )}
                    {form.formState.errors.discount_value ? (
                      <span className="mt-1 block text-xs text-red-500">
                        {form.formState.errors.discount_value.message}
                      </span>
                    ) : null}
                  </label>
                </div>
                <label className="block text-sm">
                  Aplica a
                  <AppSelect
                    withLabelSpacing
                    value={form.watch('applies_to')}
                    onValueChange={(v) =>
                      form.setValue('applies_to', v as FormValues['applies_to'], {
                        shouldValidate: true,
                      })
                    }
                    options={[
                      { value: 'recurring', label: 'Suscripciones' },
                      { value: 'single', label: 'Pagos únicos' },
                      { value: 'both', label: 'Ambos' },
                    ]}
                  />
                </label>
                <label className="block text-sm">
                  Segmento
                  <AppSelect
                    withLabelSpacing
                    value={form.watch('segment_id') || 'all'}
                    disabled={isSegmentLocked}
                    onValueChange={(v) =>
                      form.setValue('segment_id', v === 'all' ? '' : v, {
                        shouldValidate: true,
                      })
                    }
                    options={[
                      { value: 'all', label: 'Todos' },
                      ...segments.map((s) => ({ value: String(s.id), label: s.name })),
                    ]}
                  />
                </label>
              </>
            )}

            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm">
                Desde
                <AppInput type="date" withLabelSpacing {...form.register('valid_from')} />
              </label>
              <label className="block text-sm">
                Hasta
                <AppInput type="date" withLabelSpacing {...form.register('valid_until')} />
              </label>
            </div>
            <label className="block text-sm">
              Usos máximos
              <AppInput
                withLabelSpacing
                mono
                numericMode="integer"
                {...form.register('max_uses')}
              />
            </label>
            <label className="flex items-center gap-2 text-sm">
              <AppSwitch
                checked={form.watch('is_active')}
                onCheckedChange={(next) =>
                  form.setValue('is_active', next, { shouldValidate: true })
                }
                label="Activa"
              />
              Activa
            </label>
            <AppButton type="submit" className="w-full">
              {editing ? 'Guardar' : 'Crear'}
            </AppButton>
          </form>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
