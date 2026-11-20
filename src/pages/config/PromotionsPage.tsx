import { zodResolver } from '@hookform/resolvers/zod'
import { Pause, Pencil, Play, Plus, Ticket } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppFilterField } from '@/components/ui/AppFilterField'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppCurrencyInput, AppInput, AppSearchInput } from '@/components/ui/AppInput'
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
import { usePromotionsConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { paginateRows, useClientTableState } from '@/hooks/useClientTableState'
import { cn, formatCurrencyMx } from '@/lib/utils'
import type { DiscountType, Promotion } from '@/types/catalog'

function benefitLabel(promo: Promotion): string {
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

const formSchema = z.object({
  code: z.string().min(2).max(40),
  name: z.string().min(3).max(120),
  segment_id: z.string().optional(),
  discount_type: z.enum(['percentage', 'fixed_amount', 'free_months']),
  discount_value: z.string().regex(/^\d+(\.\d{1,2})?$/),
  applies_to: z.enum(['recurring', 'single', 'both']),
  valid_from: z.string().min(8),
  valid_until: z.string().optional(),
  max_uses: z.string().optional(),
  is_active: z.boolean(),
})

type FormValues = z.infer<typeof formSchema>

/**
 * Promociones admin — filtros + colores semánticos Activa/Pausada.
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
  } = useSegmentLock()
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<Promotion | null>(null)

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      code: '',
      name: '',
      segment_id: '',
      discount_type: 'percentage',
      discount_value: '10.00',
      applies_to: 'recurring',
      valid_from: new Date().toISOString().slice(0, 10),
      valid_until: '',
      max_uses: '',
      is_active: true,
    },
  })

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return promotions.filter((p) => {
      if (
        q &&
        !p.code.toLowerCase().includes(q) &&
        !p.name.toLowerCase().includes(q)
      ) {
        return false
      }
      if (estado === 'Activas' && !p.is_active) return false
      if (estado === 'Pausadas' && p.is_active) return false
      if (segment !== 'Todos' && p.segment_name && p.segment_name !== segment) {
        return false
      }
      return true
    })
  }, [promotions, search, estado, segment])

  const pageRows = paginateRows(filtered, page, pageSize)

  function openCreate() {
    setEditing(null)
    form.reset({
      code: '',
      name: '',
      segment_id: '',
      discount_type: 'percentage',
      discount_value: '10.00',
      applies_to: 'recurring',
      valid_from: new Date().toISOString().slice(0, 10),
      valid_until: '',
      max_uses: '',
      is_active: true,
    })
    setModalOpen(true)
  }

  function openEdit(promo: Promotion) {
    setEditing(promo)
    form.reset({
      code: promo.code,
      name: promo.name,
      segment_id: promo.segment_id != null ? String(promo.segment_id) : '',
      discount_type: promo.discount_type,
      discount_value: promo.discount_value,
      applies_to: promo.applies_to,
      valid_from: promo.valid_from.slice(0, 10),
      valid_until: promo.valid_until?.slice(0, 10) ?? '',
      max_uses: promo.max_uses != null ? String(promo.max_uses) : '',
      is_active: promo.is_active,
    })
    setModalOpen(true)
  }

  async function onSubmit(values: FormValues) {
    let valor = values.discount_value
    if (!valor.includes('.')) valor = `${valor}.00`
    const payload = {
      code: values.code.trim().toUpperCase(),
      name: values.name.trim(),
      segment_id: values.segment_id ? Number(values.segment_id) : null,
      discount_type: values.discount_type as DiscountType,
      discount_value: valor,
      applies_to: values.applies_to,
      valid_from: values.valid_from,
      valid_until: values.valid_until || null,
      max_uses: values.max_uses ? Number(values.max_uses) : null,
      is_active: values.is_active,
    }
    try {
      if (editing) {
        await updatePromotion({ id: editing.id, data: payload })
        toast.success('Promoción actualizada', payload.code)
      } else {
        await createPromotion({ ...payload, current_uses: 0 })
        toast.success('Promoción creada', payload.code)
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
            Administra cupones: verde = activa, ámbar = pausada
          </p>
        </div>
        {canCreate('config_promotions') && (
          <AppButton onClick={openCreate}>
            <Plus className="h-4 w-4" /> Nueva promoción
          </AppButton>
        )}
      </header>

      <AppCard className="space-y-3 p-4">
        <AppSearchInput
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
          onClear={() => {
            setSearch('')
            setPage(1)
          }}
          placeholder="Buscar por código o nombre…"
          aria-label="Buscar promociones"
        />
        <div className="flex flex-wrap items-end gap-3">
          <AppFilterField label="Estado">
            <div className="ios-pill-track" role="group" aria-label="Estado">
              {['Todas', 'Activas', 'Pausadas'].map((opt) => (
                <AppButton
                  key={opt}
                  type="button"
                  variant={estado === opt ? 'primary' : 'ghost'}
                  size="sm"
                  onClick={() => {
                    setEstado(opt)
                    setPage(1)
                  }}
                  className={cn(
                    'h-auto rounded-xl px-2.5 py-1 text-xs font-normal shadow-none',
                    estado === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                  )}
                >
                  {opt}
                </AppButton>
              ))}
            </div>
          </AppFilterField>
          <AppFilterField label="Segmento">
            <div className="ios-pill-track" role="group" aria-label="Segmento">
              {segmentOptions.map((opt) => (
                <AppButton
                  key={opt.value}
                  type="button"
                  variant={segment === opt.value ? 'primary' : 'ghost'}
                  size="sm"
                  disabled={isSegmentLocked}
                  onClick={() => {
                    if (isSegmentLocked) return
                    setSegment(opt.value)
                    setPage(1)
                  }}
                  className={cn(
                    'h-auto rounded-xl px-2.5 py-1 text-xs font-normal shadow-none',
                    segment === opt.value ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                    isSegmentLocked && 'cursor-not-allowed opacity-80',
                  )}
                >
                  {opt.label}
                </AppButton>
              ))}
            </div>
          </AppFilterField>
        </div>
      </AppCard>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {pageRows.map((promo) => {
          const max = promo.max_uses
          const used = promo.current_uses
          const pct =
            max != null && max > 0 ? Math.min(100, Math.round((used / max) * 100)) : null

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
                    'flex h-10 w-10 items-center justify-center rounded-2xl',
                    promo.is_active
                      ? 'bg-emerald-500/15 text-emerald-600'
                      : 'bg-amber-500/15 text-amber-600',
                  )}
                >
                  <Ticket className="h-5 w-5" />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-mono text-xl font-bold tracking-wide tabular-nums">
                      {promo.code}
                    </p>
                    <AppBadge
                      label={promo.is_active ? 'Activa' : 'Pausada'}
                      tone={promo.is_active ? 'success' : 'warning'}
                    />
                  </div>
                  <p className="mt-1 text-sm font-medium">
                    {benefitLabel(promo)} en {appliesLabel(promo.applies_to)}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">{promo.name}</p>
                  <p className="mt-1 text-xs text-slate-500">
                    {promo.segment_name || 'Todos los segmentos'}
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
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>{editing ? 'Editar promoción' : 'Nueva promoción'}</AppModalTitle>
            <AppModalDescription>Código, beneficio y vigencia.</AppModalDescription>
          </AppModalHeader>
          <form className="space-y-3" onSubmit={form.handleSubmit(onSubmit)}>
            <label className="block text-sm">
              Código
              <AppInput
                withLabelSpacing
                mono
                className="uppercase"
                {...form.register('code')}
              />
            </label>
            <label className="block text-sm">
              Nombre
              <AppInput withLabelSpacing {...form.register('name')} />
            </label>
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
                  <AppCurrencyInput withLabelSpacing {...form.register('discount_value')} />
                ) : (
                  <AppInput withLabelSpacing mono {...form.register('discount_value')} />
                )}
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
                onValueChange={(v) =>
                  form.setValue('segment_id', v === 'all' ? '' : v, { shouldValidate: true })
                }
                options={[
                  { value: 'all', label: 'Todos' },
                  ...segments.map((s) => ({ value: String(s.id), label: s.name })),
                ]}
              />
            </label>
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
              <AppInput withLabelSpacing mono {...form.register('max_uses')} />
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
