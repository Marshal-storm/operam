import { zodResolver } from '@hookform/resolvers/zod'
import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'

const instancesTableFeatures = tableFeatures({})
import { Pencil, Plus, Search } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
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
import { useInstancesConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegmentLock } from '@/hooks/useSegmentLock'
import { cn } from '@/lib/utils'
import type { CatalogInstance, InstanceStatus } from '@/types/catalog'

const STATUS_OPTIONS: Array<{
  value: InstanceStatus
  label: string
  tone: 'positive' | 'warning' | 'negative'
}> = [
  { value: 'operativa', label: 'Operativa', tone: 'positive' },
  { value: 'mantenimiento', label: 'En mantenimiento', tone: 'warning' },
  { value: 'suspendida', label: 'Suspendida', tone: 'negative' },
  { value: 'baja', label: 'Baja', tone: 'negative' },
]

const STATUS_SELECT_CLASS: Record<InstanceStatus, string> = {
  operativa:
    'border-emerald-500/30 bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
  mantenimiento:
    'border-amber-500/30 bg-amber-500/15 text-amber-700 dark:text-amber-400',
  suspendida: 'border-red-500/30 bg-red-500/15 text-red-700 dark:text-red-400',
  baja: 'border-red-500/30 bg-red-500/15 text-red-700 dark:text-red-400',
}

const formSchema = z.object({
  codigo: z.string().min(2).max(60),
  nombre: z.string().min(3).max(120),
  emisor_id: z.string().min(1),
  segmento_id: z.string().min(1),
  url_acceso: z.string().optional(),
  ip_servidor: z.string().optional(),
  estado: z.enum(['operativa', 'mantenimiento', 'suspendida', 'baja']),
})

type FormValues = z.infer<typeof formSchema>

const inputClass =
  'mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#0066FF]/30 dark:border-white/[0.08] dark:bg-white/[0.05]'

/**
 * Instancias — administración de servidores / VPS (sin CRUD de emisores).
 */
