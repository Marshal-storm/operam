import { zodResolver } from '@hookform/resolvers/zod'
import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'

const catalogTableFeatures = tableFeatures({})
import { useQuery } from '@tanstack/react-query'
import { Pencil, Plus, Search } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import { fetchServices } from '@/services/catalogService'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { StatusPill } from '@/components/ui/StatusPill'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { useServicesConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { cn } from '@/lib/utils'
import type { CatalogService, ServiceType, ServiceUnit } from '@/types/catalog'

const UNIT_LABELS: Record<ServiceUnit, string> = {
  usuario: 'Usuario',
  paquete_5_usuarios: 'Paquete 5',
  timbre: 'Timbre',
  hora: 'Hora',
  licencia: 'Licencia',
  instancia: 'Instancia',
}

const TYPE_LABELS: Record<ServiceType, string> = {
  recurrente: 'Recurrente',
  consumo: 'Consumo',
  unico: 'Pago único',
}

const formSchema = z.object({
  codigo: z.string().min(2).max(40),
  nombre: z.string().min(3).max(120),
  descripcion: z.string().optional(),
  segmento_id: z.string().min(1),
  tipo: z.enum(['recurrente', 'consumo', 'unico']),
  unidad_medida: z.enum([
    'usuario',
    'paquete_5_usuarios',
    'timbre',
    'hora',
    'licencia',
    'instancia',
  ]),
  permite_conversion_paquete: z.boolean(),
  servicio_paquete_id: z.string().optional(),
  factor_paquete: z.string().regex(/^[1-9]\d*$/, 'Factor entero entre 1 y 100'),
  activo: z.boolean(),
}).superRefine((values, ctx) => {
  if (values.permite_conversion_paquete && !values.servicio_paquete_id) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      message: 'Selecciona el producto paquete destino (RF-2).',
      path: ['servicio_paquete_id'],
    })
  }
})

type FormValues = z.infer<typeof formSchema>

const inputClass =
  'mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#0066FF]/30 dark:border-white/[0.08] dark:bg-white/[0.05]'

/**
 * Catálogo de servicios — tabla paginada API + filtros (100+ productos).
 */
