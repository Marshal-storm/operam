import { zodResolver } from '@hookform/resolvers/zod'
import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'

const instancesTableFeatures = tableFeatures({})
import { Pencil, Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppFilterField } from '@/components/ui/AppFilterField'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppInput, AppSearchInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppSelect, type AppSelectTone } from '@/components/ui/AppSelect'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
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
  tone: 'success' | 'warning' | 'danger'
}> = [
  { value: 'operational', label: 'Operativa', tone: 'success' },
  { value: 'maintenance', label: 'En mantenimiento', tone: 'warning' },
  { value: 'suspended', label: 'Suspendida', tone: 'danger' },
  { value: 'decommissioned', label: 'Baja', tone: 'danger' },
]

/** Semáforo del select: baja queda en neutro aunque la píldora sea peligro. */
const STATUS_SELECT_TONE: Record<InstanceStatus, AppSelectTone> = {
  operational: 'success',
  maintenance: 'warning',
  suspended: 'danger',
  decommissioned: 'neutral',
}

const formSchema = z.object({
  code: z.string().min(2).max(60),
  name: z.string().min(3).max(120),
  issuer_id: z.string().min(1),
  segment_id: z.string().min(1),
  access_url: z.string().optional(),
  server_ip: z.string().optional(),
  status: z.enum(['operational', 'maintenance', 'suspended', 'decommissioned']),
})

