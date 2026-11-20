import { useQuery } from '@tanstack/react-query'
import { History } from 'lucide-react'
import { useState } from 'react'
import { AppBadge, type AppBadgeTone } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppFilterField } from '@/components/ui/AppFilterField'
import { AppInput, AppSearchInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppSelect } from '@/components/ui/AppSelect'
import { AppTableHeadRow, AppTableWrapper } from '@/components/ui/AppTableWrapper'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { usePermissions } from '@/hooks/usePermissions'
import { formatDateTimeEsMx } from '@/lib/utils'
import { fetchManagedUsers, type ManagedUser } from '@/services/rbacService'
import {
  AUDIT_LOGS_QUERY_KEY,
  fetchAuditLogs,
  type AuditLogItem,
} from '@/services/systemService'

const ALL = 'all'

const MODULE_LABELS: Record<string, string> = {
  config_catalog: 'Catálogo',
  config_prices: 'Precios',
  config_promotions: 'Promociones',
  config_issuers: 'Emisores',
  config_instances: 'Instancias',
  config_users: 'Usuarios',
  config_roles: 'Roles y permisos',
  config_company: 'Empresa',
  config_system: 'Sistema',
  config_audit_logs: 'Bitácora',
  config_preferences: 'Preferencias',
  dashboard: 'Dashboard',
  auth: 'Autenticación',
}

const MODULE_OPTIONS = [
  { value: ALL, label: 'Todos' },
  ...Object.entries(MODULE_LABELS).map(([value, label]) => ({ value, label })),
]

const ACTION_META: Record<string, { label: string; tone: AppBadgeTone }> = {
  create: { label: 'Alta', tone: 'success' },
  update: { label: 'Actualización', tone: 'info' },
  delete: { label: 'Eliminación', tone: 'danger' },
  status_change: { label: 'Cambio de estado', tone: 'warning' },
  login: { label: 'Inicio de sesión', tone: 'info' },
  logout: { label: 'Cierre de sesión', tone: 'neutral' },
  revoke_session: { label: 'Revocación de sesión', tone: 'warning' },
  purge_logs: { label: 'Depuración', tone: 'danger' },
}

const ACTION_OPTIONS = [
  { value: ALL, label: 'Todas' },
  ...Object.entries(ACTION_META).map(([value, meta]) => ({
    value,
    label: meta.label,
    tone: meta.tone,
  })),
]

function moduleLabel(moduleKey: string): string {
  return MODULE_LABELS[moduleKey] ?? moduleKey
}

function actionMeta(action: string): { label: string; tone: AppBadgeTone } {
  return ACTION_META[action] ?? { label: action || '—', tone: 'neutral' }
}

function formatWhen(value: string): string {
  if (!value) return '—'
  try {
    return formatDateTimeEsMx(value)
  } catch {
    return value
  }
}

function formatDiffValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function diffRows(log: AuditLogItem): Array<{ key: string; before: string; after: string; changed: boolean }> {
  const keys = [
    ...new Set([
      ...Object.keys(log.old_values ?? {}),
      ...Object.keys(log.new_values ?? {}),
    ]),
  ].sort((a, b) => a.localeCompare(b, 'es'))

  return keys.map((key) => {
    const before = formatDiffValue(log.old_values?.[key])
    const after = formatDiffValue(log.new_values?.[key])
    return { key, before, after, changed: before !== after }
  })
}

/** Recorre las páginas de usuarios (máx. 100 por llamada) para el filtro. */
async function fetchUsersForFilter(): Promise<ManagedUser[]> {
  const collected: ManagedUser[] = []
  let page = 1
  let totalPages = 1
  do {
    const result = await fetchManagedUsers({ page, per_page: 100 })
    collected.push(...result.items)
    totalPages = result.total_pages
    page += 1
  } while (page <= totalPages && page <= 20)
  return collected
}

/**
 * Bitácora de auditoría — solo lectura, paginada en servidor.
 * Permiso `config_audit_logs`.
 */
