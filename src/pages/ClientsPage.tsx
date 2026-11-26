import { zodResolver } from '@hookform/resolvers/zod'
import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'
import {
  Eye,
  FilePlus2,
  KeyRound,
  Pencil,
  Plus,
  Users,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { ClientContactsTab } from '@/components/clients/ClientContactsTab'
import { ClientMagicLinkModal } from '@/components/clients/ClientMagicLinkModal'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import {
  AppCard,
  AppCardContent,
  AppCardHeader,
  AppCardTitle,
} from '@/components/ui/AppCard'
import { AppDrawer } from '@/components/ui/AppDrawer'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppInput } from '@/components/ui/AppInput'
import {
  AppActionsColumnHeader,
  AppRowActionsMenu,
} from '@/components/ui/AppRowActionsMenu'
import { AppSelect } from '@/components/ui/AppSelect'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { AppTabs } from '@/components/ui/AppTabs'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { useClientContacts } from '@/hooks/useClientContacts'
import { useClients } from '@/hooks/useClients'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegments } from '@/hooks/useSegments'
import { cn } from '@/lib/utils'
import type { Client } from '@/types/client'

const clientsTableFeatures = tableFeatures({})

const clientFormSchema = z.object({
  name: z.string().min(2, 'Indica el nombre comercial').max(150),
  legal_name: z.string().min(2, 'Indica la razón social').max(200),
  rfc: z
    .string()
    .trim()
    .transform((v) => v.toUpperCase())
    .pipe(
      z
        .string()
        .regex(
          /^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/,
          'RFC inválido (12 o 13 caracteres, formato SAT)',
        ),
    ),
  erp_client_id: z.string().max(64).optional(),
  contact_name: z.string().max(120).optional(),
  contact_email: z
    .string()
    .email('Correo inválido')
    .optional()
    .or(z.literal('')),
  contact_phone: z.string().max(30).optional(),
  segment_id: z.string().min(1, 'Selecciona un segmento'),
  issuer_id: z.string().min(1, 'Selecciona un emisor'),
  credit_days: z
    .number({ error: 'Indica los días de crédito' })
    .int('Los días de crédito deben ser un entero')
    .min(0, 'Los días de crédito deben ser ≥ 0'),
  stamp_on_issue: z.boolean(),
  exclude_suspension: z.boolean(),
  active: z.boolean(),
})

type ClientFormValues = z.infer<typeof clientFormSchema>

const EMPTY_FORM: ClientFormValues = {
  name: '',
  legal_name: '',
  rfc: '',
  erp_client_id: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  segment_id: '',
  issuer_id: '',
  credit_days: 30,
  stamp_on_issue: false,
  exclude_suspension: false,
  active: true,
}

/**
 * Directorio y CRUD de Clientes — Módulo 3 Paso 1 (`/clientes`).
 * Formularios y vista de detalle en AppDrawer (§3.1) + modal rápido de Liga Mágica.
 */