export function InstancesPage() {
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  useEffect(() => {
    setLocalPageSize(preferredSize)
    setPage(1)
  }, [preferredSize])
  const [search, setSearch] = useState('')
  const [emisorId, setEmisorId] = useState<number | null>(null)
  const { canCreate, canEdit } = usePermissions()
  const {
    segmento,
    setSegmento,
    segmentOptions,
    isSegmentLocked,
    lockedSegmentId,
  } = useSegmentLock()
  const [estado, setEstado] = useState('Todos')

  const params = useMemo(
    () => ({
      page,
      per_page: pageSize,
      search: search.trim() || undefined,
      emisor_id: emisorId,
      segmento: segmento === 'Todos' ? undefined : segmento,
      estado: estado === 'Todos' ? undefined : estado,
    }),
    [page, pageSize, search, emisorId, segmento, estado],
  )

  const {
    items,
    total,
    emitters,
    segments,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createInstance,
    updateInstance,
  } = useInstancesConfig(params)

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<CatalogInstance | null>(null)

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      codigo: '',
      nombre: '',
      emisor_id: '1',
      segmento_id: '1',
      url_acceso: '',
      ip_servidor: '',
      estado: 'operativa',
    },
  })
  const watchedEstado = form.watch('estado') as InstanceStatus

  const columns = useMemo<Array<ColumnDef<typeof instancesTableFeatures, CatalogInstance>>>(
    () => [
      {
        accessorKey: 'codigo',
        header: 'Código',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums">{row.original.codigo}</span>
        ),
      },
      {
        accessorKey: 'nombre',
        header: 'Instancia',
        cell: ({ row }) => <span className="font-medium">{row.original.nombre}</span>,
      },
      {
        accessorKey: 'emisor',
        header: 'Emisor',
      },
      {
        accessorKey: 'segmento',
        header: 'Segmento',
      },
      {
        accessorKey: 'ip_servidor',
        header: 'IP',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums">
            {row.original.ip_servidor || '—'}
          </span>
        ),
      },
      {
        accessorKey: 'url_acceso',
        header: 'URL',
        cell: ({ row }) => (
          <span className="line-clamp-1 max-w-[12rem] font-mono text-xs">
            {row.original.url_acceso || '—'}
          </span>
        ),
      },
      {
        accessorKey: 'estado',
        header: 'Estado',
        cell: ({ row }) => {
          const meta =
            STATUS_OPTIONS.find((s) => s.value === row.original.estado) ?? STATUS_OPTIONS[0]
          return <StatusPill label={meta.label} tone={meta.tone} />
        },
      },
      {
        id: 'actions',
        header: 'Acciones',
        cell: ({ row }) =>
          canEdit('config_instancias') ? (
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [canEdit],
  )

  const table = useTable({
    features: instancesTableFeatures,
    data: items,
    columns,
  })

  function openCreate() {
    setEditing(null)
    form.reset({
      codigo: '',
      nombre: '',
      emisor_id: String(emitters[0]?.id ?? 1),
      segmento_id: String(lockedSegmentId ?? segments[0]?.id ?? 1),
      url_acceso: '',
      ip_servidor: '',
      estado: 'operativa',
    })
    setModalOpen(true)
  }

  function openEdit(row: CatalogInstance) {
    setEditing(row)
    form.reset({
      codigo: row.codigo,
      nombre: row.nombre,
      emisor_id: String(row.emisor_id),
      segmento_id: String(row.segmento_id),
      url_acceso: row.url_acceso ?? '',
      ip_servidor: row.ip_servidor ?? '',
      estado: row.estado,
    })
    setModalOpen(true)
  }

  async function onSubmit(values: FormValues) {
    const payload = {
      codigo: values.codigo,
      nombre: values.nombre,
      emisor_id: Number(values.emisor_id),
      segmento_id: Number(values.segmento_id),
      estado: values.estado,
      url_acceso: values.url_acceso?.trim() || null,
      ip_servidor: values.ip_servidor?.trim() || null,
    }
    try {
      if (editing) {
        await updateInstance({ id: editing.id, data: payload })
        toast.success('Instancia actualizada', `${payload.nombre} guardada correctamente.`)
      } else {
        await createInstance(payload)
        toast.success('Instancia creada', `${payload.nombre} quedó registrada.`)
      }
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (isApiError && !items.length) {
    return (
      <section className="glass-panel border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error</h2>
        <p className="text-sm text-slate-500">{errorMessage}</p>
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
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Instancias</h1>
          <p className="mt-1 text-sm text-slate-500">
            Administración de servidores, VPS y entornos técnicos de clientes
          </p>
        </div>
        {canCreate('config_instancias') && (
          <Button className="rounded-xl" onClick={openCreate}>
            <Plus className="mr-1 h-4 w-4" /> Nueva instancia
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
            placeholder="Buscar por nombre, código, IP o URL…"
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
          />
        </div>
        <div className="flex flex-wrap gap-2">
          <div className="ios-pill-track">
            {segmentOptions.map((opt) => (
              <button
                key={opt}
                type="button"
                disabled={isSegmentLocked}
                onClick={() => {
                  if (isSegmentLocked) return
                  setSegmento(opt)
                  setPage(1)
                }}
                className={cn(
                  'rounded-xl px-2.5 py-1 text-xs',
                  segmento === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                  isSegmentLocked && 'cursor-not-allowed opacity-80',
                )}
              >
                {opt}
              </button>
            ))}
          </div>
          <div className="ios-pill-track">
            {['Todos', 'operativa', 'mantenimiento', 'suspendida'].map((opt) => (
              <button
                key={opt}
                type="button"
                onClick={() => {
                  setEstado(opt)
                  setPage(1)
                }}
                className={cn(
                  'rounded-xl px-2.5 py-1 text-xs capitalize',
                  estado === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                )}
              >
                {opt === 'Todos' ? 'Todos' : opt}
              </button>
            ))}
          </div>
          <div className="ios-pill-track">
            <button
              type="button"
              onClick={() => {
                setEmisorId(null)
                setPage(1)
              }}
              className={cn(
                'rounded-xl px-2.5 py-1 text-xs',
                emisorId === null ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
              )}
            >
              Todos los emisores
            </button>
            {emitters.map((em) => {
              const shortLabel = em.nombre.split(/\s+/)[0] ?? em.nombre
              return (
                <button
                  key={em.id}
                  type="button"
                  title={em.nombre}
                  onClick={() => {
                    setEmisorId(em.id)
                    setPage(1)
                  }}
                  className={cn(
                    'rounded-xl px-2.5 py-1 text-xs',
                    emisorId === em.id
                      ? 'ios-pill-active'
                      : 'text-slate-600 dark:text-slate-300',
                  )}
                >
                  {shortLabel}
                </button>
              )
            })}
          </div>
        </div>
      </div>

      {isLoading ? (
        <Skeleton className="h-64 w-full rounded-2xl" />
      ) : (
        <section className="glass-panel overflow-hidden p-0">
          <div className="hidden overflow-x-auto lg:block">
            <table className="w-full text-sm">
              <thead>
                {table.getHeaderGroups().map((hg) => (
                  <tr
                    key={hg.id}
                    className="border-b border-slate-200/80 text-left text-[11px] uppercase text-slate-500 dark:border-white/[0.07]"
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
                    className="border-b border-slate-100 dark:border-white/[0.04]"
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

          <div className="space-y-3 p-4 lg:hidden">
            {items.map((row) => {
              const meta =
                STATUS_OPTIONS.find((s) => s.value === row.estado) ?? STATUS_OPTIONS[0]
              return (
                <article
                  key={row.id}
                  className="rounded-2xl border border-slate-200/80 p-4 dark:border-white/[0.08]"
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-heading font-semibold">{row.nombre}</p>
                      <p className="font-mono text-xs text-slate-500">{row.codigo}</p>
                      <p className="mt-1 text-xs text-slate-500">
                        {row.emisor} · {row.segmento}
                      </p>
                    </div>
                    <StatusPill label={meta.label} tone={meta.tone} />
                  </div>
                  {canEdit('config_instancias') && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="mt-3 w-full rounded-xl"
                      onClick={() => openEdit(row)}
                    >
                      <Pencil className="mr-1 h-3.5 w-3.5" /> Editar
                    </Button>
                  )}
                </article>
              )
            })}
          </div>

          {items.length === 0 && (
            <p className="p-6 text-sm text-slate-500">Sin instancias con esos filtros.</p>
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
            <DialogTitle>{editing ? 'Editar instancia' : 'Nueva instancia'}</DialogTitle>
            <DialogDescription>Vincula el entorno al emisor correcto.</DialogDescription>
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
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm">
                Emisor asignado
                <select className={inputClass} {...form.register('emisor_id')}>
                  {emitters.map((e) => (
                    <option key={e.id} value={e.id}>
                      {e.nombre}
                    </option>
                  ))}
                </select>
              </label>
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
            </div>
            <label className="block text-sm">
              URL
              <input className={inputClass} {...form.register('url_acceso')} />
            </label>
            <label className="block text-sm">
              IP
              <input className={cn(inputClass, 'font-mono')} {...form.register('ip_servidor')} />
            </label>
            <div className="block text-sm">
              <span className="mb-1 block">Estado</span>
              <select
                className={cn(
                  'mt-1 w-full rounded-xl border px-3 py-2 text-sm font-medium outline-none focus:ring-2 focus:ring-[#0066FF]/30',
                  STATUS_SELECT_CLASS[watchedEstado],
                )}
                {...form.register('estado')}
              >
                {STATUS_OPTIONS.filter((s) => s.value !== 'baja').map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </select>
            </div>
            <Button type="submit" className="w-full rounded-xl">
              {editing ? 'Guardar' : 'Registrar'}
            </Button>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
