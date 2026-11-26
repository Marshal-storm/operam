import { zodResolver } from '@hookform/resolvers/zod'
import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'

const instancesTableFeatures = tableFeatures({})
import { AlertTriangle, Copy, KeyRound, Pencil, Plus, ShieldCheck } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppDrawer } from '@/components/ui/AppDrawer'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppSelect, type AppSelectTone } from '@/components/ui/AppSelect'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useLoadingLock } from '@/context/LoadingLockContext'
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
  const { withScreenLock } = useLoadingLock()
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
    rotateInstanceToken,
    isRotatingToken,
  } = useInstancesConfig(params)

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<CatalogInstance | null>(null)
  const [tokenModalOpen, setTokenModalOpen] = useState(false)
  const [tokenTarget, setTokenTarget] = useState<CatalogInstance | null>(null)
  const [tokenPlain, setTokenPlain] = useState<string | null>(null)
  const [confirmPhrase, setConfirmPhrase] = useState('')
  const isConfirmPhraseValid = confirmPhrase.trim().toUpperCase() === 'SI ACEPTO'

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
        header: () => <AppActionsColumnHeader />,
        cell: ({ row }) => (
          <div className="flex justify-end">
            <AppRowActionsMenu
              actions={[
                {
                  label: 'Editar',
                  icon: <Pencil className="h-4 w-4" />,
                  onClick: () => openEdit(row.original),
                  hidden: !canEdit('config_instances'),
                },
                {
                  label: 'Generar / rotar token API',
                  icon: <KeyRound className="h-4 w-4" />,
                  onClick: () => openRotateTokenModal(row.original),
                  hidden: !canEdit('config_instances'),
                },
              ]}
            />
          </div>
        ),
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

  function openRotateTokenModal(row: CatalogInstance) {
    setTokenTarget(row)
    setTokenPlain(null)
    setConfirmPhrase('')
    setTokenModalOpen(true)
  }

  function closeRotateTokenModal() {
    setTokenModalOpen(false)
    setTokenPlain(null)
    setConfirmPhrase('')
    setTokenTarget(null)
  }

  async function handleConfirmRotateToken() {
    if (!tokenTarget || !isConfirmPhraseValid) return
    try {
      const result = await withScreenLock(
        () => rotateInstanceToken(tokenTarget.id),
        'Generando nuevo token API...',
      )
      setTokenPlain(result.api_token)
      setConfirmPhrase('')
      toast.success('Token API rotado correctamente')
      void refetch()
    } catch (error) {
      toast.apiError(error)
    }
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
        setPage(1)
      }
      setModalOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  const issuerFilterValue = issuerId == null ? 'Todos' : String(issuerId)

  const hasActiveFilters =
    search.trim() !== '' ||
    (!isSegmentLocked && segment !== 'Todos') ||
    estado !== 'Todos' ||
    issuerId != null

  function clearFilters() {
    setSearch('')
    if (!isSegmentLocked) setSegment('Todos')
    setEstado('Todos')
    setIssuerId(null)
    setPage(1)
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
        searchPlaceholder="Buscar por nombre, código, IP o URL…"
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
            id: 'status',
            label: 'ESTADO',
            value: estado,
            onValueChange: (value) => {
              setEstado(value)
              setPage(1)
            },
            options: [
              { value: 'Todos', label: 'Todos' },
              { value: 'operational', label: 'Operativa', tone: 'success' },
              { value: 'maintenance', label: 'En mantenimiento', tone: 'warning' },
              { value: 'suspended', label: 'Suspendida', tone: 'danger' },
            ],
          },
          {
            id: 'issuer',
            label: 'EMISOR',
            value: issuerFilterValue,
            onValueChange: (value) => {
              setIssuerId(value === 'Todos' ? null : Number(value) || null)
              setPage(1)
            },
            options: [
              { value: 'Todos', label: 'Todos' },
              ...emitters.map((em) => ({
                value: String(em.id),
                label: em.name,
              })),
            ],
          },
        ]}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      />

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
                    className="border-b border-slate-100 dark:border-white/[0.04]"
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
                    <div className="flex shrink-0 flex-col items-end gap-2">
                      <AppBadge label={meta.label} tone={meta.tone} />
                      <AppRowActionsMenu
                        actions={[
                          {
                            label: 'Editar',
                            icon: <Pencil className="h-4 w-4" />,
                            onClick: () => openEdit(row),
                            hidden: !canEdit('config_instances'),
                          },
                          {
                            label: 'Generar / rotar token API',
                            icon: <KeyRound className="h-4 w-4" />,
                            onClick: () => openRotateTokenModal(row),
                            hidden: !canEdit('config_instances'),
                          },
                        ]}
                      />
                    </div>
                  </div>
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

      <AppDrawer
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        size="lg"
        title={editing ? 'Editar instancia' : 'Nueva instancia'}
        description="Vincula el entorno al emisor correcto."
        footer={
          <>
            <AppButton
              type="button"
              variant="outline"
              onClick={() => setModalOpen(false)}
            >
              Cancelar
            </AppButton>
            <AppButton type="submit" form="instance-form">
              {editing ? 'Guardar cambios' : 'Registrar instancia'}
            </AppButton>
          </>
        }
      >
        <form
          id="instance-form"
          className="min-w-0 space-y-4"
          onSubmit={form.handleSubmit(onSubmit)}
        >
          <label className="block min-w-0 text-sm">
            Código
            <AppInput withLabelSpacing mono {...form.register('code')} />
          </label>
          <label className="block min-w-0 text-sm">
            Nombre
            <AppInput withLabelSpacing {...form.register('name')} />
          </label>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block min-w-0 text-sm">
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
            <label className="block min-w-0 text-sm">
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
          <label className="block min-w-0 text-sm">
            URL
            <AppInput withLabelSpacing {...form.register('access_url')} />
          </label>
          <label className="block min-w-0 text-sm">
            IP
            <AppInput withLabelSpacing mono {...form.register('server_ip')} />
          </label>
          <div className="block min-w-0 text-sm">
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
        </form>
      </AppDrawer>

      <AppModal
        open={tokenModalOpen}
        onOpenChange={(open) => {
          if (!open) {
            closeRotateTokenModal()
          } else {
            setTokenModalOpen(true)
          }
        }}
      >
        <AppModalContent className="max-w-lg">
          {tokenPlain === null ? (
            <>
              <AppModalHeader>
                <AppModalTitle>Confirmar generación de token API</AppModalTitle>
                <AppModalDescription>
                  Verifica el impacto operativo antes de emitir una nueva credencial Bearer (RNF-8).
                </AppModalDescription>
              </AppModalHeader>
              <div className="space-y-4">
                {tokenTarget ? (
                  <div className="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-slate-200/80 bg-slate-50/80 px-4 py-3 dark:border-white/[0.09] dark:bg-white/[0.03]">
                    <div>
                      <p className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                        {tokenTarget.name}
                      </p>
                      <p className="font-mono text-xs tabular-nums text-slate-500 dark:text-zinc-400">
                        {tokenTarget.code}
                      </p>
                    </div>
                    {tokenTarget.has_api_token || tokenTarget.api_token_last4 ? (
                      <AppBadge
                        label={`Token activo: ****${tokenTarget.api_token_last4 ?? '••••'}`}
                        tone="warning"
                      />
                    ) : (
                      <AppBadge label="Sin token activo" tone="neutral" />
                    )}
                  </div>
                ) : null}

                <div className="space-y-2.5 rounded-2xl border border-amber-500/35 bg-amber-500/10 px-4 py-3 text-xs leading-relaxed text-amber-900 dark:border-red-500/35 dark:bg-red-500/10 dark:text-amber-100">
                  <div className="flex items-start gap-2.5">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div className="space-y-2">
                      {tokenTarget?.has_api_token || tokenTarget?.api_token_last4 ? (
                        <p>
                          Al generar un nuevo token, el token anterior (
                          <span className="font-mono font-semibold">
                            ****{tokenTarget?.api_token_last4 ?? '••••'}
                          </span>
                          ) dejará de funcionar de inmediato (HTTP 401). Deberás actualizar el nuevo
                          token en la configuración del ERP del cliente para restablecer la
                          comunicación de saldos y consumos.
                        </p>
                      ) : (
                        <p>
                          Se emitirá un nuevo token Bearer para esta instancia. Deberás configurarlo
                          en el ERP del cliente para habilitar la comunicación de saldos y consumos.
                        </p>
                      )}
                      <p className="flex items-start gap-1.5 text-[11px] text-slate-700 dark:text-zinc-300">
                        <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <span>
                          Mientras actualizas el token, la instancia del cliente podrá seguir
                          timbrando temporalmente con su último saldo en caché.
                        </span>
                      </p>
                    </div>
                  </div>
                </div>

                <label className="block text-sm font-medium text-slate-800 dark:text-zinc-200">
                  Escribe &quot;SI ACEPTO&quot; para confirmar la generación del nuevo token
                  <AppInput
                    withLabelSpacing
                    mono
                    autoComplete="off"
                    placeholder="SI ACEPTO"
                    value={confirmPhrase}
                    onChange={(event) => setConfirmPhrase(event.target.value)}
                  />
                </label>

                <div className="flex flex-wrap justify-end gap-2 pt-1">
                  <AppButton
                    type="button"
                    variant="ghost"
                    onClick={closeRotateTokenModal}
                    disabled={isRotatingToken}
                  >
                    Cancelar
                  </AppButton>
                  <AppButton
                    type="button"
                    variant={
                      tokenTarget?.has_api_token || tokenTarget?.api_token_last4
                        ? 'danger'
                        : 'primary'
                    }
                    disabled={!isConfirmPhraseValid || isRotatingToken}
                    isLoading={isRotatingToken}
                    onClick={() => void handleConfirmRotateToken()}
                  >
                    Confirmar y generar nuevo token
                  </AppButton>
                </div>
              </div>
            </>
          ) : (
            <>
              <AppModalHeader>
                <AppModalTitle>Token API generado</AppModalTitle>
                <AppModalDescription>
                  {tokenTarget
                    ? `Bearer generado para ${tokenTarget.name} (${tokenTarget.code}). Guárdalo en un lugar seguro (RNF-8).`
                    : 'Bearer generado. Guárdalo en un lugar seguro (RNF-8).'}
                </AppModalDescription>
              </AppModalHeader>
              <div className="space-y-4">
                <p className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">
                  Este token solo se muestra una vez y no volverá a mostrarse al cerrar esta ventana.
                  No se almacena en texto plano en Operam.
                </p>
                <AppInput
                  mono
                  readOnly
                  value={tokenPlain}
                  aria-label="Token API generado"
                />
                <div className="flex justify-end gap-2">
                  <AppButton
                    type="button"
                    variant="ghost"
                    onClick={closeRotateTokenModal}
                  >
                    Cerrar
                  </AppButton>
                  <AppButton
                    type="button"
                    onClick={async () => {
                      if (!tokenPlain) return
                      try {
                        await navigator.clipboard.writeText(tokenPlain)
                        toast.info('Token copiado al portapapeles')
                      } catch (error) {
                        toast.apiError(error)
                      }
                    }}
                  >
                    <Copy className="mr-1.5 h-4 w-4" />
                    Copiar token
                  </AppButton>
                </div>
              </div>
            </>
          )}
        </AppModalContent>
      </AppModal>
    </div>
  )
}
