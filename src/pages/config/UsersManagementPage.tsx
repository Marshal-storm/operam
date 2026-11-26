import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Users } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppDrawer } from '@/components/ui/AppDrawer'
import { AppFilterBar } from '@/components/ui/AppFilterBar'
import { AppInput } from '@/components/ui/AppInput'
import { AppRowActionsMenu } from '@/components/ui/AppRowActionsMenu'
import { AppSelect } from '@/components/ui/AppSelect'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { PaginationBar } from '@/components/ui/PaginationBar'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/context/AuthContext'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { usePermissions } from '@/hooks/usePermissions'
import { useSegments } from '@/hooks/useSegments'
import { scheduleCriticalRelogin } from '@/lib/criticalRelogin'
import {
  createManagedUser,
  fetchManagedUsers,
  fetchRoles,
  updateManagedUser,
  type ManagedUser,
} from '@/services/rbacService'

/**
 * Administración exclusiva de usuarios (rol + segmento).
 */
export function UsersManagementPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user, logout } = useAuth()
  const { canCreate, canEdit, userId } = usePermissions()
  const { pageSize: preferredSize, setPageSize } = useScreenPreferences()

  const [page, setPage] = useState(1)
  const [pageSize, setLocalPageSize] = useState(preferredSize)
  const [search, setSearch] = useState('')
  const [rolFilter, setRolFilter] = useState('Todos')
  const [estadoFilter, setEstadoFilter] = useState('Todos')
  const {
    segments,
    filterOptions,
    isSegmentLocked,
    allowedSegment,
  } = useSegments()
  const [segmentoFilter, setSegmentoFilter] = useState(
    isSegmentLocked ? allowedSegment : 'Todos',
  )

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<ManagedUser | null>(null)
  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    role_id: '',
    /** 'todos' = alcance global; cualquier otro valor es el id del segmento. */
    segment_choice: 'todos',
    is_active: true,
    can_authorize_exceptions: false,
  })

  const rolesQuery = useQuery({ queryKey: ['rbac', 'roles'], queryFn: fetchRoles })

  useEffect(() => {
    if (isSegmentLocked) setSegmentoFilter(allowedSegment)
  }, [isSegmentLocked, allowedSegment])

  const segmentFormOptions = useMemo(
    () => [
      { value: 'todos', label: 'Todos' },
      ...segments.map((segmentOption) => ({
        value: String(segmentOption.id),
        label: segmentOption.name,
      })),
    ],
    [segments],
  )

  const usersQuery = useQuery({
    queryKey: [
      'rbac',
      'users',
      page,
      pageSize,
      search,
      rolFilter,
      segmentoFilter,
      estadoFilter,
    ],
    queryFn: () =>
      fetchManagedUsers({
        page,
        per_page: pageSize,
        search: search.trim() || undefined,
        role_id: rolFilter === 'Todos' ? undefined : rolFilter,
        segment: segmentoFilter === 'Todos' ? undefined : segmentoFilter,
        status:
          estadoFilter === 'Todos'
            ? undefined
            : estadoFilter === 'Activos'
              ? 'active'
              : 'inactive',
      }),
  })

  function openCreate() {
    if (!canCreate('config_users')) return
    setEditing(null)
    setForm({
      name: '',
      email: '',
      password: '',
      role_id: String(rolesQuery.data?.[0]?.id ?? ''),
      segment_choice: 'todos',
      is_active: true,
      can_authorize_exceptions: false,
    })
    setModalOpen(true)
  }

  function openEdit(row: ManagedUser) {
    if (!canEdit('config_users')) return
    setEditing(row)
    setForm({
      name: row.name,
      email: row.email,
      password: '',
      role_id: String(row.role_id ?? ''),
      segment_choice: row.segment_id == null ? 'todos' : String(row.segment_id),
      is_active: row.is_active,
      can_authorize_exceptions: Boolean(row.can_authorize_exceptions),
    })
    setModalOpen(true)
  }

  function resolveSegmentoId(): number | null {
    if (form.segment_choice === 'todos') return null
    const id = Number(form.segment_choice)
    return Number.isFinite(id) && id > 0 ? id : null
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const rolId = Number(form.role_id)
      const segmentoId = resolveSegmentoId()
      if (!form.name.trim() || !form.email.trim() || !rolId) {
        throw new Error('Nombre, correo y rol son obligatorios.')
      }
      if (editing) {
        return updateManagedUser(editing.id, {
          name: form.name.trim(),
          email: form.email.trim().toLowerCase(),
          role_id: rolId,
          segment_id: segmentoId,
          is_active: form.is_active,
          can_authorize_exceptions: form.can_authorize_exceptions,
          ...(form.password ? { password: form.password } : {}),
        })
      }
      if (!form.password) throw new Error('La contraseña es obligatoria al crear.')
      return createManagedUser({
        name: form.name.trim(),
        email: form.email.trim().toLowerCase(),
        password: form.password,
        role_id: rolId,
        segment_id: segmentoId,
        is_active: form.is_active,
        can_authorize_exceptions: form.can_authorize_exceptions,
      })
    },
    onSuccess: async (saved) => {
      toast.success(editing ? 'Usuario actualizado' : 'Usuario creado', saved.email)
      setModalOpen(false)
      if (!editing) setPage(1)
      await queryClient.invalidateQueries({ queryKey: ['rbac', 'users'] })

      const isSelf = editing != null && userId != null && editing.id === userId
      const criticalSelf =
        isSelf &&
        Boolean(
          (saved as ManagedUser & { requires_reauth?: boolean }).requires_reauth ||
            editing.role_id !== saved.role_id ||
            editing.segment_id !== saved.segment_id ||
            (editing.is_active && !saved.is_active),
        )

      if (criticalSelf) {
        scheduleCriticalRelogin({
          warning: toast.warning,
          logout,
          navigateToLogin: () => navigate('/login', { replace: true }),
        })
      }
    },
    onError: (error) => toast.apiError(error),
  })

  /** Toggle rápido de is_active desde la tabla (solo el flag). */
  const toggleActiveMutation = useMutation({
    mutationFn: ({ row, next }: { row: ManagedUser; next: boolean }) =>
      updateManagedUser(row.id, { is_active: next }),
    onSuccess: async (saved, variables) => {
      toast.success(
        variables.next ? 'Usuario activado' : 'Usuario desactivado',
        saved.email,
      )
      await queryClient.invalidateQueries({ queryKey: ['rbac', 'users'] })

      const isSelf = userId != null && variables.row.id === userId
      if (isSelf && variables.row.is_active && !saved.is_active) {
        scheduleCriticalRelogin({
          warning: toast.warning,
          logout,
          navigateToLogin: () => navigate('/login', { replace: true }),
        })
      }
    },
    onError: (error) => toast.apiError(error),
  })

  const roles = rolesQuery.data ?? []

  const hasActiveFilters =
    search.trim() !== '' ||
    rolFilter !== 'Todos' ||
    (!isSegmentLocked && segmentoFilter !== 'Todos') ||
    estadoFilter !== 'Todos'

  function clearFilters() {
    setSearch('')
    setRolFilter('Todos')
    if (!isSegmentLocked) setSegmentoFilter('Todos')
    setEstadoFilter('Todos')
    setPage(1)
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
            <Users className="h-5 w-5" />
          </span>
          <div>
            <h1 className="font-heading text-2xl font-semibold md:text-3xl">Usuarios</h1>
            <p className="mt-1 text-sm text-slate-500">
              Cuentas internas, rol asignado y alcance de segmento
            </p>
          </div>
        </div>
        {canCreate('config_users') && (
          <AppButton onClick={openCreate}>
            <Plus className="h-4 w-4" /> Nuevo usuario
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
        searchPlaceholder="Buscar por nombre, correo o rol…"
        searchLabel="BUSCAR"
        filters={[
          {
            id: 'role',
            label: 'ROL',
            value: rolFilter,
            onValueChange: (value) => {
              setRolFilter(value)
              setPage(1)
            },
            options: [
              { value: 'Todos', label: 'Todos los roles' },
              ...roles.map((r) => ({ value: String(r.id), label: r.name })),
            ],
          },
          {
            id: 'segment',
            label: 'SEGMENTO',
            value: segmentoFilter,
            onValueChange: (value) => {
              if (isSegmentLocked) return
              setSegmentoFilter(value)
              setPage(1)
            },
            options: filterOptions,
            disabled: isSegmentLocked,
          },
          {
            id: 'status',
            label: 'ESTADO',
            value: estadoFilter,
            onValueChange: (value) => {
              setEstadoFilter(value)
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

      <AppCard className="overflow-hidden p-0">
        {usersQuery.isLoading ? (
          <Skeleton className="h-56 w-full rounded-none" />
        ) : (
          <>
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200/80 text-left text-[11px] uppercase tracking-wide text-slate-500 dark:border-white/[0.07]">
                    <th className="px-4 py-3 font-medium">Usuario</th>
                    <th className="px-4 py-3 font-medium">Rol</th>
                    <th className="px-4 py-3 font-medium">Segmento</th>
                    <th className="px-4 py-3 font-medium">Estado</th>
                    <th className="px-4 py-3 text-right font-medium">ACCIONES</th>
                  </tr>
                </thead>
                <tbody>
                  {(usersQuery.data?.items ?? []).map((row) => (
                    <tr
                      key={row.id}
                      className="border-b border-slate-100 dark:border-white/[0.04]"
                    >
                      <td className="px-4 py-3">
                        <p className="font-medium">
                          {row.name}
                          {user?.id === row.id && (
                            <span className="ml-2 text-[10px] font-semibold uppercase text-blue-500">
                              tú
                            </span>
                          )}
                        </p>
                        <p className="font-mono text-xs text-slate-500">{row.email}</p>
                      </td>
                      <td className="px-4 py-3">{row.role_name ?? row.role_code}</td>
                      <td className="px-4 py-3">
                        <AppBadge
                          label={
                            row.segment_id == null
                              ? 'Todos'
                              : (row.segment_name || 'Sin segmento')
                          }
                          tone={row.segment_id == null ? 'info' : 'neutral'}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <AppSwitch
                          checked={row.is_active}
                          caption={row.is_active ? 'Activo' : 'Inactivo'}
                          disabled={
                            !canEdit('config_users') || toggleActiveMutation.isPending
                          }
                          onCheckedChange={(next) =>
                            toggleActiveMutation.mutate({ row, next })
                          }
                        />
                      </td>
                      <td className="px-4 py-3 text-right">
                        <AppRowActionsMenu
                          actions={[
                            {
                              label: 'Editar',
                              icon: <Pencil className="h-4 w-4" />,
                              onClick: () => openEdit(row),
                              hidden: !canEdit('config_users'),
                            },
                          ]}
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="space-y-3 p-4 md:hidden">
              {(usersQuery.data?.items ?? []).map((row) => (
                <AppCard key={row.id} className="p-3">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="font-medium">{row.name}</p>
                      <p className="font-mono text-xs text-slate-500">{row.email}</p>
                      <p className="mt-2 text-xs text-slate-500">
                        {row.role_name ?? row.role_code} ·{' '}
                        {row.segment_id == null
                          ? 'Todos'
                          : row.segment_name || 'Sin segmento'}
                      </p>
                    </div>
                    <AppRowActionsMenu
                      actions={[
                        {
                          label: 'Editar',
                          icon: <Pencil className="h-4 w-4" />,
                          onClick: () => openEdit(row),
                          hidden: !canEdit('config_users'),
                        },
                      ]}
                    />
                  </div>
                  <div className="mt-3">
                    <AppSwitch
                      checked={row.is_active}
                      caption={row.is_active ? 'Activo' : 'Inactivo'}
                      disabled={
                        !canEdit('config_users') || toggleActiveMutation.isPending
                      }
                      onCheckedChange={(next) =>
                        toggleActiveMutation.mutate({ row, next })
                      }
                    />
                  </div>
                </AppCard>
              ))}
            </div>

            <div className="px-4 pb-4">
              <PaginationBar
                page={page}
                total={usersQuery.data?.total ?? 0}
                pageSize={pageSize}
                onPageChange={setPage}
                onPageSizeChange={(size) => {
                  setLocalPageSize(size)
                  setPageSize(size)
                  setPage(1)
                }}
              />
            </div>
          </>
        )}
      </AppCard>

      <AppDrawer
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        size="lg"
        title={editing ? 'Editar usuario' : 'Nuevo usuario'}
        description="Asigna rol y el segmento que podrá ver en todo el ERP."
        footer={
          <>
            <AppButton
              type="button"
              variant="outline"
              disabled={saveMutation.isPending}
              onClick={() => setModalOpen(false)}
            >
              Cancelar
            </AppButton>
            <AppButton
              type="button"
              isLoading={saveMutation.isPending}
              onClick={() => saveMutation.mutate()}
            >
              {editing ? 'Guardar usuario' : 'Crear usuario'}
            </AppButton>
          </>
        }
      >
        <div className="min-w-0 space-y-4">
          <label className="block min-w-0 text-sm">
            Nombre
            <AppInput
              withLabelSpacing
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            />
          </label>
          <label className="block min-w-0 text-sm">
            Correo
            <AppInput
              type="email"
              withLabelSpacing
              value={form.email}
              onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
            />
          </label>
          <label className="block min-w-0 text-sm">
            Contraseña{editing ? ' (opcional)' : ''}
            <AppInput
              type="password"
              withLabelSpacing
              value={form.password}
              onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
            />
          </label>
          <label className="block min-w-0 text-sm">
            Rol
            <AppSelect
              withLabelSpacing
              value={form.role_id || undefined}
              onValueChange={(v) => setForm((f) => ({ ...f, role_id: v }))}
              placeholder="Selecciona un rol…"
              options={roles.map((role) => ({
                value: String(role.id),
                label: role.name,
              }))}
            />
          </label>
          <label className="block min-w-0 text-sm">
            Segmento asignado
            <AppSelect
              withLabelSpacing
              value={form.segment_choice}
              onValueChange={(v) =>
                setForm((f) => ({
                  ...f,
                  segment_choice: v,
                }))
              }
              options={segmentFormOptions}
            />
          </label>
          <label className="flex items-center gap-3 text-sm">
            <AppSwitch
              checked={form.is_active}
              onCheckedChange={(next) => setForm((f) => ({ ...f, is_active: next }))}
              label="Usuario activo"
            />
            Usuario activo
          </label>
          <div className="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-3 dark:border-white/[0.08] dark:bg-white/[0.03]">
            <label className="flex items-start gap-3 text-sm">
              <AppSwitch
                checked={form.can_authorize_exceptions}
                onCheckedChange={(next) =>
                  setForm((f) => ({ ...f, can_authorize_exceptions: next }))
                }
                label="Puede autorizar excepciones"
              />
              <span>
                <span className="font-medium">
                  Puede autorizar excepciones comerciales (RF-11.4)
                </span>
                <span className="mt-1 block text-xs text-slate-500 dark:text-slate-400">
                  Permite brincarse políticas comerciales (saldo vencido, fechas de baja,
                  suspensión) registrando motivo obligatorio en el reporte de Dirección
                </span>
              </span>
            </label>
          </div>
        </div>
      </AppDrawer>
    </div>
  )
}
