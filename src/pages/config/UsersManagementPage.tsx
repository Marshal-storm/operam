import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Search, Users } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
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
import { useAuth } from '@/context/AuthContext'
import { useScreenPreferences } from '@/context/ScreenPreferencesContext'
import { useToast } from '@/context/ToastContext'
import { usePermissions } from '@/hooks/usePermissions'
import { scheduleCriticalRelogin } from '@/lib/criticalRelogin'
import { cn } from '@/lib/utils'
import { fetchSegments } from '@/services/catalogService'
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
  const [segmentoFilter, setSegmentoFilter] = useState('Todos')
  const [estadoFilter, setEstadoFilter] = useState('Todos')

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<ManagedUser | null>(null)
  const [form, setForm] = useState({
    nombre: '',
    email: '',
    password: '',
    rol_id: '',
    segmento_mode: 'todos' as 'todos' | 'operam' | 'construsync',
    activo: true,
  })

  const rolesQuery = useQuery({ queryKey: ['rbac', 'roles'], queryFn: fetchRoles })
  const segmentsQuery = useQuery({ queryKey: ['segments'], queryFn: fetchSegments })

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
        rol_id: rolFilter === 'Todos' ? undefined : rolFilter,
        segmento:
          segmentoFilter === 'Todos'
            ? undefined
            : segmentoFilter === 'Todos los segmentos'
              ? 'sin_segmento'
              : segmentoFilter,
        estado:
          estadoFilter === 'Todos'
            ? undefined
            : estadoFilter === 'Activos'
              ? 'activos'
              : 'inactivos',
      }),
  })

  function openCreate() {
    if (!canCreate('config_usuarios')) return
    setEditing(null)
    setForm({
      nombre: '',
      email: '',
      password: '',
      rol_id: String(rolesQuery.data?.[0]?.id ?? ''),
      segmento_mode: 'todos',
      activo: true,
    })
    setModalOpen(true)
  }

  function openEdit(row: ManagedUser) {
    if (!canEdit('config_usuarios')) return
    const mode =
      row.segmento_nombre === 'Operam'
        ? 'operam'
        : row.segmento_nombre === 'Construsync'
          ? 'construsync'
          : 'todos'
    setEditing(row)
    setForm({
      nombre: row.nombre,
      email: row.email,
      password: '',
      rol_id: String(row.rol_id ?? ''),
      segmento_mode: mode,
      activo: row.activo,
    })
    setModalOpen(true)
  }

  function resolveSegmentoId(): number | null {
    if (form.segmento_mode === 'todos') return null
    const name = form.segmento_mode === 'operam' ? 'Operam' : 'Construsync'
    return segmentsQuery.data?.find((s) => s.nombre === name)?.id ?? null
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const rolId = Number(form.rol_id)
      const segmentoId = resolveSegmentoId()
      if (!form.nombre.trim() || !form.email.trim() || !rolId) {
        throw new Error('Nombre, correo y rol son obligatorios.')
      }
      if (editing) {
        return updateManagedUser(editing.id, {
          nombre: form.nombre.trim(),
          email: form.email.trim().toLowerCase(),
          rol_id: rolId,
          segmento_id: segmentoId,
          activo: form.activo,
          ...(form.password ? { password: form.password } : {}),
        })
      }
      if (!form.password) throw new Error('La contraseña es obligatoria al crear.')
      return createManagedUser({
        nombre: form.nombre.trim(),
        email: form.email.trim().toLowerCase(),
        password: form.password,
        rol_id: rolId,
        segmento_id: segmentoId,
        activo: form.activo,
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
            editing.rol_id !== saved.rol_id ||
            editing.segmento_id !== saved.segmento_id ||
            (editing.activo && !saved.activo),
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
        {canCreate('config_usuarios') && (
          <Button className="rounded-xl" onClick={openCreate}>
            <Plus className="mr-1 h-4 w-4" /> Nuevo usuario
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
            placeholder="Buscar por nombre, correo o rol…"
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
          />
        </div>
        <div className="flex flex-wrap gap-2">
          <select
            className="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs dark:border-white/[0.08] dark:bg-white/[0.05]"
            value={rolFilter}
            onChange={(e) => {
              setRolFilter(e.target.value)
              setPage(1)
            }}
          >
            <option value="Todos">Todos los roles</option>
            {roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.nombre}
              </option>
            ))}
          </select>
          <div className="ios-pill-track">
            {['Todos', 'Todos los segmentos', 'Operam', 'Construsync'].map((opt) => (
              <button
                key={opt}
                type="button"
                onClick={() => {
                  setSegmentoFilter(opt)
                  setPage(1)
                }}
                className={cn(
                  'rounded-xl px-2.5 py-1 text-xs',
                  segmentoFilter === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                )}
              >
                {opt === 'Todos los segmentos' ? 'Alcance Todos' : opt}
              </button>
            ))}
          </div>
          <div className="ios-pill-track">
            {['Todos', 'Activos', 'Inactivos'].map((opt) => (
              <button
                key={opt}
                type="button"
                onClick={() => {
                  setEstadoFilter(opt)
                  setPage(1)
                }}
                className={cn(
                  'rounded-xl px-2.5 py-1 text-xs',
                  estadoFilter === opt ? 'ios-pill-active' : 'text-slate-600 dark:text-slate-300',
                )}
              >
                {opt}
              </button>
            ))}
          </div>
        </div>
      </div>

      <section className="glass-panel overflow-hidden p-0">
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
                          {row.nombre}
                          {user?.id === row.id && (
                            <span className="ml-2 text-[10px] font-semibold uppercase text-blue-500">
                              tú
                            </span>
                          )}
                        </p>
                        <p className="font-mono text-xs text-slate-500">{row.email}</p>
                      </td>
                      <td className="px-4 py-3">{row.rol_nombre ?? row.rol}</td>
                      <td className="px-4 py-3">
                        <StatusPill
                          label={
                            row.segmento_id == null
                              ? 'Todos (Operam + Construsync)'
                              : row.segmento_nombre
                          }
                          tone={row.segmento_id == null ? 'info' : 'neutral'}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <StatusPill
                          label={row.activo ? 'Activo' : 'Inactivo'}
                          tone={row.activo ? 'positive' : 'negative'}
                        />
                      </td>
                      <td className="px-4 py-3 text-right">
                        {canEdit('config_usuarios') && (
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="rounded-xl"
                            onClick={() => openEdit(row)}
                          >
                            Editar
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="space-y-3 p-4 md:hidden">
              {(usersQuery.data?.items ?? []).map((row) => (
                <article
                  key={row.id}
                  className="rounded-2xl border border-slate-200/80 p-3 dark:border-white/[0.08]"
                >
                  <p className="font-medium">{row.nombre}</p>
                  <p className="font-mono text-xs text-slate-500">{row.email}</p>
                  <p className="mt-2 text-xs text-slate-500">
                    {row.rol_nombre ?? row.rol} ·{' '}
                    {row.segmento_id == null ? 'Todos' : row.segmento_nombre}
                  </p>
                  {canEdit('config_usuarios') && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="mt-3 w-full rounded-xl"
                      onClick={() => openEdit(row)}
                    >
                      Editar
                    </Button>
                  )}
                </article>
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
      </section>

      <Dialog open={modalOpen} onOpenChange={setModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? 'Editar usuario' : 'Nuevo usuario'}</DialogTitle>
            <DialogDescription>
              Asigna rol y el segmento que podrá ver en todo el ERP.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <label className="block text-sm">
              Nombre
              <input
                className="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
                value={form.nombre}
                onChange={(e) => setForm((f) => ({ ...f, nombre: e.target.value }))}
              />
            </label>
            <label className="block text-sm">
              Correo
              <input
                type="email"
                className="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
                value={form.email}
                onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
              />
            </label>
            <label className="block text-sm">
              Contraseña{editing ? ' (opcional)' : ''}
              <input
                type="password"
                className="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
                value={form.password}
                onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
              />
            </label>
            <label className="block text-sm">
              Rol
              <select
                className="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
                value={form.rol_id}
                onChange={(e) => setForm((f) => ({ ...f, rol_id: e.target.value }))}
              >
                {roles.map((role) => (
                  <option key={role.id} value={role.id}>
                    {role.nombre}
                  </option>
                ))}
              </select>
            </label>
            <label className="block text-sm">
              Segmento asignado
              <select
                className="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-white/[0.05]"
                value={form.segmento_mode}
                onChange={(e) =>
                  setForm((f) => ({
                    ...f,
                    segmento_mode: e.target.value as typeof f.segmento_mode,
                  }))
                }
              >
                <option value="todos">Todos (Operam + Construsync)</option>
                <option value="operam">Solo Operam</option>
                <option value="construsync">Solo Construsync</option>
              </select>
            </label>
            <label className="flex items-center gap-3 text-sm">
              <IosSwitch
                checked={form.activo}
                onCheckedChange={(next) => setForm((f) => ({ ...f, activo: next }))}
                label="Usuario activo"
              />
              Usuario activo
            </label>
            <Button
              className="w-full rounded-xl"
              disabled={saveMutation.isPending}
              onClick={() => saveMutation.mutate()}
            >
              {saveMutation.isPending
                ? 'Guardando…'
                : editing
                  ? 'Guardar usuario'
                  : 'Crear usuario'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
