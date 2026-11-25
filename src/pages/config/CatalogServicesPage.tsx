import { zodResolver } from '@hookform/resolvers/zod'
import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'

const catalogTableFeatures = tableFeatures({})
import { useQuery } from '@tanstack/react-query'
import { Pencil, Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import { positiveIntSchema } from '@/lib/validationSchemas'
import { fetchServices } from '@/services/catalogService'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppInput, AppTextarea } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppSelect } from '@/components/ui/AppSelect'
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { useServicesConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { cn } from '@/lib/utils'
import type { CatalogService, ServiceType, ServiceUnit } from '@/types/catalog'

const UNIT_LABELS: Record<ServiceUnit, string> = {
  user: 'Usuario',
  // Valor BD `pack_5_users` se mantiene; etiqueta visible genérica (factor dinámico).
  pack_5_users: 'Paquete',
  stamp: 'Timbre',
  hour: 'Hora',
  license: 'Licencia',
  instance: 'Instancia',
}

const TYPE_LABELS: Record<ServiceType, string> = {
  recurring: 'Recurrente',
  consumption: 'Consumo',
  single: 'Pago único',
}

const TYPE_FILTERS: Array<{ value: string; label: string }> = [
  { value: 'all', label: 'Todos' },
  { value: 'recurring', label: 'Recurrente' },
  { value: 'consumption', label: 'Consumo' },
  { value: 'single', label: 'Pago único' },
]

const STATUS_FILTERS: Array<{ value: string; label: string }> = [
  { value: 'all', label: 'Todos' },
  { value: 'active', label: 'Disponibles' },
  { value: 'inactive', label: 'Inactivos' },
]

const formSchema = z.object({
  code: z.string().min(2).max(40),
  name: z.string().min(3).max(120),
  description: z.string().optional(),
  segment_id: z.string().min(1),
  service_type: z.enum(['recurring', 'consumption', 'single']),
  unit_of_measure: z.enum([
    'user',
    'pack_5_users',
    'stamp',
    'hour',
    'license',
    'instance',
  ]),
  allow_package_conversion: z.boolean(),
  package_service_id: z.string().optional(),
  package_factor: positiveIntSchema({
    message: 'Factor entero entre 2 y 100',
    min: 2,
    max: 100,
  }),
  is_active: z.boolean(),
}).superRefine((values, ctx) => {
  if (values.allow_package_conversion && !values.package_service_id) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      message: 'Selecciona el producto paquete destino (RF-2).',
      path: ['package_service_id'],
    })
  }
})

type FormValues = z.infer<typeof formSchema>

/**
 * Catálogo de servicios — tabla paginada API + filtros (100+ productos).
 */
