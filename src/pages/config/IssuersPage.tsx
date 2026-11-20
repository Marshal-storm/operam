import { zodResolver } from '@hookform/resolvers/zod'
import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'

const issuersTableFeatures = tableFeatures({})
import { Building2, Pencil, Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
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
import { AppSwitch } from '@/components/ui/AppSwitch'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { useIssuersConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { cn } from '@/lib/utils'
import type { Issuer } from '@/types/catalog'

const issuerFormSchema = z.object({
  name: z.string().min(3, 'Indica el nombre comercial o razón social').max(120),
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
  is_active: z.boolean(),
  api_url: z.string().max(255).optional(),
  secret_ref: z.string().max(255).optional(),
  integropay_merchant_token_ref: z.string().max(255).optional(),
  integropay_device_token_ref: z.string().max(255).optional(),
})

type IssuerFormValues = z.infer<typeof issuerFormSchema>

function RefBadge({ label, configured }: { label: string; configured: boolean }) {
  return (
    <AppBadge
      label={label}
      tone={configured ? 'success' : 'neutral'}
      className="px-2 py-0.5 text-[10px]"
    />
  )
}

/**
 * Emisores — CRUD exclusivo RF-1 (tabla `emisor`).
 */
export function IssuersPage() {
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()
  const toast = useToast()
  const { canCreate, canEdit } = usePermissions()
  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  useEffect(() => {
    setLocalPageSize(preferredSize)
    setPage(1)
  }, [preferredSize])
  const [search, setSearch] = useState('')
  const [estado, setEstado] = useState('Todos')

  const params = useMemo(
    () => ({
      page,
      per_page: pageSize,
      search: search.trim() || undefined,
      status:
        estado === 'Activos' ? 'active' : estado === 'Inactivos' ? 'inactive' : undefined,
    }),
    [page, pageSize, search, estado],
  )

  const {
    items,
    total,
    isLoading,
    isApiError,
    errorMessage,
    refetch,
    createIssuer,
    updateIssuer,
    toggleIssuer,
  } = useIssuersConfig(params)

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<Issuer | null>(null)

  const form = useForm<IssuerFormValues>({
    resolver: zodResolver(issuerFormSchema),
    defaultValues: {
      name: '',
      rfc: '',
      is_active: true,
      api_url: '',
      secret_ref: '',
      integropay_merchant_token_ref: '',
      integropay_device_token_ref: '',
    },
  })

  const columns = useMemo<Array<ColumnDef<typeof issuersTableFeatures, Issuer>>>(
    () => [
      {
        accessorKey: 'name',
        header: 'Emisor',
        cell: ({ row }) => (
          <div className="flex min-w-0 items-center gap-2">
            <Building2 className="h-4 w-4 shrink-0 text-[#0066FF] dark:text-[#0A84FF]" />
            <span className="truncate font-medium" title={row.original.name}>
              {row.original.name}
            </span>
          </div>
        ),
      },
      {
        accessorKey: 'rfc',
        header: 'RFC',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums tracking-wide">{row.original.rfc}</span>
        ),
      },
      {
        accessorKey: 'api_url',
        header: 'Conexión ERP',
        cell: ({ row }) => (
          <span className="line-clamp-1 max-w-[14rem] font-mono text-xs text-slate-500">
            {row.original.api_url || '—'}
          </span>
        ),
      },
      {
        id: 'refs',
        header: 'Referencias IntegroPAY',
        cell: ({ row }) => {
          const em = row.original
          return (
            <div className="flex flex-wrap gap-1">
              <RefBadge label="Secret" configured={em.has_erp_secret} />
              <RefBadge label="Merchant" configured={em.has_merchant_token} />
              <RefBadge label="Device" configured={em.has_device_token} />
            </div>
          )
        },
      },
      {
        id: 'instancias',
        header: 'Instancias',
        cell: ({ row }) => (
          <span className="font-mono text-xs tabular-nums">
            {row.original.linked_instances_count ?? 0}
          </span>
        ),
      },
      {
        accessorKey: 'is_active',
        header: 'Estado',
        cell: ({ row }) => (
          <AppBadge
            label={row.original.is_active ? 'Activo' : 'Inactivo'}
            tone={row.original.is_active ? 'success' : 'danger'}
          />
        ),
      },
      {
        id: 'actions',
        header: 'Acciones',
        cell: ({ row }) =>
          canEdit('config_issuers') ? (
            <div className="flex items-center gap-2">
              <AppButton
                type="button"
                variant="outline"
                size="sm"
                className="rounded-xl"
                onClick={() => openEdit(row.original)}
              >
                <Pencil className="mr-1 h-3.5 w-3.5" /> Editar
              </AppButton>
              <AppSwitch
                checked={row.original.is_active}
                onCheckedChange={(next) => void onToggle(row.original, next)}
                label={row.original.is_active ? 'Desactivar emisor' : 'Activar emisor'}
              />
            </div>
          ) : null,
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [canEdit],
  )

  const table = useTable({
    features: issuersTableFeatures,
    data: items,
    columns,
  })

  function openCreate() {
    setEditing(null)
    form.reset({
      name: '',
      rfc: '',
      is_active: true,
      api_url: '',
      secret_ref: '',
      integropay_merchant_token_ref: '',
      integropay_device_token_ref: '',
    })
    setModalOpen(true)
  }

  function openEdit(issuer: Issuer) {
    setEditing(issuer)
    const unmask = (value: string | null): string =>
      !value || value === '••••••••' ? '' : value
    form.reset({
      name: issuer.name,
      rfc: issuer.rfc,
      is_active: issuer.is_active,
      api_url: issuer.api_url ?? '',
      secret_ref: unmask(issuer.secret_ref),
      integropay_merchant_token_ref: unmask(issuer.integropay_merchant_token_ref),
      integropay_device_token_ref: unmask(issuer.integropay_device_token_ref),
    })
    setModalOpen(true)
  }

  async function onToggle(issuer: Issuer, next: boolean) {
    try {
      await toggleIssuer({ id: issuer.id, is_active: next })
      toast.success(
        next ? 'Emisor activado' : 'Emisor desactivado',
        issuer.name,
      )
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onSubmit(values: IssuerFormValues) {
    const payload = {
      name: values.name.trim(),
      rfc: values.rfc.trim().toUpperCase(),
      is_active: values.is_active,
      api_url: values.api_url?.trim() || null,
      secret_ref: values.secret_ref?.trim() || null,
      integropay_merchant_token_ref: values.integropay_merchant_token_ref?.trim() || null,
      integropay_device_token_ref: values.integropay_device_token_ref?.trim() || null,
    }
    try {
      if (editing) {
        await updateIssuer({ id: editing.id, data: payload })
        toast.success('Emisor actualizado', `${payload.name} guardado correctamente.`)
      } else {
        await createIssuer(payload)
        toast.success('Emisor creado', `${payload.name} quedó registrado.`)
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
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Emisores</h1>
          <p className="mt-1 text-sm text-slate-500">
            Datos fiscales, conexión ERP e IntegroPAY (solo referencias del cofre)
          </p>
        </div>
        {canCreate('config_issuers') && (
          <AppButton className="rounded-xl" onClick={openCreate}>
            <Plus className="mr-1 h-4 w-4" /> Nuevo emisor
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
          placeholder="Buscar por nombre o RFC…"
          aria-label="Buscar emisores"
        />
        <AppFilterField label="Estado">
          <div className="ios-pill-track" role="group" aria-label="Estado">
            {['Todos', 'Activos', 'Inactivos'].map((opt) => (
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
            {items.map((em) => (
              <AppCard key={em.id} className="p-4">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-heading truncate font-semibold">{em.name}</p>
                    <p className="font-mono text-xs text-slate-500">{em.rfc}</p>
                  </div>
                  <AppBadge
                    label={em.is_active ? 'Activo' : 'Inactivo'}
                    tone={em.is_active ? 'success' : 'danger'}
                  />
                </div>
                <p className="mt-2 line-clamp-1 font-mono text-[11px] text-slate-500">
                  {em.api_url || 'Sin URL ERP'}
                </p>
                <div className="mt-2 flex flex-wrap gap-1">
                  <RefBadge label="Secret" configured={em.has_erp_secret} />
                  <RefBadge label="Merchant" configured={em.has_merchant_token} />
                  <RefBadge label="Device" configured={em.has_device_token} />
                </div>
                <p className="mt-2 text-xs text-slate-500">
                  {em.linked_instances_count ?? 0} instancias vinculadas
                </p>
                {canEdit('config_issuers') && (
                  <div className="mt-3 flex items-center gap-2">
                    <AppButton
                      type="button"
                      variant="outline"
                      size="sm"
                      className="flex-1 rounded-xl"
                      onClick={() => openEdit(em)}
                    >
                      <Pencil className="mr-1 h-3.5 w-3.5" /> Editar
                    </AppButton>
                    <AppSwitch
                      checked={em.is_active}
                      onCheckedChange={(next) => void onToggle(em, next)}
                      label={em.is_active ? 'Desactivar' : 'Activar'}
                    />
                  </div>
                )}
              </AppCard>
            ))}
          </div>

          {items.length === 0 && (
            <p className="p-6 text-sm text-slate-500">Sin emisores con esos filtros.</p>
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
        <AppModalContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
          <AppModalHeader>
            <AppModalTitle>{editing ? 'Editar emisor' : 'Nuevo emisor'}</AppModalTitle>
            <AppModalDescription>
              Datos fiscales y referencias de conexión al ERP / IntegroPAY.
            </AppModalDescription>
          </AppModalHeader>
          <form className="space-y-5" onSubmit={form.handleSubmit(onSubmit)}>
            <AppCard className="space-y-3 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Datos fiscales y estado
              </p>
              <label className="block text-sm">
                Nombre comercial / Razón social
                <AppInput withLabelSpacing {...form.register('name')} />
              </label>
              {form.formState.errors.name && (
                <p className="text-xs text-red-500">{form.formState.errors.name.message}</p>
              )}
              <label className="block text-sm">
                RFC
                <AppInput
                  withLabelSpacing
                  mono
                  className="uppercase tracking-wide"
                  maxLength={13}
                  autoCapitalize="characters"
                  {...form.register('rfc', {
                    onChange: (e) => {
                      e.target.value = String(e.target.value ?? '').toUpperCase()
                    },
                  })}
                />
              </label>
              {form.formState.errors.rfc && (
                <p className="text-xs text-red-500">{form.formState.errors.rfc.message}</p>
              )}
              <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200/70 bg-slate-50/80 px-3 py-2.5 dark:border-white/[0.08] dark:bg-white/[0.04]">
                <div>
                  <p className="text-sm font-medium">Emisor activo para facturar y operar</p>
                  <p className="text-[11px] text-slate-500">
                    Si está inactivo no se recomienda usarlo en nuevas instancias.
                  </p>
                </div>
                <Controller
                  control={form.control}
                  name="is_active"
                  render={({ field }) => (
                    <AppSwitch
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      label="Emisor activo"
                    />
                  )}
                />
              </div>
            </AppCard>

            <AppCard className="space-y-3 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Conexión ERP e IntegroPAY
              </p>
              <p className="rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-[11px] leading-relaxed text-amber-800 dark:text-amber-300">
                Por seguridad bancaria, aquí solo se guarda el nombre de la variable o referencia
                del cofre de secretos, nunca la llave en texto plano.
              </p>
              <label className="block text-sm">
                URL de API del ERP (api_url)
                <AppInput
                  withLabelSpacing
                  mono
                  className="text-xs"
                  placeholder="https://erp.ejemplo.com/api"
                  {...form.register('api_url')}
                />
              </label>
              <label className="block text-sm">
                Referencia Secret ERP (secret_ref)
                <AppInput
                  withLabelSpacing
                  mono
                  className="text-xs"
                  placeholder="ERP_SECRET_ACME"
                  {...form.register('secret_ref')}
                />
              </label>
              <label className="block text-sm">
                Referencia Merchant Token IntegroPAY
                <AppInput
                  withLabelSpacing
                  mono
                  className="text-xs"
                  placeholder="INTEGROPAY_MERCHANT_ACME"
                  {...form.register('integropay_merchant_token_ref')}
                />
              </label>
              <label className="block text-sm">
                Referencia Device Token IntegroPAY
                <AppInput
                  withLabelSpacing
                  mono
                  className="text-xs"
                  placeholder="INTEGROPAY_DEVICE_ACME"
                  {...form.register('integropay_device_token_ref')}
                />
              </label>
            </AppCard>

            <AppButton type="submit" className="w-full rounded-xl">
              {editing ? 'Guardar emisor' : 'Registrar emisor'}
            </AppButton>
          </form>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
