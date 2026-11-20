import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Users } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
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
import { cn } from '@/lib/utils'
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
      })
    },
    onSuccess: async (saved) => {
      toast.success(editing ? 'Usuario actualizado' : 'Usuario creado', saved.email)
      setModalOpen(false)
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

  const roles = rolesQuery.data ?? []

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
          placeholder="Buscar por nombre, correo o rol…"
          aria-label="Buscar usuarios"
        />
        <div className="flex flex-wrap items-end gap-3">
          <AppFilterField label="Rol">
            <AppSelect
              aria-label="Filtrar por rol"
              className="w-auto min-w-[11rem]"
              triggerClassName="h-8 py-1 text-xs"
              value={rolFilter}
              onValueChange={(v) => {
                setRolFilter(v)
                setPage(1)
              }}
              options={[
                { value: 'Todos', label: 'Todos los roles' },
                ...roles.map((r) => ({ value: String(r.id), label: r.name })),
              ]}
            />
          </AppFilterField>
          <AppFilterField label="Segmento">
            <div className="ios-pill-track" role="group" aria-label="Segmento">
              {filterOptions.map((opt) => (
                <AppButton
                  key={opt.value}
                  type="button"
                  variant={segmentoFilter === opt.value ? 'primary' : 'ghost'}
                  size="sm"
                  disabled={isSegmentLocked}
                  onClick={() => {
                    if (isSegmentLocked) return
                    setSegmentoFilter(opt.value)
                    setPage(1)
                  }}
                  className={cn(
                    'h-auto rounded-xl px-2.5 py-1 text-xs font-normal shadow-none',
                    segmentoFilter === opt.value
                      ? 'ios-pill-active'
                      : 'text-slate-600 dark:text-slate-300',
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
              {['Todos', 'Activos', 'Inactivos'].map((opt) => (
                <AppButton
                  key={opt}
                  type="button"
                  variant={estadoFilter === opt ? 'primary' : 'ghost'}
                  size="sm"
                  onClick={() => {
                    setEstadoFilter(opt)
                    setPage(1)
                  }}
                  className={cn(
                    'h-auto rounded-xl px-2.5 py-1 text-xs font-normal shadow-none',
                    estadoFilter === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                  )}
                >
                  {opt}
                </AppButton>
              ))}
            </div>
          </AppFilterField>
        </div>
      </AppCard>

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
                    <th className="px-4 py-3 font-medium" />
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
                        <AppBadge
                          label={row.is_active ? 'Activo' : 'Inactivo'}
                          tone={row.is_active ? 'success' : 'danger'}
                        />
                      </td>
                      <td className="px-4 py-3 text-right">
                        {canEdit('config_users') && (
                          <AppButton
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => openEdit(row)}
                          >
                            Editar
                          </AppButton>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="space-y-3 p-4 md:hidden">
              {(usersQuery.data?.items ?? []).map((row) => (
                <AppCard key={row.id} className="p-3">
                  <p className="font-medium">{row.name}</p>
                  <p className="font-mono text-xs text-slate-500">{row.email}</p>
                  <p className="mt-2 text-xs text-slate-500">
                    {row.role_name ?? row.role_code} ·{' '}
                    {row.segment_id == null ? 'Todos' : row.segment_name || 'Sin segmento'}
                  </p>
                  {canEdit('config_users') && (
                    <AppButton
                      type="button"
                      variant="outline"
                      size="sm"
                      className="mt-3 w-full"
                      onClick={() => openEdit(row)}
                    >
                      Editar
                    </AppButton>
                  )}
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

      <AppModal open={modalOpen} onOpenChange={setModalOpen}>
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>{editing ? 'Editar usuario' : 'Nuevo usuario'}</AppModalTitle>
            <AppModalDescription>
              Asigna rol y el segmento que podrá ver en todo el ERP.
            </AppModalDescription>
          </AppModalHeader>
          <div className="space-y-3">
            <label className="block text-sm">
              Nombre
              <AppInput
                withLabelSpacing
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              />
            </label>
            <label className="block text-sm">
              Correo
              <AppInput
                type="email"
                withLabelSpacing
                value={form.email}
                onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
              />
            </label>
            <label className="block text-sm">
              Contraseña{editing ? ' (opcional)' : ''}
              <AppInput
                type="password"
                withLabelSpacing
                value={form.password}
                onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
              />
            </label>
            <label className="block text-sm">
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
            <label className="block text-sm">
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
            <AppButton
              className="w-full"
              isLoading={saveMutation.isPending}
              onClick={() => saveMutation.mutate()}
            >
              {editing ? 'Guardar usuario' : 'Crear usuario'}
            </AppButton>
          </div>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