export function CatalogServicesPage() {
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const toast = useToast()
  const { canCreate, canEdit, canDelete } = usePermissions()
  const {
    segment,
    setSegment,
    segmentOptions,
    isSegmentLocked,
    lockedSegmentId,
  } = useSegmentLock()
  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  useEffect(() => {
    setLocalPageSize(preferredSize)
    setPage(1)
  }, [preferredSize])
  const [search, setSearch] = useState('')
  const [serviceType, setServiceType] = useState('all')
  const [status, setStatus] = useState('all')

  const queryParams = useMemo(
    () => ({
      page,
      per_page: pageSize,
      search: search.trim() || undefined,
      segment: segment === 'Todos' ? undefined : segment,
      service_type: serviceType === 'all' ? undefined : serviceType,
      status: status === 'all' ? undefined : status,
    }),
    [page, pageSize, search, segment, serviceType, status],
  )

  const {
    items,
    total,
    segments,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createService,
    updateService,
    toggleService,
  } = useServicesConfig(queryParams)

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<CatalogService | null>(null)

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      code: '',
      name: '',
      description: '',
      segment_id: '1',
      service_type: 'recurring',
      unit_of_measure: 'license',
      allow_package_conversion: false,
      package_service_id: '',
      package_factor: '5',
      is_active: true,
    },
  })

  const allowsPackage = useWatch({
    control: form.control,
    name: 'allow_package_conversion',
  })
  const watchedUnit = useWatch({
    control: form.control,
    name: 'unit_of_measure',
  })
  const watchedSegmentId = useWatch({
    control: form.control,
    name: 'segment_id',
  })
  const watchedPackageFactor = useWatch({
    control: form.control,
    name: 'package_factor',
  })

  // RF-2: el switch de paquetes solo aplica a Usuario individual.
  const isUserUnit = watchedUnit === 'user'
  const isPackUnit = watchedUnit === 'pack_5_users'

  useEffect(() => {
    if (isUserUnit) return
    if (form.getValues('allow_package_conversion')) {
      form.setValue('allow_package_conversion', false, { shouldValidate: true })
    }
    if (form.getValues('package_service_id')) {
      form.setValue('package_service_id', '', { shouldValidate: true })
    }
  }, [isUserUnit, form])

  const packageOptionsQuery = useQuery({
    queryKey: ['config', 'services', 'packages', watchedSegmentId],
    queryFn: () => fetchServices({ page: 1, per_page: 100, status: 'active' }),
    enabled: modalOpen && Boolean(allowsPackage),
    staleTime: 30_000,
  })

  const packageOptions = useMemo(() => {
    const rows = packageOptionsQuery.data?.items ?? []
    const segmentId = Number(watchedSegmentId)
    return rows.filter(
      (s) =>
        s.unit_of_measure === 'pack_5_users' &&
        (!Number.isFinite(segmentId) || s.segment_id === segmentId) &&
        (!editing || s.id !== editing.id),
    )
  }, [packageOptionsQuery.data?.items, watchedSegmentId, editing])

  const columns = useMemo<Array<ColumnDef<typeof catalogTableFeatures, CatalogService>>>(
    () => [
      {
        accessorKey: 'code',
        header: 'SKU',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums">{row.original.code}</span>
        ),
      },
      {
        accessorKey: 'name',
        header: 'Producto',
        cell: ({ row }) => (
          <div>
            <p className="font-medium">{row.original.name}</p>
            {row.original.description && (
              <p className="line-clamp-1 text-xs text-slate-500">{row.original.description}</p>
            )}
          </div>
        ),
      },
      {
        accessorKey: 'segment_name',
        header: 'Segmento',
        cell: ({ row }) => (
          <AppBadge label={row.original.segment_name || '—'} tone="info" />
        ),
      },
      {
        accessorKey: 'service_type',
        header: 'Tipo',
        cell: ({ row }) => TYPE_LABELS[row.original.service_type],
      },
      {
        accessorKey: 'unit_of_measure',
        header: 'Unidad',
        cell: ({ row }) => UNIT_LABELS[row.original.unit_of_measure],
      },
      {
        accessorKey: 'is_active',
        header: 'Estado',
        cell: ({ row }) => (
          <AppSwitch
            checked={row.original.is_active}
            caption={row.original.is_active ? 'Disponible' : 'Inactivo'}
            disabled={!canEdit('config_catalog')}
            onCheckedChange={(next) => {
              if (!canEdit('config_catalog')) return
              void (async () => {
                try {
                  await toggleService({ id: row.original.id, is_active: next })
                  toast.success(
                    next ? 'Producto activado' : 'Producto desactivado',
                    row.original.name,
                  )
                } catch (error) {
                  toast.apiError(error)
                }
              })()
            }}
          />
        ),
      },
      {
        id: 'actions',
        header: () => <AppActionsColumnHeader />,
        cell: ({ row }) => (
          <div className="flex justify-end">
            <AppRowActionsMenu
              actions={[
                {
                  label: 'Editar',
                  icon: <Pencil className="h-4 w-4" />,
                  onClick: () => openEdit(row.original),
                  hidden: !canEdit('config_catalog'),
                },
              ]}
            />
          </div>
        ),
      },
    ],
    [toggleService, toast, canEdit],
  )

  const table = useTable({
    features: catalogTableFeatures,
    data: items,
    columns,
  })

  function openCreate() {
    if (!canCreate('config_catalog')) return
    setEditing(null)
    form.reset({
      code: '',
      name: '',
      description: '',
      segment_id: String(lockedSegmentId ?? segments[0]?.id ?? 1),
      service_type: 'recurring',
      unit_of_measure: 'license',
      allow_package_conversion: false,
      package_service_id: '',
      package_factor: '5',
      is_active: true,
    })
    setModalOpen(true)
  }

  function openEdit(service: CatalogService) {
    if (!canEdit('config_catalog')) return
    setEditing(service)
    form.reset({
      code: service.code,
      name: service.name,
      description: service.description ?? '',
      segment_id: String(service.segment_id),
      service_type: service.service_type,
      unit_of_measure: service.unit_of_measure,
      allow_package_conversion: service.allow_package_conversion,
      package_service_id: service.package_service_id
        ? String(service.package_service_id)
        : '',
      package_factor: String(service.package_factor ?? 5),
      is_active: service.is_active,
    })
    setModalOpen(true)
  }

  async function onSubmit(values: FormValues) {
    const payload = {
      code: values.code,
      name: values.name,
      description: values.description?.trim() || null,
      segment_id: Number(values.segment_id),
      service_type: values.service_type,
      unit_of_measure: values.unit_of_measure,
      // RF-2 solo para Usuario individual; paquetes destino nunca convierten.
      allow_package_conversion:
        values.unit_of_measure === 'user' ? values.allow_package_conversion : false,
      package_service_id:
        values.unit_of_measure === 'user' && values.allow_package_conversion
          ? Number(values.package_service_id)
          : null,
      package_factor:
        values.unit_of_measure === 'user' && values.allow_package_conversion
          ? Math.min(100, Math.max(2, Number(values.package_factor) || 5))
          : 5,
      is_active: values.is_active,
    }
    try {
      if (editing) {
        await updateService({ id: editing.id, data: payload })
        toast.success('Producto actualizado', payload.name)
      } else {
        await createService(payload)
        toast.success('Producto creado', payload.name)
        setPage(1)
      }
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  const hasActiveFilters =
    search.trim() !== '' ||
    (!isSegmentLocked && segment !== 'Todos') ||
    serviceType !== 'all' ||
    status !== 'all'

  function clearFilters() {
    setSearch('')
    if (!isSegmentLocked) setSegment('Todos')
    setServiceType('all')
    setStatus('all')
    setPage(1)
  }

  if (isApiError && !items.length) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error al cargar</h2>
        <p className="mt-1 text-sm text-slate-500">{errorMessage}</p>
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
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">
            Catálogo de servicios
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Busca, filtra y pagina cientos de productos desde MySQL
          </p>
        </div>
        {canCreate('config_catalog') && (
          <AppButton onClick={openCreate}>
            <Plus className="h-4 w-4" /> Nuevo producto
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
        searchPlaceholder="Buscar por nombre, SKU o descripción…"
        searchLabel="BUSCAR"
        filters={[
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
          {
            id: 'type',
            label: 'TIPO',
            value: serviceType,
            onValueChange: (value) => {
              setServiceType(value)
              setPage(1)
            },
            options: TYPE_FILTERS,
          },
          {
            id: 'status',
            label: 'ESTADO',
            value: status,
            onValueChange: (value) => {
              setStatus(value)
              setPage(1)
            },
            options: STATUS_FILTERS,
          },
        ]}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      />

      {isLoading ? (
        <Skeleton className="h-64 w-full rounded-2xl" />
      ) : (
        <AppCard className="overflow-hidden p-0">
          {/* Desktop table */}
          <div className="hidden md:block overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                {table.getHeaderGroups().map((hg) => (
                  <tr
                    key={hg.id}
                    className="border-b border-slate-200/80 text-left text-[11px] uppercase tracking-wide text-slate-500 dark:border-white/[0.07]"
                  >
                    {hg.headers.map((h) => (
                      <th
                        key={h.id}
                        className={cn(
                          rowPaddingClass,
                          'font-medium',
                          h.column.id === 'actions' && 'text-right',
                        )}
                      >
                        {h.isPlaceholder ? null : <table.FlexRender header={h} />}
                      </th>
                    ))}
                  </tr>
                ))}
              </thead>
              <tbody>
                {table.getRowModel().rows.map((row) => (
                  <tr
                    key={row.id}
                    className={cn(
                      'border-b border-slate-100 dark:border-white/[0.04]',
                      !row.original.is_active && 'opacity-60',
                    )}
                  >
                    {row.getAllCells().map((cell) => (
                      <td
                        key={cell.id}
                        className={cn(
                          rowPaddingClass,
                          cell.column.id === 'actions' && 'text-right',
                        )}
                      >
                        <table.FlexRender cell={cell} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Mobile cards UI-4 */}
          <div className="space-y-3 p-4 md:hidden">
            {items.map((service) => (
              <AppCard key={service.id} className="p-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-mono text-xs tabular-nums text-slate-500">{service.code}</p>
                    <p className="font-heading font-semibold">{service.name}</p>
                    <p className="mt-1 text-xs text-slate-500">
                      {service.segment_name || '—'} · {TYPE_LABELS[service.service_type]}
                    </p>
                  </div>
                  <AppRowActionsMenu
                    actions={[
                      {
                        label: 'Editar',
                        icon: <Pencil className="h-4 w-4" />,
                        onClick: () => openEdit(service),
                        hidden: !canEdit('config_catalog'),
                      },
                    ]}
                  />
                </div>
                <div className="mt-3">
                  <AppSwitch
                    checked={service.is_active}
                    caption={service.is_active ? 'Disponible' : 'Inactivo'}
                    disabled={!canEdit('config_catalog') && !canDelete('config_catalog')}
                    onCheckedChange={(next) => {
                      if (!canEdit('config_catalog') && !canDelete('config_catalog')) return
                      void (async () => {
                        try {
                          await toggleService({ id: service.id, is_active: next })
                          toast.success(
                            next ? 'Producto activado' : 'Producto desactivado',
                            service.name,
                          )
                        } catch (error) {
                          toast.apiError(error)
                        }
                      })()
                    }}
                  />
                </div>
              </AppCard>
            ))}
          </div>

          {items.length === 0 && (
            <p className="p-6 text-sm text-slate-500">No hay productos con esos filtros.</p>
          )}

          <div className="px-4 pb-4">
            <PaginationBar
              page={page}
              total={total}
              pageSize={pageSize}
              onPageChange={setPage}
              onPageSizeChange={(n) => {
                setLocalPageSize(n)
                setPageSize(n)
                setPage(1)
              }}
            />
          </div>
        </AppCard>
      )}

      <AppModal open={modalOpen} onOpenChange={setModalOpen}>
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>{editing ? 'Editar producto' : 'Nuevo producto'}</AppModalTitle>
            <AppModalDescription>Se guarda en MySQL vía API real.</AppModalDescription>
          </AppModalHeader>
          <form className="space-y-3" onSubmit={form.handleSubmit(onSubmit)}>
            <label className="block text-sm">
              Código
              <AppInput withLabelSpacing mono {...form.register('code')} />
            </label>
            <label className="block text-sm">
              Nombre
              <AppInput withLabelSpacing {...form.register('name')} />
            </label>
            <label className="block text-sm">
              Descripción
              <AppTextarea withLabelSpacing rows={2} {...form.register('description')} />
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm">
                Segmento
                <AppSelect
                  withLabelSpacing
                  value={form.watch('segment_id')}
                  onValueChange={(v) =>
                    form.setValue('segment_id', v, { shouldValidate: true })
                  }
                  options={segments.map((s) => ({
                    value: String(s.id),
                    label: s.name,
                  }))}
                />
              </label>
              <label className="block text-sm">
                Tipo
                <AppSelect
                  withLabelSpacing
                  value={form.watch('service_type')}
                  onValueChange={(v) =>
                    form.setValue('service_type', v as FormValues['service_type'], {
                      shouldValidate: true,
                    })
                  }
                  options={[
                    { value: 'recurring', label: 'Recurrente' },
                    { value: 'consumption', label: 'Consumo' },
                    { value: 'single', label: 'Pago único' },
                  ]}
                />
              </label>
            </div>
            <label className="block text-sm">
              Unidad
              <AppSelect
                withLabelSpacing
                value={form.watch('unit_of_measure')}
                onValueChange={(v) => {
                  const next = v as FormValues['unit_of_measure']
                  form.setValue('unit_of_measure', next, { shouldValidate: true })
                  // Paquete destino / otras unidades: limpia vínculo RF-2.
                  if (next !== 'user') {
                    form.setValue('allow_package_conversion', false, { shouldValidate: true })
                    form.setValue('package_service_id', '', { shouldValidate: true })
                  }
                }}
                options={(Object.keys(UNIT_LABELS) as ServiceUnit[]).map((u) => ({
                  value: u,
                  label: UNIT_LABELS[u],
                }))}
              />
            </label>
            {isPackUnit ? (
              <p className="rounded-xl border border-[#0A84FF]/25 bg-[#0A84FF]/5 px-3 py-2 text-xs text-[#0066FF] dark:border-[#0A84FF]/30 dark:bg-[#0A84FF]/10 dark:text-[#0A84FF]">
                Este producto funcionará como paquete destino para la conversión automática de
                usuarios individuales (RF-2).
              </p>
            ) : null}
            {isUserUnit ? (
              <>
                <label className="flex items-center gap-3 text-sm">
                  <AppSwitch
                    checked={form.watch('allow_package_conversion')}
                    onCheckedChange={(next) =>
                      form.setValue('allow_package_conversion', next, { shouldValidate: true })
                    }
                    label="Agrupar automáticamente en paquetes"
                  />
                  Agrupar automáticamente en paquetes
                </label>
                {allowsPackage && (
                  <AppCard className="space-y-3 p-3">
                    <label className="block text-sm">
                      Producto paquete destino (RF-2)
                      <AppSelect
                        withLabelSpacing
                        placeholder="Selecciona un paquete…"
                        value={form.watch('package_service_id') || undefined}
                        onValueChange={(v) =>
                          form.setValue('package_service_id', v, { shouldValidate: true })
                        }
                        error={Boolean(form.formState.errors.package_service_id)}
                        options={packageOptions.map((pkg) => ({
                          value: String(pkg.id),
                          label: `${pkg.code} — ${pkg.name}`,
                        }))}
                      />
                      {form.formState.errors.package_service_id && (
                        <span className="mt-1 block text-xs text-red-500">
                          {form.formState.errors.package_service_id.message}
                        </span>
                      )}
                    </label>
                    <label className="block text-sm">
                      Factor de paquete
                      <AppInput
                        withLabelSpacing
                        mono
                        numericMode="integer"
                        {...form.register('package_factor')}
                      />
                      {form.formState.errors.package_factor && (
                        <span className="mt-1 block text-xs text-red-500">
                          {form.formState.errors.package_factor.message}
                        </span>
                      )}
                    </label>
                    <p className="rounded-xl border border-[#0A84FF]/25 bg-[#0A84FF]/5 px-3 py-2 text-xs text-[#0066FF] dark:border-[#0A84FF]/30 dark:bg-[#0A84FF]/10 dark:text-[#0A84FF]">
                      {`Cada ${
                        Number.isFinite(Number(watchedPackageFactor)) &&
                        Number(watchedPackageFactor) >= 2
                          ? Math.min(100, Math.trunc(Number(watchedPackageFactor)))
                          : 5
                      } usuarios individuales se convertirán automáticamente en 1 paquete destino.`}
                    </p>
                  </AppCard>
                )}
              </>
            ) : null}
            <label className="flex items-center gap-3 text-sm">
              <AppSwitch
                checked={form.watch('is_active')}
                onCheckedChange={(next) =>
                  form.setValue('is_active', next, { shouldValidate: true })
                }
                label="Disponible"
              />
              Disponible
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
