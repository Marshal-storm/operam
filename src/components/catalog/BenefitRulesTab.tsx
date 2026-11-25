import { zodResolver } from '@hookform/resolvers/zod'
import { Gift, Pencil, Sparkles } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalFooter,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppSelect } from '@/components/ui/AppSelect'
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { paginateRows, useClientTableState } from '@/hooks/useClientTableState'
import { useBenefitRules } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { cn } from '@/lib/utils'
import { positiveIntSchema } from '@/lib/validationSchemas'
import type { BenefitResetPeriod, BenefitRule } from '@/services/benefitRulesService'

const RESET_PERIOD_LABELS: Record<BenefitResetPeriod, string> = {
  monthly: 'Mensual',
  anniversary: 'Aniversario',
}

const STATUS_OPTIONS = [
  { value: 'all', label: 'Todas' },
  { value: 'active', label: 'Activas', tone: 'success' as const },
  { value: 'inactive', label: 'Inactivas', tone: 'danger' as const },
]

const benefitRuleSchema = z
  .object({
    source_service_id: z.string().min(1, 'Selecciona el servicio origen'),
    benefit_service_id: z.string().min(1, 'Selecciona el servicio beneficio'),
    units_per_unit: positiveIntSchema({
      message: 'Ingresa un entero mayor o igual a 1',
      min: 1,
    }),
    reset_period: z.enum(['monthly', 'anniversary']),
    is_cumulative: z.boolean(),
    active: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (
      values.source_service_id &&
      values.benefit_service_id &&
      values.source_service_id === values.benefit_service_id
    ) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'El servicio beneficio debe ser distinto al servicio origen.',
        path: ['benefit_service_id'],
      })
    }
  })

type BenefitRuleFormValues = z.infer<typeof benefitRuleSchema>

type BenefitRulesTabProps = {
  /** Incrementar para abrir el modal de nueva regla desde el header de la página. */
  createSignal: number
}

/**
 * Pestaña de Reglas de Beneficio (RF-2.6) dentro de Catálogo de servicios.
 */
