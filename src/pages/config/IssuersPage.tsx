import { zodResolver } from '@hookform/resolvers/zod'
import {
  tableFeatures,
  useTable,
  type ColumnDef,
} from '@tanstack/react-table'

const issuersTableFeatures = tableFeatures({})
import { Building2, Pencil, Plus, Search } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { IosSwitch } from '@/components/ui/IosSwitch'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { StatusPill } from '@/components/ui/StatusPill'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { useIssuersConfig } from '@/hooks/useConfigCatalog'
import { usePermissions } from '@/hooks/usePermissions'
import { cn } from '@/lib/utils'
import type { Issuer } from '@/types/catalog'

const issuerFormSchema = z.object({
  nombre: z.string().min(3, 'Indica el nombre comercial o razón social').max(120),
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
  activo: z.boolean(),
  api_url: z.string().max(255).optional(),
  secret_ref: z.string().max(255).optional(),
  integropay_merchant_token_ref: z.string().max(255).optional(),
  integropay_device_token_ref: z.string().max(255).optional(),
})

type IssuerFormValues = z.infer<typeof issuerFormSchema>

const inputClass =
  'mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[#0066FF]/30 dark:border-white/[0.08] dark:bg-white/[0.05]'

function RefBadge({ label, configured }: { label: string; configured: boolean }) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium',
        configured
          ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
          : 'border-slate-300/70 bg-slate-100 text-slate-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-zinc-400',
      )}
    >
      {label}
    </span>
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
      estado:
        estado === 'Activos' ? 'activos' : estado === 'Inactivos' ? 'inactivos' : undefined,
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
      nombre: '',
      rfc: '',
      activo: true,
      api_url: '',
      secret_ref: '',
      integropay_merchant_token_ref: '',
      integropay_device_token_ref: '',
    },
  })

  const columns = useMemo<Array<ColumnDef<typeof issuersTableFeatures, Issuer>>>(
    () => [
      {
        accessorKey: 'nombre',
        header: 'Emisor',
        cell: ({ row }) => (
          <div className="flex min-w-0 items-center gap-2">
            <Building2 className="h-4 w-4 shrink-0 text-[#0066FF] dark:text-[#0A84FF]" />
            <span className="truncate font-medium" title={row.original.nombre}>
              {row.original.nombre}
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
            {row.original.instancias_vinculadas ?? 0}
          </span>
        ),
      },
      {
        accessorKey: 'activo',
        header: 'Estado',
        cell: ({ row }) => (
          <StatusPill
            label={row.original.activo ? 'Activo' : 'Inactivo'}
            tone={row.original.activo ? 'positive' : 'negative'}
          />
        ),
      },
      {
        id: 'actions',
        header: 'Acciones',
        cell: ({ row }) =>
          canEdit('config_emisores') ? (
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="rounded-xl"
                onClick={() => openEdit(row.original)}
              >
                <Pencil className="mr-1 h-3.5 w-3.5" /> Editar
              </Button>
              <IosSwitch
                checked={row.original.activo}
                onCheckedChange={(next) => void onToggle(row.original, next)}
                label={row.original.activo ? 'Desactivar emisor' : 'Activar emisor'}
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
      nombre: '',
      rfc: '',
      activo: true,
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
      nombre: issuer.nombre,
      rfc: issuer.rfc,
      activo: issuer.activo,
      api_url: issuer.api_url ?? '',
      secret_ref: unmask(issuer.secret_ref),
      integropay_merchant_token_ref: unmask(issuer.integropay_merchant_token_ref),
      integropay_device_token_ref: unmask(issuer.integropay_device_token_ref),
    })
    setModalOpen(true)
  }

  async function onToggle(issuer: Issuer, next: boolean) {
    try {
      await toggleIssuer({ id: issuer.id, activo: next })
      toast.success(
        next ? 'Emisor activado' : 'Emisor desactivado',
        issuer.nombre,
      )
    } catch (error) {
      toast.apiError(error)
    }
  }

  async function onSubmit(values: IssuerFormValues) {
    const payload = {
      nombre: values.nombre.trim(),
      rfc: values.rfc.trim().toUpperCase(),
      activo: values.activo,
      api_url: values.api_url?.trim() || null,
      secret_ref: values.secret_ref?.trim() || null,
      integropay_merchant_token_ref: values.integropay_merchant_token_ref?.trim() || null,
      integropay_device_token_ref: values.integropay_device_token_ref?.trim() || null,
    }
    try {
      if (editing) {
        await updateIssuer({ id: editing.id, data: payload })
        toast.success('Emisor actualizado', `${payload.nombre} guardado correctamente.`)
      } else {
        await createIssuer(payload)
        toast.success('Emisor creado', `${payload.nombre} quedó registrado.`)
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
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Emisores</h1>
          <p className="mt-1 text-sm text-slate-500">
            Datos fiscales, conexión ERP e IntegroPAY (solo referencias del cofre)
          </p>
        </div>
        {canCreate('config_emisores') && (
          <Button className="rounded-xl" onClick={openCreate}>
            <Plus className="mr-1 h-4 w-4" /> Nuevo emisor
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
            placeholder="Buscar por nombre o RFC…"
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
          />
        </div>
        <div className="ios-pill-track">
          {['Todos', 'Activos', 'Inactivos'].map((opt) => (
            <button
              key={opt}
              type="button"
              onClick={() => {
                setEstado(opt)
                setPage(1)
              }}
              className={cn(
                'rounded-xl px-2.5 py-1 text-xs',
                estado === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
              )}
            >
              {opt}
            </button>
          ))}
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
            {items.map((em) => (
              <article
                key={em.id}
                className="rounded-2xl border border-slate-200/80 p-4 dark:border-white/[0.08]"
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-heading truncate font-semibold">{em.nombre}</p>
                    <p className="font-mono text-xs text-slate-500">{em.rfc}</p>
                  </div>
                  <StatusPill
                    label={em.activo ? 'Activo' : 'Inactivo'}
                    tone={em.activo ? 'positive' : 'negative'}
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
                  {em.instancias_vinculadas ?? 0} instancias vinculadas
                </p>
                {canEdit('config_emisores') && (
                  <div className="mt-3 flex items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="flex-1 rounded-xl"
                      onClick={() => openEdit(em)}
                    >
                      <Pencil className="mr-1 h-3.5 w-3.5" /> Editar
                    </Button>
                    <IosSwitch
                      checked={em.activo}
                      onCheckedChange={(next) => void onToggle(em, next)}
                      label={em.activo ? 'Desactivar' : 'Activar'}
                    />
                  </div>
                )}
              </article>
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
        </section>
      )}

      <Dialog open={modalOpen} onOpenChange={setModalOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? 'Editar emisor' : 'Nuevo emisor'}</DialogTitle>
            <DialogDescription>
              Datos fiscales y referencias de conexión al ERP / IntegroPAY.
            </DialogDescription>
          </DialogHeader>
          <form className="space-y-5" onSubmit={form.handleSubmit(onSubmit)}>
            <fieldset className="space-y-3 rounded-2xl border border-slate-200/80 p-4 dark:border-white/[0.08]">
              <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                Datos fiscales y estado
              </legend>
              <label className="block text-sm">
                Nombre comercial / Razón social
                <input className={inputClass} {...form.register('nombre')} />
              </label>
              {form.formState.errors.nombre && (
                <p className="text-xs text-red-500">{form.formState.errors.nombre.message}</p>
              )}
              <label className="block text-sm">
                RFC
                <input
                  className={cn(inputClass, 'font-mono uppercase tracking-wide')}
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
                  name="activo"
                  render={({ field }) => (
                    <IosSwitch
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      label="Emisor activo"
                    />
                  )}
                />
              </div>
            </fieldset>

            <fieldset className="space-y-3 rounded-2xl border border-slate-200/80 p-4 dark:border-white/[0.08]">
              <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                Conexión ERP e IntegroPAY
              </legend>
              <p className="rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-[11px] leading-relaxed text-amber-800 dark:text-amber-300">
                Por seguridad bancaria, aquí solo se guarda el nombre de la variable o referencia
                del cofre de secretos, nunca la llave en texto plano.
              </p>
              <label className="block text-sm">
                URL de API del ERP (api_url)
                <input
                  className={cn(inputClass, 'font-mono text-xs')}
                  placeholder="https://erp.ejemplo.com/api"
                  {...form.register('api_url')}
                />
              </label>
              <label className="block text-sm">
                Referencia Secret ERP (secret_ref)
                <input
                  className={cn(inputClass, 'font-mono text-xs')}
                  placeholder="ERP_SECRET_ACME"
                  {...form.register('secret_ref')}
                />
              </label>
              <label className="block text-sm">
                Referencia Merchant Token IntegroPAY
                <input
                  className={cn(inputClass, 'font-mono text-xs')}
                  placeholder="INTEGROPAY_MERCHANT_ACME"
                  {...form.register('integropay_merchant_token_ref')}
                />
              </label>
              <label className="block text-sm">
                Referencia Device Token IntegroPAY
                <input
                  className={cn(inputClass, 'font-mono text-xs')}
                  placeholder="INTEGROPAY_DEVICE_ACME"
                  {...form.register('integropay_device_token_ref')}
                />
              </label>
            </fieldset>

            <Button type="submit" className="w-full rounded-xl">
              {editing ? 'Guardar emisor' : 'Registrar emisor'}
            </Button>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