export function AuditLogsPage() {
  const { canView } = usePermissions()
  const { pageSize: preferredSize, rowPaddingClass, setPageSize } = useScreenPreferences()

  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  const [search, setSearch] = useState('')
  const [moduleKey, setModuleKey] = useState(ALL)
  const [action, setAction] = useState(ALL)
  const [userId, setUserId] = useState(ALL)
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selected, setSelected] = useState<AuditLogItem | null>(null)

  const usersQuery = useQuery({
    queryKey: ['rbac', 'users', 'audit-filter'],
    queryFn: fetchUsersForFilter,
    enabled: canView('config_audit_logs'),
    retry: false,
    staleTime: 60_000,
  })

  const logsQuery = useQuery({
    queryKey: [
      ...AUDIT_LOGS_QUERY_KEY,
      page,
      pageSize,
      search,
      moduleKey,
      action,
      userId,
      dateFrom,
      dateTo,
    ],
    queryFn: () =>
      fetchAuditLogs({
        page,
        per_page: pageSize,
        search: search.trim() || undefined,
        module_key: moduleKey === ALL ? undefined : moduleKey,
        action: action === ALL ? undefined : action,
        user_id: userId === ALL ? undefined : userId,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
      }),
    enabled: canView('config_audit_logs'),
  })

  const items = logsQuery.data?.items ?? []
  const auditEnabled = logsQuery.data?.audit_logs_enabled ?? true
  const userOptions = [
    { value: ALL, label: 'Todos' },
    ...(usersQuery.data ?? []).map((user) => ({
      value: String(user.id),
      label: user.email ? `${user.name} · ${user.email}` : user.name,
    })),
  ]

  function clearFilters() {
    setSearch('')
    setModuleKey(ALL)
    setAction(ALL)
    setUserId(ALL)
    setDateFrom('')
    setDateTo('')
    setPage(1)
  }

  const selectedMeta = selected ? actionMeta(selected.action) : null
  const selectedRows = selected ? diffRows(selected) : []

  return (
    <div className="space-y-5">
      <header className="flex items-start gap-3">
        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
          <History className="h-5 w-5" />
        </span>
        <div>
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Bitácora</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Historial de auditoría de solo lectura: quién cambió qué, desde dónde y cuándo.
          </p>
        </div>
      </header>

      {logsQuery.data && !auditEnabled ? (
        <AppCard className="border-amber-400/50 bg-amber-500/[0.07] p-4 text-sm text-amber-950 dark:border-amber-400/35 dark:bg-amber-400/10 dark:text-amber-100">
          El registro automático está en pausa. Los eventos nuevos no se guardan hasta que se
          reactive en Sistema. El historial que ya existe sigue visible.
        </AppCard>
      ) : null}

      <AppCard className="space-y-3 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <AppFilterField label="Búsqueda" className="min-w-[16rem] flex-1">
            <AppSearchInput
              value={search}
              placeholder="Descripción, IP, usuario o identificador…"
              aria-label="Buscar en la bitácora"
              onChange={(event) => {
                setSearch(event.target.value)
                setPage(1)
              }}
              onClear={() => {
                setSearch('')
                setPage(1)
              }}
            />
          </AppFilterField>
          <AppFilterField label="Módulo">
            <AppSelect
              aria-label="Filtrar por módulo"
              className="w-auto min-w-[11rem]"
              triggerClassName="h-10 py-2 text-sm"
              value={moduleKey}
              options={MODULE_OPTIONS}
              onValueChange={(value) => {
                setModuleKey(value)
                setPage(1)
              }}
            />
          </AppFilterField>
          <AppFilterField label="Acción">
            <AppSelect
              aria-label="Filtrar por acción"
              className="w-auto min-w-[12rem]"
              triggerClassName="h-10 py-2 text-sm"
              value={action}
              options={ACTION_OPTIONS}
              onValueChange={(value) => {
                setAction(value)
                setPage(1)
              }}
            />
          </AppFilterField>
          <AppFilterField label="Usuario">
            <AppSelect
              aria-label="Filtrar por usuario"
              className="w-auto min-w-[14rem]"
              triggerClassName="h-10 py-2 text-sm"
              value={userId}
              options={userOptions}
              placeholder="Todos"
              onValueChange={(value) => {
                setUserId(value)
                setPage(1)
              }}
            />
          </AppFilterField>
          <AppFilterField label="Desde">
            <AppInput
              type="date"
              aria-label="Fecha desde"
              value={dateFrom}
              onChange={(event) => {
                setDateFrom(event.target.value)
                setPage(1)
              }}
            />
          </AppFilterField>
          <AppFilterField label="Hasta">
            <AppInput
              type="date"
              aria-label="Fecha hasta"
              value={dateTo}
              onChange={(event) => {
                setDateTo(event.target.value)
                setPage(1)
              }}
            />
          </AppFilterField>
          <AppButton type="button" variant="outline" onClick={clearFilters}>
            Limpiar
          </AppButton>
        </div>
        {usersQuery.isError ? (
          <p className="text-xs text-amber-700 dark:text-amber-300">
            No se pudo cargar el catálogo de usuarios. El resto de filtros sigue disponible.
          </p>
        ) : null}
      </AppCard>

      <AppTableWrapper
        isLoading={logsQuery.isLoading}
        loadingState={<Skeleton className="h-56 w-full rounded-none" />}
        isEmpty={!logsQuery.isLoading && (logsQuery.isError || items.length === 0)}
        emptyState={
          <p className="px-4 py-10 text-center text-sm text-slate-500">
            {logsQuery.isError
              ? 'No se pudo cargar la bitácora.'
              : 'No hay registros con estos filtros.'}
          </p>
        }
        footer={
          <PaginationBar
            page={page}
            total={logsQuery.data?.total ?? 0}
            pageSize={pageSize}
            onPageChange={setPage}
            onPageSizeChange={(size) => {
              setLocalPageSize(size)
              setPageSize(size)
              setPage(1)
            }}
          />
        }
      >
        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-sm">
            <thead>
              <AppTableHeadRow>
                <th className="px-4 py-3 font-medium">Fecha</th>
                <th className="px-4 py-3 font-medium">Usuario</th>
                <th className="px-4 py-3 font-medium">Módulo</th>
                <th className="px-4 py-3 font-medium">Acción</th>
                <th className="px-4 py-3 font-medium">Descripción</th>
                <th className="px-4 py-3 font-medium">IP</th>
                <th className="px-4 py-3 font-medium" />
              </AppTableHeadRow>
            </thead>
            <tbody>
              {items.map((row) => {
                const meta = actionMeta(row.action)
                return (
                  <tr
                    key={row.id}
                    className="border-b border-slate-100 dark:border-white/[0.04]"
                  >
                    <td className={`${rowPaddingClass} whitespace-nowrap font-mono text-xs tabular-nums`}>
                      {formatWhen(row.created_at)}
                    </td>
                    <td className={rowPaddingClass}>
                      <p className="font-medium">{row.user_name || 'Sistema'}</p>
                      {row.user_email ? (
                        <p className="font-mono text-xs text-slate-500">{row.user_email}</p>
                      ) : null}
                    </td>
                    <td className={rowPaddingClass}>{moduleLabel(row.module_key)}</td>
                    <td className={rowPaddingClass}>
                      <AppBadge label={meta.label} tone={meta.tone} />
                    </td>
                    <td className={`${rowPaddingClass} max-w-xs`}>
                      <p className="line-clamp-2">{row.description}</p>
                    </td>
                    <td className={`${rowPaddingClass} font-mono text-xs`}>{row.ip_address}</td>
                    <td className={`${rowPaddingClass} text-right`}>
                      <AppButton
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setSelected(row)}
                      >
                        Ver cambios
                      </AppButton>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div className="space-y-3 p-4 md:hidden">
          {items.map((row) => {
            const meta = actionMeta(row.action)
            return (
              <AppCard key={row.id} className="space-y-2 p-3">
                <div className="flex items-start justify-between gap-2">
                  <p className="font-mono text-xs tabular-nums text-slate-500">
                    {formatWhen(row.created_at)}
                  </p>
                  <AppBadge label={meta.label} tone={meta.tone} />
                </div>
                <p className="font-medium">{row.user_name || 'Sistema'}</p>
                <p className="text-xs text-slate-500">
                  {moduleLabel(row.module_key)} ·{' '}
                  <span className="font-mono">{row.ip_address}</span>
                </p>
                <p className="text-sm">{row.description}</p>
                <AppButton
                  type="button"
                  variant="outline"
                  size="sm"
                  className="w-full"
                  onClick={() => setSelected(row)}
                >
                  Ver cambios
                </AppButton>
              </AppCard>
            )
          })}
        </div>
      </AppTableWrapper>

      <AppModal
        open={selected != null}
        onOpenChange={(open) => {
          if (!open) setSelected(null)
        }}
      >
        <AppModalContent className="max-w-4xl">
          {selected && selectedMeta ? (
            <>
              <AppModalHeader>
                <AppModalTitle>Cambios del evento</AppModalTitle>
                <AppModalDescription>
                  {formatWhen(selected.created_at)} · {moduleLabel(selected.module_key)} ·{' '}
                  {selected.entity_type}
                  {selected.entity_id ? ` #${selected.entity_id}` : ''}
                </AppModalDescription>
              </AppModalHeader>

              <div className="flex flex-wrap items-center gap-2">
                <AppBadge label={selectedMeta.label} tone={selectedMeta.tone} />
                <span className="text-sm text-slate-600 dark:text-zinc-300">
                  {selected.description}
                </span>
              </div>

              {selectedRows.length === 0 ? (
                <p className="text-sm text-slate-500">
                  Este evento no guardó valores anteriores ni nuevos.
                </p>
              ) : (
                <div className="max-h-80 overflow-auto rounded-xl border border-slate-200/80 dark:border-white/[0.09]">
                  <table className="w-full text-left text-xs">
                    <thead>
                      <AppTableHeadRow>
                        <th className="px-3 py-2 font-medium">Campo</th>
                        <th className="px-3 py-2 font-medium">Antes</th>
                        <th className="px-3 py-2 font-medium">Después</th>
                      </AppTableHeadRow>
                    </thead>
                    <tbody>
                      {selectedRows.map((row) => (
                        <tr
                          key={row.key}
                          className={
                            row.changed
                              ? 'bg-amber-500/10'
                              : 'border-b border-slate-100 dark:border-white/[0.04]'
                          }
                        >
                          <td className="px-3 py-2 font-mono">{row.key}</td>
                          <td className="px-3 py-2 font-mono break-all text-slate-500">
                            {row.before}
                          </td>
                          <td className="px-3 py-2 font-mono break-all">{row.after}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <dl className="grid gap-3 text-xs sm:grid-cols-2">
                <div>
                  <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
                    Dirección IP
                  </dt>
                  <dd className="mt-1 font-mono">{selected.ip_address || '—'}</dd>
                </div>
                <div>
                  <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
                    Agente de usuario
                  </dt>
                  <dd className="mt-1 break-all font-mono">{selected.user_agent || '—'}</dd>
                </div>
              </dl>
            </>
          ) : null}
        </AppModalContent>
      </AppModal>
    </div>
  )
}