type FormValues = z.infer<typeof formSchema>

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
  const [issuerId, setIssuerId] = useState<number | null>(null)
  const { canCreate, canEdit } = usePermissions()
  const {
    segment,
    setSegment,
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
      issuer_id: issuerId,
      segment: segment === 'Todos' ? undefined : segment,
      status: estado === 'Todos' ? undefined : estado,
    }),
    [page, pageSize, search, issuerId, segment, estado],
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
      code: '',
      name: '',
      issuer_id: '1',
      segment_id: '1',
      access_url: '',
      server_ip: '',
      status: 'operational',
    },
  })

  const columns = useMemo<Array<ColumnDef<typeof instancesTableFeatures, CatalogInstance>>>(
    () => [
      {
        accessorKey: 'code',
        header: 'Código',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums">{row.original.code}</span>
        ),
      },
      {
        accessorKey: 'name',
        header: 'Instancia',
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
      },
      {
        accessorKey: 'issuer',
        header: 'Emisor',
      },
      {
        accessorKey: 'segment_name',
        header: 'Segmento',
        cell: ({ row }) => row.original.segment_name || '—',
      },
      {
        accessorKey: 'server_ip',
        header: 'IP',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums">
            {row.original.server_ip || '—'}
          </span>
        ),
      },
      {
        accessorKey: 'access_url',
        header: 'URL',
        cell: ({ row }) => (
          <span className="line-clamp-1 max-w-[12rem] font-mono text-xs">
            {row.original.access_url || '—'}
          </span>
        ),
      },
      {
        accessorKey: 'status',
        header: 'Estado',
        cell: ({ row }) => {
          const meta =
            STATUS_OPTIONS.find((s) => s.value === row.original.status) ?? STATUS_OPTIONS[0]
          return <AppBadge label={meta.label} tone={meta.tone} />
        },
      },
      {
        id: 'actions',
        header: 'Acciones',
        cell: ({ row }) =>
          canEdit('config_instances') ? (
            <AppButton
              type="button"
              variant="outline"
              size="sm"
              onClick={() => openEdit(row.original)}
            >
              <Pencil className="h-3.5 w-3.5" /> Editar
            </AppButton>
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
      code: '',
      name: '',
      issuer_id: String(emitters[0]?.id ?? 1),
      segment_id: String(lockedSegmentId ?? segments[0]?.id ?? 1),
      access_url: '',
      server_ip: '',
      status: 'operational',
    })
    setModalOpen(true)
  }

  function openEdit(row: CatalogInstance) {
    setEditing(row)
    form.reset({
      code: row.code,
      name: row.name,
      issuer_id: String(row.issuer_id),
      segment_id: String(row.segment_id),
      access_url: row.access_url ?? '',
      server_ip: row.server_ip ?? '',
      status: row.status,
    })
    setModalOpen(true)
  }

  async function onSubmit(values: FormValues) {
    const payload = {
      code: values.code,
      name: values.name,
      issuer_id: Number(values.issuer_id),
      segment_id: Number(values.segment_id),
      status: values.status,
      access_url: values.access_url?.trim() || null,
      server_ip: values.server_ip?.trim() || null,
    }
    try {
      if (editing) {
        await updateInstance({ id: editing.id, data: payload })
        toast.success('Instancia actualizada', `${payload.name} guardada correctamente.`)
      } else {
        await createInstance(payload)
        toast.success('Instancia creada', `${payload.name} quedó registrada.`)
      }
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (isApiError && !items.length) {
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
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Instancias</h1>
          <p className="mt-1 text-sm text-slate-500">
            Administración de servidores, VPS y entornos técnicos de clientes
          </p>
        </div>
        {canCreate('config_instances') && (
          <AppButton onClick={openCreate}>
            <Plus className="h-4 w-4" /> Nueva instancia
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
          placeholder="Buscar por nombre, código, IP o URL…"
          aria-label="Buscar instancias"
        />
        <div className="flex flex-wrap items-end gap-3">
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
          <AppFilterField label="Estado">
            <div className="ios-pill-track" role="group" aria-label="Estado">
              {(
                [
                  { value: 'Todos', label: 'Todos' },
                  { value: 'operational', label: 'Operativa' },
                  { value: 'maintenance', label: 'En mantenimiento' },
                  { value: 'suspended', label: 'Suspendida' },
                ] as const
              ).map((opt) => (
                <AppButton
                  key={opt.value}
                  type="button"
                  variant={estado === opt.value ? 'primary' : 'ghost'}
                  size="sm"
                  onClick={() => {
                    setEstado(opt.value)
                    setPage(1)
                  }}
                  className={cn(
                    'h-auto rounded-xl px-2.5 py-1 text-xs font-normal shadow-none',
                    estado === opt.value ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                  )}
                >
                  {opt.label}
                </AppButton>
              ))}
            </div>
          </AppFilterField>
          <AppFilterField label="Emisor">
            <div className="ios-pill-track" role="group" aria-label="Emisor">
              <AppButton
                type="button"
                variant={issuerId === null ? 'primary' : 'ghost'}
                size="sm"
                onClick={() => {
                  setIssuerId(null)
                  setPage(1)
                }}
                className={cn(
                  'h-auto rounded-xl px-2.5 py-1 text-xs font-normal shadow-none',
                  issuerId === null ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                )}
              >
                Todos
              </AppButton>
              {emitters.map((em) => {
                const shortLabel = em.name.split(/\s+/)[0] ?? em.name
                return (
                  <AppButton
                    key={em.id}
                    type="button"
                    variant={issuerId === em.id ? 'primary' : 'ghost'}
                    size="sm"
                    title={em.name}
                    onClick={() => {
                      setIssuerId(em.id)
                      setPage(1)
                    }}
                    className={cn(
                      'h-auto rounded-xl px-2.5 py-1 text-xs font-normal shadow-none',
                      issuerId === em.id
                        ? 'ios-pill-active'
                        : 'text-slate-600 dark:text-slate-300',
                    )}
                  >
                    {shortLabel}
                  </AppButton>
                )
              })}
            </div>
          </AppFilterField>
        </div>
      </AppCard>

      {isLoading ? (
        <Skeleton className="h-64 w-full rounded-2xl" />
      ) : (
        <AppCard className="overflow-hidden p-0">
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
                STATUS_OPTIONS.find((s) => s.value === row.status) ?? STATUS_OPTIONS[0]
              return (
                <AppCard key={row.id} className="p-4">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-heading font-semibold">{row.name}</p>
                      <p className="font-mono text-xs text-slate-500">{row.code}</p>
                      <p className="mt-1 text-xs text-slate-500">
                        {row.issuer} · {row.segment_name || '—'}
                      </p>
                    </div>
                    <AppBadge label={meta.label} tone={meta.tone} />
                  </div>
                  {canEdit('config_instances') && (
                    <AppButton
                      type="button"
                      variant="outline"
                      size="sm"
                      className="mt-3 w-full"
                      onClick={() => openEdit(row)}
                    >
                      <Pencil className="h-3.5 w-3.5" /> Editar
                    </AppButton>
                  )}
                </AppCard>
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
        </AppCard>
      )}

      <AppModal open={modalOpen} onOpenChange={setModalOpen}>
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>{editing ? 'Editar instancia' : 'Nueva instancia'}</AppModalTitle>
            <AppModalDescription>Vincula el entorno al emisor correcto.</AppModalDescription>
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
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm">
                Emisor asignado
                <AppSelect
                  withLabelSpacing
                  value={form.watch('issuer_id')}
                  onValueChange={(v) =>
                    form.setValue('issuer_id', v, { shouldValidate: true })
                  }
                  options={emitters.map((e) => ({
                    value: String(e.id),
                    label: e.name,
                  }))}
                />
              </label>
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
            </div>
            <label className="block text-sm">
              URL
              <AppInput withLabelSpacing {...form.register('access_url')} />
            </label>
            <label className="block text-sm">
              IP
              <AppInput withLabelSpacing mono {...form.register('server_ip')} />
            </label>
            <div className="block text-sm">
              <span className="mb-1 block">Estado</span>
              <AppSelect
                withLabelSpacing
                value={form.watch('status')}
                onValueChange={(v) =>
                  form.setValue('status', v as FormValues['status'], { shouldValidate: true })
                }
                options={STATUS_OPTIONS.map((s) => ({
                  value: s.value,
                  label: s.label,
                  tone: STATUS_SELECT_TONE[s.value],
                }))}
              />
            </div>
            <AppButton type="submit" className="w-full">
              {editing ? 'Guardar' : 'Registrar'}
            </AppButton>
          </form>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