export function ClientsPage() {
  const navigate = useNavigate()
  const toast = useToast()
  const { canCreate, canEdit } = usePermissions()
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const {
    segments,
    filterOptions: segmentFilterOptions,
    isSegmentLocked,
    lockedSegmentId,
    allowedSegment,
  } = useSegments()

  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  const [search, setSearch] = useState('')
  const [segmentFilter, setSegmentFilter] = useState(
    isSegmentLocked ? allowedSegment : 'Todos',
  )
  const [issuerFilter, setIssuerFilter] = useState('Todos')
  const [statusFilter, setStatusFilter] = useState('Todos')

  const [formDrawerOpen, setFormDrawerOpen] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailTab, setDetailTab] = useState<'general' | 'contacts'>('general')
  const [editing, setEditing] = useState<Client | null>(null)
  const [detailClient, setDetailClient] = useState<Client | null>(null)
  const [magicLinkClient, setMagicLinkClient] = useState<Client | null>(null)
  const [creditInheritedNote, setCreditInheritedNote] = useState<string | null>(null)

  const { contacts: detailContacts } = useClientContacts(
    detailOpen && detailClient ? detailClient.id : null,
  )

  useEffect(() => {
    setLocalPageSize(preferredSize)
    setPage(1)
  }, [preferredSize])

  useEffect(() => {
    if (isSegmentLocked) setSegmentFilter(allowedSegment)
  }, [isSegmentLocked, allowedSegment])

  const selectedSegmentId = useMemo(() => {
    if (isSegmentLocked && lockedSegmentId != null) return lockedSegmentId
    if (segmentFilter === 'Todos') return undefined
    const match = segments.find((s) => s.name === segmentFilter)
    return match?.id
  }, [isSegmentLocked, lockedSegmentId, segmentFilter, segments])

  const params = useMemo(
    () => ({
      page,
      per_page: pageSize,
      search: search.trim() || undefined,
      segment_id: selectedSegmentId,
      issuer_id:
        issuerFilter === 'Todos' ? undefined : Number(issuerFilter) || undefined,
      active:
        statusFilter === 'Activos'
          ? true
          : statusFilter === 'Inactivos'
            ? false
            : undefined,
    }),
    [page, pageSize, search, selectedSegmentId, issuerFilter, statusFilter],
  )

  const {
    items,
    total,
    summary,
    issuers,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createClient,
    updateClient,
    toggleClient,
  } = useClients(params)

  const form = useForm<ClientFormValues>({
    resolver: zodResolver(clientFormSchema),
    defaultValues: EMPTY_FORM,
  })

  const watchedSegmentId = useWatch({ control: form.control, name: 'segment_id' })

  useEffect(() => {
    if (editing || !formDrawerOpen) return
    if (!watchedSegmentId) {
      setCreditInheritedNote(null)
      return
    }
    const segment = segments.find((s) => String(s.id) === watchedSegmentId)
    if (!segment) return
    form.setValue('credit_days', segment.default_credit_days, { shouldDirty: true })
    setCreditInheritedNote(`Heredado de ${segment.name}, editable`)
  }, [watchedSegmentId, editing, formDrawerOpen, segments, form])

  const hasActiveFilters =
    search.trim() !== '' ||
    (!isSegmentLocked && segmentFilter !== 'Todos') ||
    issuerFilter !== 'Todos' ||
    statusFilter !== 'Todos'

  const issuerOptions = useMemo(
    () => [
      { value: 'Todos', label: 'Todos' },
      ...issuers.map((issuer) => ({
        value: String(issuer.id),
        label: issuer.name,
      })),
    ],
    [issuers],
  )

  const segmentFormOptions = useMemo(
    () =>
      segments.map((segment) => ({
        value: String(segment.id),
        label: segment.name,
      })),
    [segments],
  )

  const issuerFormOptions = useMemo(
    () =>
      issuers
        .filter((issuer) => issuer.is_active || (editing && editing.issuer_id === issuer.id))
        .map((issuer) => ({
          value: String(issuer.id),
          label: issuer.name,
        })),
    [issuers, editing],
  )

  function clearFilters() {
    setSearch('')
    setSegmentFilter(isSegmentLocked ? allowedSegment : 'Todos')
    setIssuerFilter('Todos')
    setStatusFilter('Todos')
    setPage(1)
  }

  function openCreate() {
    if (!canCreate('clients')) return
    setEditing(null)
    setCreditInheritedNote(null)
    const defaultSegment =
      isSegmentLocked && lockedSegmentId != null
        ? String(lockedSegmentId)
        : segments[0]
          ? String(segments[0].id)
          : ''
    const defaultDays =
      segments.find((s) => String(s.id) === defaultSegment)?.default_credit_days ?? 30
    form.reset({
      ...EMPTY_FORM,
      segment_id: defaultSegment,
      issuer_id: issuers.find((i) => i.is_active)?.id
        ? String(issuers.find((i) => i.is_active)!.id)
        : '',
      credit_days: defaultDays,
    })
    if (defaultSegment) {
      const seg = segments.find((s) => String(s.id) === defaultSegment)
      if (seg) setCreditInheritedNote(`Heredado de ${seg.name}, editable`)
    }
    setFormDrawerOpen(true)
  }

  function openEdit(client: Client) {
    if (!canEdit('clients')) return
    setEditing(client)
    setCreditInheritedNote(null)
    form.reset({
      name: client.name,
      legal_name: client.legal_name,
      rfc: client.rfc,
      erp_client_id: client.erp_client_id ?? '',
      contact_name: client.contact_name ?? '',
      contact_email: client.contact_email ?? '',
      contact_phone: client.contact_phone ?? '',
      segment_id: String(client.segment_id),
      issuer_id: String(client.issuer_id),
      credit_days: client.credit_days,
      stamp_on_issue: client.stamp_on_issue,
      exclude_suspension: client.exclude_suspension,
      active: client.active,
    })
    setFormDrawerOpen(true)
  }

  function openDetail(client: Client, initialTab: 'general' | 'contacts' = 'general') {
    setDetailClient(client)
    setDetailTab(initialTab)
    setDetailOpen(true)
  }

  async function onToggle(client: Client, next: boolean) {
    try {
      await toggleClient({ id: client.id, active: next })
      toast.success(
        next ? 'Cliente activado' : 'Cliente desactivado',
        client.name,
      )
      // El registro actualizado debe verse en la primera fila (§3.4).
      setPage(1)
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onSubmit(values: ClientFormValues) {
    const payload = {
      name: values.name.trim(),
      legal_name: values.legal_name.trim(),
      rfc: values.rfc.trim().toUpperCase(),
      erp_client_id: values.erp_client_id?.trim() || null,
      contact_name: values.contact_name?.trim() || null,
      contact_email: values.contact_email?.trim() || null,
      contact_phone: values.contact_phone?.trim() || null,
      segment_id: Number(values.segment_id),
      issuer_id: Number(values.issuer_id),
      credit_days: values.credit_days,
      stamp_on_issue: values.stamp_on_issue,
      exclude_suspension: values.exclude_suspension,
      active: values.active,
    }
    try {
      if (editing) {
        await updateClient({ id: editing.id, data: payload })
        toast.success('Cliente actualizado', `${payload.name} guardado correctamente.`)
      } else {
        await createClient(payload)
        toast.success('Cliente creado', `${payload.name} quedó registrado.`)
      }
      // Create y edit: volver a página 1 para ver el cliente recién tocado arriba.
      setPage(1)
      setFormDrawerOpen(false)
    } catch (error) {
      toast.apiError(error)
    }
  }

  const columns = useMemo<Array<ColumnDef<typeof clientsTableFeatures, Client>>>(
    () => [
      {
        accessorKey: 'name',
        header: 'Cliente',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="truncate font-medium" title={row.original.name}>
              {row.original.name}
            </p>
            <p className="truncate text-xs text-slate-500 dark:text-zinc-400">
              {row.original.legal_name}
            </p>
          </div>
        ),
      },
      {
        id: 'rfc_erp',
        header: 'RFC / ID ERP',
        cell: ({ row }) => (
          <div className="font-mono text-xs tabular-nums tracking-wide">
            <p>{row.original.rfc}</p>
            <p className="text-slate-500 dark:text-zinc-400">
              {row.original.erp_client_id || '—'}
            </p>
          </div>
        ),
      },
      {
        accessorKey: 'segment_name',
        header: 'Segmento',
        cell: ({ row }) => row.original.segment_name ?? '—',
      },
      {
        accessorKey: 'issuer_name',
        header: 'Emisor',
        cell: ({ row }) => (
          <span className="line-clamp-1 max-w-[10rem]" title={row.original.issuer_name ?? ''}>
            {row.original.issuer_name ?? '—'}
          </span>
        ),
      },
      {
        id: 'conditions',
        header: 'Condiciones',
        cell: ({ row }) => (
          <div className="flex flex-wrap gap-1.5">
            <AppBadge
              label={`${row.original.credit_days} días crédito`}
              tone="neutral"
            />
            {row.original.stamp_on_issue ? (
              <AppBadge label="Timbrado auto" tone="info" />
            ) : null}
            {row.original.exclude_suspension ? (
              <AppBadge label="Sin suspensión" tone="warning" />
            ) : null}
          </div>
        ),
      },
      {
        accessorKey: 'active_contracts_count',
        header: 'Contratos',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums">
            {row.original.active_contracts_count}
          </span>
        ),
      },
      {
        accessorKey: 'active',
        header: 'Estado',
        cell: ({ row }) => (
          <AppSwitch
            checked={row.original.active}
            caption={row.original.active ? 'Activo' : 'Inactivo'}
            disabled={!canEdit('clients')}
            onCheckedChange={(next) => void onToggle(row.original, next)}
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
                  label: 'Ver detalle',
                  icon: <Eye className="h-4 w-4" />,
                  onClick: () => openDetail(row.original),
                },
                {
                  label: 'Generar liga mágica',
                  icon: <KeyRound className="h-4 w-4" />,
                  onClick: () => setMagicLinkClient(row.original),
                  hidden: !canEdit('clients'),
                },
                {
                  label: 'Editar',
                  icon: <Pencil className="h-4 w-4" />,
                  onClick: () => openEdit(row.original),
                  hidden: !canEdit('clients'),
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
    features: clientsTableFeatures,
    data: items,
    columns,
  })

  const kpiCards = [
    { label: 'Total de clientes', value: summary.total },
    { label: 'Clientes activos', value: summary.active_count },
    { label: 'Con timbrado automático', value: summary.auto_stamp_count },
    { label: 'Excluidos de suspensión', value: summary.excluded_suspension_count },
  ]

  if (isApiError && !items.length) {
    return (
      <AppCard className="border-red-500/30 p-5">
        <h2 className="font-heading font-semibold text-red-500">Error al cargar clientes</h2>
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
          <h1 className="font-heading text-2xl font-semibold tracking-[-0.4px] md:text-3xl">
            Clientes
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
            Directorio fiscal y comercial: segmentos, emisores, crédito y reglas de cobranza
          </p>
        </div>
        {canCreate('clients') ? (
          <AppButton className="rounded-xl" onClick={openCreate}>
            <Plus className="mr-1 h-4 w-4" /> Nuevo cliente
          </AppButton>
        ) : null}
      </header>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {kpiCards.map((kpi) => (
          <AppCard key={kpi.label} className="pointer-events-none p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-zinc-400">
              {kpi.label}
            </p>
            <p className="mt-2 font-heading text-2xl font-semibold tabular-nums tracking-[-0.4px]">
              {kpi.value}
            </p>
          </AppCard>
        ))}
      </div>

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
        searchPlaceholder="Nombre comercial, razón social, RFC o ID ERP…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'segment',
            label: 'SEGMENTO',
            value: segmentFilter,
            onValueChange: (value) => {
              setSegmentFilter(value)
              setPage(1)
            },
            options: segmentFilterOptions,
            disabled: isSegmentLocked,
          },
          {
            id: 'issuer',
            label: 'EMISOR',
            value: issuerFilter,
            onValueChange: (value) => {
              setIssuerFilter(value)
              setPage(1)
            },
            options: issuerOptions,
          },
          {
            id: 'status',
            label: 'ESTADO',
            value: statusFilter,
            onValueChange: (value) => {
              setStatusFilter(value)
              setPage(1)
            },
            options: [
              { value: 'Todos', label: 'Todos' },
              { value: 'Activos', label: 'Activos', tone: 'success' },
              { value: 'Inactivos', label: 'Inactivos', tone: 'danger' },
            ],
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
            <Skeleton className="h-10 w-full rounded-xl" />
            <Skeleton className="h-48 w-full rounded-2xl" />
          </div>
        }
        isEmpty={!isLoading && items.length === 0}
        emptyState={
          <div className="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
            <Users className="h-10 w-10 text-slate-300 dark:text-zinc-600" />
            <p className="font-heading text-lg font-semibold">Sin clientes para mostrar</p>
            <p className="max-w-md text-sm text-slate-500 dark:text-zinc-400">
              Ajusta los filtros o crea el primer cliente del directorio para comenzar el
              alta de contratos.
            </p>
            {canCreate('clients') ? (
              <AppButton className="mt-2 rounded-xl" onClick={openCreate}>
                <Plus className="mr-1 h-4 w-4" /> Nuevo cliente
              </AppButton>
            ) : null}
          </div>
        }
        footer={
          <PaginationBar
            page={page}
            pageSize={pageSize}
            total={total}
            onPageChange={setPage}
            onPageSizeChange={(size) => {
              setLocalPageSize(size)
              setPageSize(size)
              setPage(1)
            }}
          />
        }
      >
        <div className="hidden overflow-x-auto lg:block">
          <table className="w-full text-sm">
            <thead>
              {table.getHeaderGroups().map((hg) => (
                <AppTableHeadRow key={hg.id}>
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
                </AppTableHeadRow>
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
          {items.map((client) => (
            <AppCard key={client.id} className="p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-heading truncate font-semibold">{client.name}</p>
                  <p className="truncate text-xs text-slate-500">{client.legal_name}</p>
                  <p className="mt-1 font-mono text-xs tabular-nums">{client.rfc}</p>
                </div>
                <AppRowActionsMenu
                  actions={[
                    {
                      label: 'Ver detalle',
                      icon: <Eye className="h-4 w-4" />,
                      onClick: () => openDetail(client),
                    },
                    {
                      label: 'Generar liga mágica',
                      icon: <KeyRound className="h-4 w-4" />,
                      onClick: () => setMagicLinkClient(client),
                      hidden: !canEdit('clients'),
                    },
                    {
                      label: 'Editar',
                      icon: <Pencil className="h-4 w-4" />,
                      onClick: () => openEdit(client),
                      hidden: !canEdit('clients'),
                    },
                  ]}
                />
              </div>
              <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500 dark:text-zinc-400">
                <p>Segmento: {client.segment_name ?? '—'}</p>
                <p>Emisor: {client.issuer_name ?? '—'}</p>
                <p className="tabular-nums">Contratos: {client.active_contracts_count}</p>
              </div>
              <div className="mt-3 flex flex-wrap gap-1.5">
                <AppBadge
                  label={`${client.credit_days} días crédito`}
                  tone="neutral"
                />
                {client.stamp_on_issue ? (
                  <AppBadge label="Timbrado auto" tone="info" />
                ) : null}
                {client.exclude_suspension ? (
                  <AppBadge label="Sin suspensión" tone="warning" />
                ) : null}
              </div>
              <div className="mt-3">
                <AppSwitch
                  checked={client.active}
                  caption={client.active ? 'Activo' : 'Inactivo'}
                  disabled={!canEdit('clients')}
                  onCheckedChange={(next) => void onToggle(client, next)}
                />
              </div>
            </AppCard>
          ))}
        </div>
      </AppTableWrapper>

      {/* Drawer lg: Crear / Editar cliente (§3.1) */}
      <AppDrawer
        open={formDrawerOpen}
        onClose={() => setFormDrawerOpen(false)}
        size="lg"
        title={editing ? 'Editar cliente' : 'Nuevo cliente'}
        description="Identidad fiscal, contacto y reglas comerciales de cobranza"
        footer={
          <>
            <AppButton
              type="button"
              variant="outline"
              className="rounded-xl"
              onClick={() => setFormDrawerOpen(false)}
            >
              Cancelar
            </AppButton>
            <AppButton type="submit" form="client-form" className="rounded-xl">
              {editing ? 'Guardar cambios' : 'Crear cliente'}
            </AppButton>
          </>
        }
      >
        <form
          id="client-form"
          className="space-y-5"
          onSubmit={form.handleSubmit(onSubmit)}
        >
          <AppCard className="p-0">
            <AppCardHeader>
              <AppCardTitle>Identidad fiscal y contacto</AppCardTitle>
            </AppCardHeader>
            <AppCardContent className="grid gap-3 sm:grid-cols-2">
              <div className="sm:col-span-1">
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  Nombre comercial
                </label>
                <AppInput {...form.register('name')} error={!!form.formState.errors.name} />
                {form.formState.errors.name ? (
                  <p className="mt-1 text-xs text-red-500">
                    {form.formState.errors.name.message}
                  </p>
                ) : null}
              </div>
              <div className="sm:col-span-1">
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  Razón social
                </label>
                <AppInput
                  {...form.register('legal_name')}
                  error={!!form.formState.errors.legal_name}
                />
                {form.formState.errors.legal_name ? (
                  <p className="mt-1 text-xs text-red-500">
                    {form.formState.errors.legal_name.message}
                  </p>
                ) : null}
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  RFC
                </label>
                <AppInput
                  className="font-mono uppercase"
                  {...form.register('rfc')}
                  onChange={(e) =>
                    form.setValue('rfc', e.target.value.toUpperCase(), {
                      shouldValidate: true,
                    })
                  }
                  error={!!form.formState.errors.rfc}
                />
                {form.formState.errors.rfc ? (
                  <p className="mt-1 text-xs text-red-500">
                    {form.formState.errors.rfc.message}
                  </p>
                ) : null}
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  ID Cliente en ERP
                </label>
                <AppInput
                  className="font-mono"
                  {...form.register('erp_client_id')}
                  placeholder="Opcional"
                />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  Nombre de contacto
                </label>
                <AppInput {...form.register('contact_name')} />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  Correo
                </label>
                <AppInput type="email" {...form.register('contact_email')} />
                {form.formState.errors.contact_email ? (
                  <p className="mt-1 text-xs text-red-500">
                    {form.formState.errors.contact_email.message}
                  </p>
                ) : null}
              </div>
              <div className="sm:col-span-2">
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  Teléfono
                </label>
                <AppInput {...form.register('contact_phone')} />
              </div>
            </AppCardContent>
          </AppCard>

          <AppCard className="p-0">
            <AppCardHeader>
              <AppCardTitle>Configuración comercial y cobranza</AppCardTitle>
            </AppCardHeader>
            <AppCardContent className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  Segmento
                </label>
                <Controller
                  control={form.control}
                  name="segment_id"
                  render={({ field }) => (
                    <AppSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      options={segmentFormOptions}
                      disabled={isSegmentLocked}
                      error={!!form.formState.errors.segment_id}
                      placeholder="Selecciona segmento"
                    />
                  )}
                />
                {form.formState.errors.segment_id ? (
                  <p className="mt-1 text-xs text-red-500">
                    {form.formState.errors.segment_id.message}
                  </p>
                ) : null}
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  Emisor facturador
                </label>
                <Controller
                  control={form.control}
                  name="issuer_id"
                  render={({ field }) => (
                    <AppSelect
                      value={field.value}
                      onValueChange={field.onChange}
                      options={issuerFormOptions}
                      error={!!form.formState.errors.issuer_id}
                      placeholder="Selecciona emisor"
                    />
                  )}
                />
                {form.formState.errors.issuer_id ? (
                  <p className="mt-1 text-xs text-red-500">
                    {form.formState.errors.issuer_id.message}
                  </p>
                ) : null}
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600 dark:text-zinc-300">
                  Días de crédito
                </label>
                <AppInput
                  numericMode="integer"
                  mono
                  className="tabular-nums"
                  {...form.register('credit_days', { valueAsNumber: true })}
                  onChange={(e) => {
                    const raw = e.target.value.replace(/[^\d]/g, '')
                    const next = raw === '' ? 0 : Number(raw)
                    form.setValue('credit_days', Number.isFinite(next) ? next : 0, {
                      shouldValidate: true,
                    })
                    setCreditInheritedNote(null)
                  }}
                  error={!!form.formState.errors.credit_days}
                />
                {creditInheritedNote && !editing ? (
                  <p className="mt-1 text-[11px] text-slate-500 dark:text-zinc-400">
                    {creditInheritedNote}
                  </p>
                ) : null}
                {form.formState.errors.credit_days ? (
                  <p className="mt-1 text-xs text-red-500">
                    {form.formState.errors.credit_days.message}
                  </p>
                ) : null}
              </div>
              <div className="space-y-3 sm:col-span-2">
                <div className="flex items-center justify-between gap-3">
                  <p className="text-sm font-medium">
                    Timbrar factura automáticamente al emitir
                  </p>
                  <Controller
                    control={form.control}
                    name="stamp_on_issue"
                    render={({ field }) => (
                      <AppSwitch
                        checked={field.value}
                        onCheckedChange={field.onChange}
                        label="Timbrar factura automáticamente al emitir"
                      />
                    )}
                  />
                </div>
                <div className="flex items-center justify-between gap-3">
                  <p className="text-sm font-medium">
                    Excluir de suspensión automática a 90 días
                  </p>
                  <Controller
                    control={form.control}
                    name="exclude_suspension"
                    render={({ field }) => (
                      <AppSwitch
                        checked={field.value}
                        onCheckedChange={field.onChange}
                        label="Excluir de suspensión automática a 90 días"
                      />
                    )}
                  />
                </div>
                <div className="flex items-center justify-between gap-3">
                  <p className="text-sm font-medium">Cliente activo</p>
                  <Controller
                    control={form.control}
                    name="active"
                    render={({ field }) => (
                      <AppSwitch
                        checked={field.value}
                        onCheckedChange={field.onChange}
                        label="Cliente activo"
                      />
                    )}
                  />
                </div>
              </div>
            </AppCardContent>
          </AppCard>
        </form>
      </AppDrawer>

      {/* Drawer xl: Detalle del cliente + Contactos amplios (§3.1) */}
      <AppDrawer
        open={detailOpen}
        onClose={() => setDetailOpen(false)}
        size="xl"
        title={detailClient?.name ?? 'Cliente'}
        description={
          detailClient
            ? `${detailClient.legal_name} · RFC ${detailClient.rfc}`
            : 'Vista de datos fiscales, contactos y reglas de cobranza'
        }
        headerExtra={
          detailClient ? (
            <>
              <AppBadge
                label={detailClient.active ? 'Activo' : 'Inactivo'}
                tone={detailClient.active ? 'success' : 'danger'}
              />
              {detailClient.stamp_on_issue ? (
                <AppBadge label="Timbrado auto" tone="info" />
              ) : null}
              {detailClient.exclude_suspension ? (
                <AppBadge label="Sin suspensión" tone="warning" />
              ) : null}
            </>
          ) : null
        }
        footer={
          detailClient ? (
            <>
              <AppButton
                type="button"
                variant="outline"
                className="rounded-xl"
                onClick={() => setDetailOpen(false)}
              >
                Cerrar
              </AppButton>
              {canEdit('clients') ? (
                <AppButton
                  type="button"
                  variant="secondary"
                  className="rounded-xl"
                  onClick={() => {
                    setDetailOpen(false)
                    openEdit(detailClient)
                  }}
                >
                  <Pencil className="mr-1 h-4 w-4" /> Editar cliente
                </AppButton>
              ) : null}
              {canCreate('contracts') ? (
                <AppButton
                  type="button"
                  className="rounded-xl"
                  onClick={() => {
                    setDetailOpen(false)
                    navigate(`/contratos/nuevo?client_id=${detailClient.id}`)
                  }}
                >
                  <FilePlus2 className="mr-1 h-4 w-4" /> Crear contrato para este cliente
                </AppButton>
              ) : null}
            </>
          ) : null
        }
      >
        {detailClient ? (
          <div className="space-y-5 text-sm">
            <AppTabs
              value={detailTab}
              onChange={(id) => setDetailTab(id as 'general' | 'contacts')}
              aria-label="Pestañas del detalle de cliente"
              items={[
                { id: 'general', label: 'Información general' },
                {
                  id: 'contacts',
                  label: 'Contactos',
                  badge: detailContacts.length,
                },
              ]}
            />

            {detailTab === 'general' ? (
              <div className="grid gap-4 md:grid-cols-2">
                <AppCard className="p-0">
                  <AppCardHeader>
                    <AppCardTitle>Identidad fiscal</AppCardTitle>
                  </AppCardHeader>
                  <AppCardContent className="space-y-3 text-sm">
                    <div>
                      <p className="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">
                        Razón social
                      </p>
                      <p className="mt-0.5 font-medium text-slate-900 dark:text-white">
                        {detailClient.legal_name}
                      </p>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">
                          RFC
                        </p>
                        <p className="mt-0.5 font-mono tabular-nums text-slate-900 dark:text-white">
                          {detailClient.rfc}
                        </p>
                      </div>
                      <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">
                          ID ERP
                        </p>
                        <p className="mt-0.5 font-mono text-slate-900 dark:text-white">
                          {detailClient.erp_client_id || '—'}
                        </p>
                      </div>
                    </div>
                    <div>
                      <p className="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">
                        Contacto principal
                      </p>
                      <p className="mt-0.5 text-slate-800 dark:text-zinc-200">
                        {detailClient.contact_name || '—'}
                      </p>
                      {detailClient.contact_email || detailClient.contact_phone ? (
                        <p className="mt-0.5 text-xs text-slate-500 dark:text-zinc-400">
                          {[detailClient.contact_email, detailClient.contact_phone]
                            .filter(Boolean)
                            .join(' · ')}
                        </p>
                      ) : null}
                    </div>
                  </AppCardContent>
                </AppCard>

                <AppCard className="p-0">
                  <AppCardHeader>
                    <AppCardTitle>Reglas comerciales y cobranza</AppCardTitle>
                  </AppCardHeader>
                  <AppCardContent className="space-y-3 text-sm">
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">
                          Segmento
                        </p>
                        <p className="mt-0.5 font-medium text-slate-900 dark:text-white">
                          {detailClient.segment_name ?? '—'}
                        </p>
                      </div>
                      <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">
                          Emisor facturador
                        </p>
                        <p className="mt-0.5 font-medium text-slate-900 dark:text-white">
                          {detailClient.issuer_name ?? '—'}
                        </p>
                      </div>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">
                          Crédito autorizado
                        </p>
                        <p className="mt-0.5 font-mono tabular-nums text-slate-900 dark:text-white">
                          {detailClient.credit_days} días
                        </p>
                      </div>
                      <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-zinc-500">
                          Contratos activos
                        </p>
                        <p className="mt-0.5 font-mono tabular-nums text-slate-900 dark:text-white">
                          {detailClient.active_contracts_count}
                        </p>
                      </div>
                    </div>
                    <div className="flex flex-wrap gap-1.5 pt-1">
                      <AppBadge
                        label={detailClient.active ? 'Activo' : 'Inactivo'}
                        tone={detailClient.active ? 'success' : 'danger'}
                      />
                      {detailClient.stamp_on_issue ? (
                        <AppBadge label="Timbrado automático" tone="info" />
                      ) : null}
                      {detailClient.exclude_suspension ? (
                        <AppBadge label="Excluido de suspensión" tone="warning" />
                      ) : null}
                    </div>
                  </AppCardContent>
                </AppCard>
              </div>
            ) : (
              <ClientContactsTab
                clientId={detailClient.id}
                clientName={detailClient.name}
              />
            )}
          </div>
        ) : null}
      </AppDrawer>

      {/* Modal compacto de Liga Mágica desde tabla de Clientes (z-[70]) */}
      <ClientMagicLinkModal
        open={Boolean(magicLinkClient)}
        onOpenChange={(open) => {
          if (!open) setMagicLinkClient(null)
        }}
        client={magicLinkClient}
        onManageContacts={(client) => openDetail(client, 'contacts')}
      />
    </div>
  )
}