export function BenefitRulesTab({ createSignal }: BenefitRulesTabProps) {
  const { rowPaddingClass } = useScreenPreferences()
  const toast = useToast()
  const { canCreate, canEdit } = usePermissions()
  const {
    rules,
    services,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createBenefitRule,
    updateBenefitRule,
    patchBenefitRule,
  } = useBenefitRules()

  const { page, setPage, pageSize, setPageSize } = useClientTableState()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<BenefitRule | null>(null)

  const form = useForm<BenefitRuleFormValues>({
    resolver: zodResolver(benefitRuleSchema),
    defaultValues: {
      source_service_id: '',
      benefit_service_id: '',
      units_per_unit: '50',
      reset_period: 'monthly',
      is_cumulative: false,
      active: true,
    },
  })

  const watchedSourceId = useWatch({ control: form.control, name: 'source_service_id' })
  const watchedBenefitId = useWatch({ control: form.control, name: 'benefit_service_id' })
  const watchedUnits = useWatch({ control: form.control, name: 'units_per_unit' })
  const watchedReset = useWatch({ control: form.control, name: 'reset_period' })
  const watchedCumulative = useWatch({ control: form.control, name: 'is_cumulative' })

  useEffect(() => {
    if (createSignal <= 0) return
    if (!canCreate('config_catalog')) return
    setEditing(null)
    form.reset({
      source_service_id: '',
      benefit_service_id: '',
      units_per_unit: '50',
      reset_period: 'monthly',
      is_cumulative: false,
      active: true,
    })
    setModalOpen(true)
  }, [createSignal, canCreate, form])

  const serviceOptions = useMemo(
    () =>
      services
        .filter(
          (s) =>
            Boolean(Number(s.is_active)) ||
            (editing != null &&
              (s.id === editing.source_service_id || s.id === editing.benefit_service_id)),
        )
        .map((s) => ({
          value: String(s.id),
          label: `${s.code} — ${s.name}`,
        })),
    [services, editing],
  )

  const livePreview = useMemo(() => {
    const src = services.find((s) => String(s.id) === watchedSourceId)
    const ben = services.find((s) => String(s.id) === watchedBenefitId)
    const srcLabel = src ? src.name : 'unidad de servicio origen'
    const benLabel = ben ? ben.name : 'unidades de beneficio'
    const qty = Math.max(1, Number(watchedUnits) || 0)
    const qtyFormatted = qty.toLocaleString('es-MX')
    const periodText = watchedReset === 'anniversary' ? 'por aniversario' : 'al mes'
    const cumulText = watchedCumulative ? 'acumulables' : 'no acumulables'
    return `Ej. Cada ${srcLabel} otorgará ${qtyFormatted} ${benLabel} ${periodText}, ${cumulText}.`
  }, [services, watchedSourceId, watchedBenefitId, watchedUnits, watchedReset, watchedCumulative])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rules.filter((rule) => {
      if (statusFilter === 'active' && !rule.active) return false
      if (statusFilter === 'inactive' && rule.active) return false
      if (q) {
        const hay = [
          rule.source_service_name,
          rule.source_service_code,
          rule.benefit_service_name,
          rule.benefit_service_code,
        ]
          .join(' ')
          .toLowerCase()
        if (!hay.includes(q)) return false
      }
      return true
    })
  }, [rules, search, statusFilter])

  const pageRows = paginateRows(filtered, page, pageSize)
  const hasActiveFilters = search.trim() !== '' || statusFilter !== 'all'

  function clearFilters() {
    setSearch('')
    setStatusFilter('all')
    setPage(1)
  }

  function openEdit(rule: BenefitRule) {
    if (!canEdit('config_catalog')) return
    setEditing(rule)
    form.reset({
      source_service_id: String(rule.source_service_id),
      benefit_service_id: String(rule.benefit_service_id),
      units_per_unit: String(rule.units_per_unit),
      reset_period: rule.reset_period,
      is_cumulative: rule.is_cumulative,
      active: rule.active,
    })
    setModalOpen(true)
  }

  async function onToggleActive(rule: BenefitRule, next: boolean) {
    if (!canEdit('config_catalog')) return
    try {
      await patchBenefitRule({ id: rule.id, data: { active: next } })
      toast.success(
        next ? 'Regla activada' : 'Regla desactivada',
        `${rule.source_service_name} → ${rule.benefit_service_name}`,
      )
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onSubmit(values: BenefitRuleFormValues) {
    const payload = {
      source_service_id: Number(values.source_service_id),
      benefit_service_id: Number(values.benefit_service_id),
      units_per_unit: Number(values.units_per_unit),
      reset_period: values.reset_period,
      is_cumulative: values.is_cumulative,
      active: values.active,
    }
    try {
      if (editing) {
        await updateBenefitRule({ id: editing.id, data: payload })
        toast.success('Regla actualizada', 'La regla de beneficio se actualizó correctamente.')
      } else {
        await createBenefitRule(payload)
        toast.success('Regla creada', 'La regla de beneficio quedó registrada.')
        setPage(1)
      }
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (isApiError && !rules.length) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error al cargar reglas</h2>
        <p className="mt-1 text-sm text-slate-500">{errorMessage}</p>
        <AppButton className="mt-4" onClick={() => void refetch()}>
          Reintentar
        </AppButton>
      </AppCard>
    )
  }

  return (
    <div className="space-y-5">
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
        searchPlaceholder="Buscar por servicio origen o beneficio otorgado…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'status',
            label: 'ESTADO',
            value: statusFilter,
            onValueChange: (value) => {
              setStatusFilter(value)
              setPage(1)
            },
            options: STATUS_OPTIONS,
          },
        ]}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      />

      <AppTableWrapper
        isLoading={isLoading}
        loadingState={
          <div className="space-y-3 p-4">
            <Skeleton className="h-10 w-full rounded-xl" />
            <Skeleton className="h-10 w-full rounded-xl" />
            <Skeleton className="h-32 w-full rounded-2xl" />
          </div>
        }
        isEmpty={!isLoading && filtered.length === 0}
        emptyState={
          <div className="flex flex-col items-center justify-center gap-2 px-6 py-14 text-center">
            <Gift className="h-9 w-9 text-slate-300 dark:text-zinc-600" />
            <p className="font-heading text-base font-semibold">
              Sin reglas de beneficio configuradas
            </p>
            <p className="max-w-md text-sm text-slate-500 dark:text-zinc-400">
              Define cuántos consumibles incluidos otorga cada producto recurrente al contratarlo.
            </p>
          </div>
        }
        footer={
          <PaginationBar
            page={page}
            total={filtered.length}
            pageSize={pageSize}
            onPageChange={setPage}
            onPageSizeChange={setPageSize}
          />
        }
      >
        {/* Desktop table */}
        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-sm">
            <thead>
              <AppTableHeadRow>
                <th className={cn(rowPaddingClass, 'font-medium')}>SERVICIO ORIGEN</th>
                <th className={cn(rowPaddingClass, 'font-medium')}>BENEFICIO OTORGADO</th>
                <th className={cn(rowPaddingClass, 'font-medium')}>UNIDADES POR UNIDAD</th>
                <th className={cn(rowPaddingClass, 'font-medium')}>REINICIO</th>
                <th className={cn(rowPaddingClass, 'font-medium')}>ACUMULABLE</th>
                <th className={cn(rowPaddingClass, 'font-medium')}>ESTADO</th>
                <th className={cn(rowPaddingClass, 'text-right font-medium')}>
                  <AppActionsColumnHeader />
                </th>
              </AppTableHeadRow>
            </thead>
            <tbody>
              {pageRows.map((rule) => (
                <tr
                  key={rule.id}
                  className={cn(
                    'border-b border-slate-100 dark:border-white/[0.04]',
                    !rule.active && 'opacity-60',
                  )}
                >
                  <td className={rowPaddingClass}>
                    <p className="font-medium">{rule.source_service_name || '—'}</p>
                    {rule.source_service_code ? (
                      <p className="font-mono text-xs tabular-nums text-slate-500">
                        {rule.source_service_code}
                      </p>
                    ) : null}
                  </td>
                  <td className={rowPaddingClass}>
                    <p className="font-medium">{rule.benefit_service_name || '—'}</p>
                    {rule.benefit_service_code ? (
                      <p className="font-mono text-xs tabular-nums text-slate-500">
                        {rule.benefit_service_code}
                      </p>
                    ) : null}
                  </td>
                  <td className={cn(rowPaddingClass, 'font-mono font-semibold tabular-nums')}>
                    {rule.units_per_unit.toLocaleString('es-MX')}
                  </td>
                  <td className={rowPaddingClass}>
                    <AppBadge
                      label={RESET_PERIOD_LABELS[rule.reset_period]}
                      tone="info"
                    />
                  </td>
                  <td className={rowPaddingClass}>
                    <AppBadge
                      label={rule.is_cumulative ? 'Sí' : 'No'}
                      tone={rule.is_cumulative ? 'success' : 'neutral'}
                    />
                  </td>
                  <td className={rowPaddingClass}>
                    <AppSwitch
                      checked={rule.active}
                      caption={rule.active ? 'Activo' : 'Inactivo'}
                      disabled={!canEdit('config_catalog')}
                      onCheckedChange={(next) => void onToggleActive(rule, next)}
                    />
                  </td>
                  <td className={cn(rowPaddingClass, 'text-right')}>
                    <div className="flex justify-end">
                      <AppRowActionsMenu
                        actions={[
                          {
                            label: 'Editar',
                            icon: <Pencil className="h-4 w-4" />,
                            onClick: () => openEdit(rule),
                            hidden: !canEdit('config_catalog'),
                          },
                        ]}
                      />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Mobile cards UI-4 */}
        <div className="space-y-3 p-4 md:hidden">
          {pageRows.map((rule) => (
            <AppCard key={rule.id} className="p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-heading font-semibold">{rule.source_service_name}</p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    Otorga{' '}
                    <span className="font-mono font-semibold tabular-nums text-slate-700 dark:text-zinc-200">
                      {rule.units_per_unit.toLocaleString('es-MX')}
                    </span>{' '}
                    × {rule.benefit_service_name}
                  </p>
                </div>
                <AppRowActionsMenu
                  actions={[
                    {
                      label: 'Editar',
                      icon: <Pencil className="h-4 w-4" />,
                      onClick: () => openEdit(rule),
                      hidden: !canEdit('config_catalog'),
                    },
                  ]}
                />
              </div>
              <div className="mt-3 flex flex-wrap gap-1.5">
                <AppBadge label={RESET_PERIOD_LABELS[rule.reset_period]} tone="info" />
                <AppBadge
                  label={rule.is_cumulative ? 'Acumulable: Sí' : 'Acumulable: No'}
                  tone={rule.is_cumulative ? 'success' : 'neutral'}
                />
              </div>
              <div className="mt-3">
                <AppSwitch
                  checked={rule.active}
                  caption={rule.active ? 'Activo' : 'Inactivo'}
                  disabled={!canEdit('config_catalog')}
                  onCheckedChange={(next) => void onToggleActive(rule, next)}
                />
              </div>
            </AppCard>
          ))}
        </div>
      </AppTableWrapper>

      <AppModal open={modalOpen} onOpenChange={setModalOpen}>
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>
              {editing ? 'Editar regla de beneficio' : 'Nueva regla de beneficio'}
            </AppModalTitle>
            <AppModalDescription>
              Configura qué consumibles incluidos otorga automáticamente un producto al contratarse.
            </AppModalDescription>
          </AppModalHeader>
          <form className="space-y-4" onSubmit={form.handleSubmit(onSubmit)}>
            <label className="block text-sm">
              Servicio origen
              <AppSelect
                withLabelSpacing
                placeholder="Selecciona servicio origen…"
                value={form.watch('source_service_id') || undefined}
                onValueChange={(v) =>
                  form.setValue('source_service_id', v, { shouldValidate: true })
                }
                error={Boolean(form.formState.errors.source_service_id)}
                options={serviceOptions}
              />
              {form.formState.errors.source_service_id ? (
                <span className="mt-1 block text-xs text-red-500">
                  {form.formState.errors.source_service_id.message}
                </span>
              ) : null}
            </label>

            <label className="block text-sm">
              Beneficio otorgado
              <AppSelect
                withLabelSpacing
                placeholder="Selecciona servicio consumible…"
                value={form.watch('benefit_service_id') || undefined}
                onValueChange={(v) =>
                  form.setValue('benefit_service_id', v, { shouldValidate: true })
                }
                error={Boolean(form.formState.errors.benefit_service_id)}
                options={serviceOptions}
              />
              {form.formState.errors.benefit_service_id ? (
                <span className="mt-1 block text-xs text-red-500">
                  {form.formState.errors.benefit_service_id.message}
                </span>
              ) : null}
            </label>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label className="block text-sm">
                Unidades por unidad
                <AppInput
                  withLabelSpacing
                  mono
                  numericMode="integer"
                  error={Boolean(form.formState.errors.units_per_unit)}
                  {...form.register('units_per_unit')}
                />
                {form.formState.errors.units_per_unit ? (
                  <span className="mt-1 block text-xs text-red-500">
                    {form.formState.errors.units_per_unit.message}
                  </span>
                ) : null}
              </label>

              <label className="block text-sm">
                Reinicio del periodo
                <AppSelect
                  withLabelSpacing
                  value={form.watch('reset_period')}
                  onValueChange={(v) =>
                    form.setValue('reset_period', v as BenefitResetPeriod, {
                      shouldValidate: true,
                    })
                  }
                  options={[
                    { value: 'monthly', label: 'Mensual' },
                    { value: 'anniversary', label: 'Aniversario' },
                  ]}
                />
              </label>
            </div>

            <div className="space-y-3">
              <label className="flex items-center gap-3 text-sm">
                <AppSwitch
                  checked={form.watch('is_cumulative')}
                  onCheckedChange={(next) =>
                    form.setValue('is_cumulative', next, { shouldValidate: true })
                  }
                  label="Acumulable entre periodos"
                />
                Acumulable entre periodos
              </label>
              <label className="flex items-center gap-3 text-sm">
                <AppSwitch
                  checked={form.watch('active')}
                  onCheckedChange={(next) =>
                    form.setValue('active', next, { shouldValidate: true })
                  }
                  label="Regla activa"
                />
                Regla activa
              </label>
            </div>

            <div className="flex items-start gap-2.5 rounded-2xl border border-[#0A84FF]/25 bg-[#0A84FF]/5 px-3.5 py-2.5 text-xs text-[#0066FF] dark:border-[#0A84FF]/30 dark:bg-[#0A84FF]/10 dark:text-[#60A5FA]">
              <Sparkles className="mt-0.5 h-4 w-4 shrink-0" />
              <p>{livePreview}</p>
            </div>

            <AppModalFooter>
              <AppButton
                type="button"
                variant="outline"
                onClick={() => setModalOpen(false)}
              >
                Cancelar
              </AppButton>
              <AppButton type="submit">
                {editing ? 'Guardar cambios' : 'Crear regla'}
              </AppButton>
            </AppModalFooter>
          </form>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