export function CatalogServicesPage() {
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const toast = useToast()
  const { canCreate, canEdit, canDelete } = usePermissions()
  const {
    segmento,
    setSegmento,
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
  const [tipo, setTipo] = useState('Todos')
  const [estado, setEstado] = useState('Todos')

  const queryParams = useMemo(
    () => ({
      page,
      per_page: pageSize,
      search: search.trim() || undefined,
      segmento: segmento === 'Todos' ? undefined : segmento,
      tipo:
        tipo === 'Todos'
          ? undefined
          : tipo === 'Pago único'
            ? 'unico'
            : tipo.toLowerCase(),
      estado:
        estado === 'Todos'
          ? undefined
          : estado === 'Disponibles'
            ? 'disponibles'
            : 'inactivos',
    }),
    [page, pageSize, search, segmento, tipo, estado],
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
      codigo: '',
      nombre: '',
      descripcion: '',
      segmento_id: '1',
      tipo: 'recurrente',
      unidad_medida: 'licencia',
      permite_conversion_paquete: false,
      servicio_paquete_id: '',
      factor_paquete: '5',
      activo: true,
    },
  })

  const allowsPackage = useWatch({
    control: form.control,
    name: 'permite_conversion_paquete',
  })
  const watchedSegmentoId = useWatch({
    control: form.control,
    name: 'segmento_id',
  })

  const packageOptionsQuery = useQuery({
    queryKey: ['config', 'services', 'packages', watchedSegmentoId],
    queryFn: () => fetchServices({ page: 1, per_page: 100, estado: 'disponibles' }),
    enabled: modalOpen && Boolean(allowsPackage),
    staleTime: 30_000,
  })

  const packageOptions = useMemo(() => {
    const rows = packageOptionsQuery.data?.items ?? []
    const segmentoId = Number(watchedSegmentoId)
    return rows.filter(
      (s) =>
        s.unidad_medida === 'paquete_5_usuarios' &&
        (!Number.isFinite(segmentoId) || s.segmento_id === segmentoId) &&
        (!editing || s.id !== editing.id),
    )
  }, [packageOptionsQuery.data?.items, watchedSegmentoId, editing])

  const columns = useMemo<Array<ColumnDef<typeof catalogTableFeatures, CatalogService>>>(
    () => [
      {
        accessorKey: 'codigo',
        header: 'SKU',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums">{row.original.codigo}</span>
        ),
      },
      {
        accessorKey: 'nombre',
        header: 'Producto',
        cell: ({ row }) => (
          <div>
            <p className="font-medium">{row.original.nombre}</p>
            {row.original.descripcion && (
              <p className="line-clamp-1 text-xs text-slate-500">{row.original.descripcion}</p>
            )}
          </div>
        ),
      },
      {
        accessorKey: 'segmento',
        header: 'Segmento',
        cell: ({ row }) => <StatusPill label={row.original.segmento} tone="info" />,
      },
      {
        accessorKey: 'tipo',
        header: 'Tipo',
        cell: ({ row }) => TYPE_LABELS[row.original.tipo],
      },
      {
        accessorKey: 'unidad_medida',
        header: 'Unidad',
        cell: ({ row }) => UNIT_LABELS[row.original.unidad_medida],
      },
      {
        accessorKey: 'activo',
        header: 'Estado',
        cell: ({ row }) => (
          <button
            type="button"
            disabled={!canEdit('config_catalogo') && !canDelete('config_catalogo')}
            onClick={() => {
              if (!canEdit('config_catalogo') && !canDelete('config_catalogo')) return
              void (async () => {
                try {
                  const next = !row.original.activo
                  await toggleService({ id: row.original.id, activo: next })
                  toast.success(
                    next ? 'Producto activado' : 'Producto desactivado',
                    row.original.nombre,
                  )
                } catch (error) {
                  toast.apiError(error)
                }
              })()
            }}
            className="flex items-center gap-2 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <span
              className={cn(
                'relative h-6 w-11 rounded-full transition',
                row.original.activo ? 'bg-[#34C759]' : 'bg-slate-300 dark:bg-white/20',
              )}
            >
              <span
                className={cn(
                  'absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition',
                  row.original.activo && 'translate-x-5',
                )}
              />
            </span>
            <span className="text-xs text-slate-500">
              {row.original.activo ? 'Disponible' : 'Inactivo'}
            </span>
          </button>
        ),
      },
      {
        id: 'actions',
        header: '',
        cell: ({ row }) =>
          canEdit('config_catalogo') ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="rounded-xl"
              onClick={() => openEdit(row.original)}
            >
              <Pencil className="mr-1 h-3.5 w-3.5" /> Editar
            </Button>
          ) : null,
      },
    ],
    [toggleService, toast, canEdit, canDelete],
  )

  const table = useTable({
    features: catalogTableFeatures,
    data: items,
    columns,
  })

  function openCreate() {
    if (!canCreate('config_catalogo')) return
    setEditing(null)
    form.reset({
      codigo: '',
      nombre: '',
      descripcion: '',
      segmento_id: String(lockedSegmentId ?? segments[0]?.id ?? 1),
      tipo: 'recurrente',
      unidad_medida: 'licencia',
      permite_conversion_paquete: false,
      servicio_paquete_id: '',
      factor_paquete: '5',
      activo: true,
    })
    setModalOpen(true)
  }

  function openEdit(service: CatalogService) {
    if (!canEdit('config_catalogo')) return
    setEditing(service)
    form.reset({
      codigo: service.codigo,
      nombre: service.nombre,
      descripcion: service.descripcion ?? '',
      segmento_id: String(service.segmento_id),
      tipo: service.tipo,
      unidad_medida: service.unidad_medida,
      permite_conversion_paquete: service.permite_conversion_paquete,
      servicio_paquete_id: service.servicio_paquete_id
        ? String(service.servicio_paquete_id)
        : '',
      factor_paquete: String(service.factor_paquete ?? 5),
      activo: service.activo,
    })
    setModalOpen(true)
  }

  async function onSubmit(values: FormValues) {
    const payload = {
      codigo: values.codigo,
      nombre: values.nombre,
      descripcion: values.descripcion?.trim() || null,
      segmento_id: Number(values.segmento_id),
      tipo: values.tipo,
      unidad_medida: values.unidad_medida,
      permite_conversion_paquete: values.permite_conversion_paquete,
      servicio_paquete_id: values.permite_conversion_paquete
        ? Number(values.servicio_paquete_id)
        : null,
      factor_paquete: values.permite_conversion_paquete
        ? Math.min(100, Math.max(1, Number(values.factor_paquete) || 5))
        : 5,
      activo: values.activo,
    }
    try {
      if (editing) {
        await updateService({ id: editing.id, data: payload })
        toast.success('Producto actualizado', payload.nombre)
      } else {
        await createService(payload)
        toast.success('Producto creado', payload.nombre)
      }
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  function resetFilters(partial: Record<string, string>) {
    if ('search' in partial) setSearch(partial.search!)
    if ('segmento' in partial) setSegmento(partial.segmento!)
    if ('tipo' in partial) setTipo(partial.tipo!)
    if ('estado' in partial) setEstado(partial.estado!)
    setPage(1)
  }

  if (isApiError && !items.length) {
    return (
      <section className="glass-panel border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error al cargar</h2>
        <p className="mt-1 text-sm text-slate-500">{errorMessage}</p>
        <Button className="mt-4 rounded-xl" onClick={() => void refetch()}>
          Reintentar
        </Button>
      </section>
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
        {canCreate('config_catalogo') && (
          <Button className="rounded-xl" onClick={openCreate}>
            <Plus className="mr-1 h-4 w-4" /> Nuevo producto
          </Button>
        )}
      </header>

      <div className="glass-panel space-y-3 p-4">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            placeholder="Buscar por nombre, SKU o descripción…"
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm outline-none focus:ring-2 focus:ring-[#0066FF]/30 dark:border-white/[0.08] dark:bg-white/[0.05]"
          />
        </div>
        <div className="flex flex-wrap gap-2">
          <FilterGroup
            label="Segmento"
            value={segmento}
            options={segmentOptions}
            onChange={(v) => {
              if (isSegmentLocked) return
              resetFilters({ segmento: v })
            }}
          />
          <FilterGroup
            label="Tipo"
            value={tipo}
            options={['Todos', 'Recurrente', 'Consumo', 'Pago único']}
            onChange={(v) => resetFilters({ tipo: v })}
          />
          <FilterGroup
            label="Estado"
            value={estado}
            options={['Todos', 'Disponibles', 'Inactivos']}
            onChange={(v) => resetFilters({ estado: v })}
          />
        </div>
      </div>

      {isLoading ? (
        <Skeleton className="h-64 w-full rounded-2xl" />
      ) : (
        <section className="glass-panel overflow-hidden p-0">
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
                      <th key={h.id} className={cn(rowPaddingClass, 'font-medium')}>
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
                      !row.original.activo && 'opacity-60',
                    )}
                  >
                    {row.getAllCells().map((cell) => (
                      <td key={cell.id} className={rowPaddingClass}>
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
              <article
                key={service.id}
                className="rounded-2xl border border-slate-200/80 p-4 dark:border-white/[0.08]"
              >
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-mono text-xs tabular-nums text-slate-500">{service.codigo}</p>
                    <p className="font-heading font-semibold">{service.nombre}</p>
                    <p className="mt-1 text-xs text-slate-500">
                      {service.segmento} · {TYPE_LABELS[service.tipo]}
                    </p>
                  </div>
                  <StatusPill
                    label={service.activo ? 'Disponible' : 'Inactivo'}
                    tone={service.activo ? 'positive' : 'neutral'}
                  />
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="mt-3 w-full rounded-xl"
                  onClick={() => openEdit(service)}
                >
                  Editar
                </Button>
              </article>
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
        </section>
      )}

      <Dialog open={modalOpen} onOpenChange={setModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? 'Editar producto' : 'Nuevo producto'}</DialogTitle>
            <DialogDescription>Se guarda en MySQL vía API real.</DialogDescription>
          </DialogHeader>
          <form className="space-y-3" onSubmit={form.handleSubmit(onSubmit)}>
            <label className="block text-sm">
              Código
              <input className={cn(inputClass, 'font-mono')} {...form.register('codigo')} />
            </label>
            <label className="block text-sm">
              Nombre
              <input className={inputClass} {...form.register('nombre')} />
            </label>
            <label className="block text-sm">
              Descripción
              <textarea className={inputClass} rows={2} {...form.register('descripcion')} />
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm">
                Segmento
                <select className={inputClass} {...form.register('segmento_id')}>
                  {segments.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.nombre}
                    </option>
                  ))}
                </select>
              </label>
              <label className="block text-sm">
                Tipo
                <select className={inputClass} {...form.register('tipo')}>
                  <option value="recurrente">Recurrente</option>
                  <option value="consumo">Consumo</option>
                  <option value="unico">Pago único</option>
                </select>
              </label>
            </div>
            <label className="block text-sm">
              Unidad
              <select className={inputClass} {...form.register('unidad_medida')}>
                {(Object.keys(UNIT_LABELS) as ServiceUnit[]).map((u) => (
                  <option key={u} value={u}>
                    {UNIT_LABELS[u]}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex items-center gap-3 text-sm">
              <input type="checkbox" {...form.register('permite_conversion_paquete')} />
              Agrupar automáticamente cada 5 usuarios
            </label>
            {allowsPackage && (
              <div className="space-y-3 rounded-2xl border border-slate-200/80 p-3 dark:border-white/[0.08]">
                <label className="block text-sm">
                  Producto paquete destino (RF-2)
                  <select className={inputClass} {...form.register('servicio_paquete_id')}>
                    <option value="">Selecciona un paquete…</option>
                    {packageOptions.map((pkg) => (
                      <option key={pkg.id} value={pkg.id}>
                        {pkg.codigo} — {pkg.nombre}
                      </option>
                    ))}
                  </select>
                  {form.formState.errors.servicio_paquete_id && (
                    <span className="mt-1 block text-xs text-red-500">
                      {form.formState.errors.servicio_paquete_id.message}
                    </span>
                  )}
                </label>
                <label className="block text-sm">
                  Factor de paquete
                  <input
                    type="number"
                    min={1}
                    max={100}
                    className={cn(inputClass, 'font-mono tabular-nums')}
                    {...form.register('factor_paquete')}
                  />
                </label>
              </div>
            )}
            <label className="flex items-center gap-3 text-sm">
              <input type="checkbox" {...form.register('activo')} /> Disponible
            </label>
            <Button type="submit" className="w-full rounded-xl">
              {editing ? 'Guardar' : 'Crear'}
            </Button>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function FilterGroup({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: string
  options: string[]
  onChange: (v: string) => void
}) {
  return (
    <div className="ios-pill-track" role="group" aria-label={label}>
      {options.map((opt) => (
        <button
          key={opt}
          type="button"
          onClick={() => onChange(opt)}
          className={cn(
            'rounded-xl px-2.5 py-1 text-xs transition',
            value === opt
              ? 'ios-pill-active'
              : 'text-slate-600 hover:bg-white/80 dark:text-slate-300 dark:hover:bg-white/5',
          )}
        >
          {opt}
        </button>
      ))}
    </div>
  )
}
