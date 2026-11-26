import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Save, Shield } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppDrawer } from '@/components/ui/AppDrawer'
import { AppInput } from '@/components/ui/AppInput'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/context/AuthContext'
import { useToast } from '@/context/ToastContext'
import { usePermissions } from '@/hooks/usePermissions'
import { scheduleCriticalRelogin } from '@/lib/criticalRelogin'
import { cn } from '@/lib/utils'
import {
  createRole,
  fetchRoles,
  fetchSystemModules,
  saveRolePermissions,
} from '@/services/rbacService'
import {
  isCrudActionApplicable,
  type ModulePermission,
  type PermissionsMap,
} from '@/types/auth'

const EMPTY_PERM: ModulePermission = {
  view: false,
  create: false,
  edit: false,
  delete: false,
}

const CRUD_KEYS: Array<{ key: keyof ModulePermission; label: string }> = [
  { key: 'view', label: 'Ver' },
  { key: 'create', label: 'Crear' },
  { key: 'edit', label: 'Editar' },
  { key: 'delete', label: 'Eliminar' },
]

function clonePerms(source: PermissionsMap): Record<string, ModulePermission> {
  const out: Record<string, ModulePermission> = {}
  for (const [moduleKey, flags] of Object.entries(source ?? {})) {
    out[moduleKey] = { ...(flags ?? EMPTY_PERM) }
  }
  return out
}

function permsEqual(
  a: Record<string, ModulePermission>,
  b: Record<string, ModulePermission>,
): boolean {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)])
  for (const key of keys) {
    const left = a[key] ?? EMPTY_PERM
    const right = b[key] ?? EMPTY_PERM
    if (
      left.view !== right.view ||
      left.create !== right.create ||
      left.edit !== right.edit ||
      left.delete !== right.delete
    ) {
      return false
    }
  }
  return true
}

/**
 * Roles y matriz multinivel — sin rol preseleccionado al entrar.
 */
export function RolesPermissionsPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { logout } = useAuth()
  const { canCreate, canEdit, roleId } = usePermissions()

  /** REGLA UX: siempre inicia en null (cero preselección). */
  const [selectedRoleId, setSelectedRoleId] = useState<number | null>(null)
  const [draftPerms, setDraftPerms] = useState<Record<string, ModulePermission>>({})
  const [newRoleOpen, setNewRoleOpen] = useState(false)
  const [newRoleCodigo, setNewRoleCodigo] = useState('')
  const [newRoleNombre, setNewRoleNombre] = useState('')
  const [newRoleDesc, setNewRoleDesc] = useState('')

  const rolesQuery = useQuery({ queryKey: ['rbac', 'roles'], queryFn: fetchRoles })
  const modulesQuery = useQuery({
    queryKey: ['rbac', 'modules'],
    queryFn: fetchSystemModules,
  })

  const selectedRole = useMemo(
    () => rolesQuery.data?.find((r) => r.id === selectedRoleId) ?? null,
    [rolesQuery.data, selectedRoleId],
  )

  useEffect(() => {
    if (selectedRole) {
      setDraftPerms(clonePerms(selectedRole.permissions))
    } else {
      setDraftPerms({})
    }
  }, [selectedRole])

  const isDirty =
    selectedRole != null && !permsEqual(draftPerms, clonePerms(selectedRole.permissions))

  // group_key canónico en inglés (main | configuration).
  const principalModules = (modulesQuery.data ?? []).filter((m) => m.group_key === 'main')
  const configModules = (modulesQuery.data ?? []).filter((m) => m.group_key === 'configuration')

  const savePermsMutation = useMutation({
    mutationFn: () => {
      if (!selectedRole) throw new Error('Sin rol seleccionado')
      return saveRolePermissions(selectedRole.id, draftPerms)
    },
    onSuccess: async (saved) => {
      toast.success('Permisos guardados', selectedRole?.name ?? '')
      await queryClient.invalidateQueries({ queryKey: ['rbac', 'roles'] })

      const isOwnRole = roleId != null && selectedRole != null && selectedRole.id === roleId
      if (isOwnRole) {
        scheduleCriticalRelogin({
          warning: toast.warning,
          logout,
          navigateToLogin: () => navigate('/login', { replace: true }),
        })
      } else if ((saved as { sessions_revoked?: number }).sessions_revoked) {
        toast.info(
          'Sesiones revocadas',
          'Los usuarios con este rol deberán iniciar sesión de nuevo.',
        )
      }
    },
    onError: (error) => toast.apiError(error),
  })

  const createRoleMutation = useMutation({
    mutationFn: () =>
      createRole({
        code: newRoleCodigo.trim().toLowerCase().replace(/\s+/g, '_'),
        name: newRoleNombre.trim(),
        description: newRoleDesc.trim() || undefined,
      }),
    onSuccess: async (role) => {
      toast.success('Rol creado', role.name)
      setNewRoleOpen(false)
      setNewRoleCodigo('')
      setNewRoleNombre('')
      setNewRoleDesc('')
      await queryClient.invalidateQueries({ queryKey: ['rbac', 'roles'] })
      setSelectedRoleId(role.id)
    },
    onError: (error) => toast.apiError(error),
  })

  function renderModuleRows(
    modules: Array<{ module_key: string; name: string }>,
  ): React.ReactNode {
    return modules.map((mod) => {
      const flags = draftPerms[mod.module_key] ?? EMPTY_PERM
      return (
        <tr key={mod.module_key} className="border-b border-slate-100 dark:border-white/[0.04]">
          <td className="px-4 py-3">
            <p className="font-medium">{mod.name}</p>
            <p className="font-mono text-[11px] text-slate-400">{mod.module_key}</p>
          </td>
          {CRUD_KEYS.map((col) => {
            const applicable = isCrudActionApplicable(mod.module_key, col.key)
            return (
              <td key={col.key} className="px-3 py-3 text-center">
                {applicable ? (
                  <div className="inline-flex justify-center">
                    <AppSwitch
                      checked={flags[col.key]}
                      disabled={!canEdit('config_roles')}
                      label={`${col.label} ${mod.name}`}
                      onCheckedChange={(next) => {
                        setDraftPerms((prev) => {
                          const base = { ...(prev[mod.module_key] ?? EMPTY_PERM), [col.key]: next }
                          // Fuerza a false las acciones no aplicables del módulo.
                          for (const action of ['view', 'create', 'edit', 'delete'] as const) {
                            if (!isCrudActionApplicable(mod.module_key, action)) {
                              base[action] = false
                            }
                          }
                          return { ...prev, [mod.module_key]: base }
                        })
                      }}
                    />
                  </div>
                ) : (
                  <span
                    className="select-none text-slate-300 dark:text-zinc-600"
                    title="Acción no aplicable"
                    aria-hidden
                  >
                    —
                  </span>
                )}
              </td>
            )
          })}
        </tr>
      )
    })
  }

  if (rolesQuery.isLoading || modulesQuery.isLoading) {
    return <Skeleton className="h-80 w-full rounded-2xl" />
  }

  const roles = rolesQuery.data ?? []

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-violet-500/10 text-violet-600 dark:text-violet-300">
            <Shield className="h-5 w-5" />
          </span>
          <div>
            <h1 className="font-heading text-2xl font-semibold md:text-3xl">
              Roles y permisos
            </h1>
            <p className="mt-1 text-sm text-slate-500">
              Matriz multinivel: módulos operativos y submódulos de configuración
            </p>
          </div>
        </div>
        {canCreate('config_roles') && (
          <AppButton onClick={() => setNewRoleOpen(true)}>
            <Plus className="h-4 w-4" /> Nuevo rol
          </AppButton>
        )}
      </header>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
        <AppCard className="space-y-2 p-3">
          <p className="px-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
            Roles
          </p>
          {roles.map((role) => (
            <AppButton
              key={role.id}
              type="button"
              variant="ghost"
              onClick={() => setSelectedRoleId(role.id)}
              className={cn(
                'h-auto w-full flex-col items-start rounded-xl px-3 py-2.5 text-left text-sm font-normal',
                selectedRoleId === role.id
                  ? 'border border-blue-500/25 bg-blue-50 text-blue-700 hover:bg-blue-50 dark:bg-[#0A84FF]/15 dark:text-[#0A84FF] dark:hover:bg-[#0A84FF]/15'
                  : 'hover:bg-slate-100 dark:hover:bg-white/[0.05]',
              )}
            >
              <span className="font-medium">{role.name}</span>
              <span className="font-mono text-[11px] text-slate-400">{role.code}</span>
            </AppButton>
          ))}
        </AppCard>

        <AppCard className="flex min-h-[28rem] flex-col overflow-hidden p-0">
          {selectedRoleId == null || !selectedRole ? (
            <div className="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center">
              <Shield className="h-10 w-10 text-slate-300 dark:text-zinc-600" />
              <p className="max-w-md text-sm text-slate-500 dark:text-zinc-400">
                Selecciona un rol de la lista para consultar o configurar sus permisos por módulo
                y submódulo
              </p>
            </div>
          ) : (
            <>
              <div className="border-b border-slate-200/80 px-4 py-3 dark:border-white/[0.07]">
                <h2 className="font-heading text-lg font-semibold">{selectedRole.name}</h2>
                <p className="text-sm text-slate-500">
                  {selectedRole.description ?? 'Sin descripción'}
                </p>
              </div>

              <div className="flex-1 overflow-x-auto">
                <table className="w-full min-w-[40rem] text-sm">
                  <thead>
                    <tr className="border-b border-slate-200/80 text-left text-[11px] uppercase tracking-wide text-slate-500 dark:border-white/[0.07]">
                      <th className="px-4 py-3 font-medium">Módulo</th>
                      {CRUD_KEYS.map((col) => (
                        <th key={col.key} className="px-3 py-3 text-center font-medium">
                          {col.label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="bg-slate-50/80 dark:bg-white/[0.03]">
                      <td
                        colSpan={5}
                        className="px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500"
                      >
                        1. Módulos operativos
                      </td>
                    </tr>
                    {renderModuleRows(principalModules)}
                    <tr className="bg-slate-50/80 dark:bg-white/[0.03]">
                      <td
                        colSpan={5}
                        className="px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500"
                      >
                        2. Submódulos de configuración
                      </td>
                    </tr>
                    {renderModuleRows(configModules)}
                  </tbody>
                </table>
              </div>

              <footer className="flex items-center justify-between gap-3 border-t border-slate-200/80 px-4 py-3 dark:border-white/[0.07]">
                <p className="text-sm text-slate-500">
                  {isDirty ? 'Hay cambios sin guardar' : 'Sin cambios pendientes'}
                </p>
                {canEdit('config_roles') && (
                  <AppButton
                    type="button"
                    disabled={!isDirty}
                    isLoading={savePermsMutation.isPending}
                    onClick={() => savePermsMutation.mutate()}
                  >
                    <Save className="h-4 w-4" />
                    Guardar permisos
                  </AppButton>
                )}
              </footer>
            </>
          )}
        </AppCard>
      </div>

      <AppDrawer
        open={newRoleOpen}
        onClose={() => setNewRoleOpen(false)}
        size="lg"
        title="Nuevo rol"
        description="Se crea con permisos apagados; luego configuras la matriz multinivel."
        footer={
          <>
            <AppButton
              type="button"
              variant="outline"
              disabled={createRoleMutation.isPending}
              onClick={() => setNewRoleOpen(false)}
            >
              Cancelar
            </AppButton>
            <AppButton
              type="button"
              isLoading={createRoleMutation.isPending}
              onClick={() => createRoleMutation.mutate()}
            >
              Crear rol
            </AppButton>
          </>
        }
      >
        <div className="min-w-0 space-y-4">
          <label className="block min-w-0 text-sm">
            Código
            <AppInput
              withLabelSpacing
              mono
              value={newRoleCodigo}
              onChange={(e) => setNewRoleCodigo(e.target.value)}
              placeholder="ej. gestor_promociones"
            />
          </label>
          <label className="block min-w-0 text-sm">
            Nombre
            <AppInput
              withLabelSpacing
              value={newRoleNombre}
              onChange={(e) => setNewRoleNombre(e.target.value)}
            />
          </label>
          <label className="block min-w-0 text-sm">
            Descripción
            <AppInput
              withLabelSpacing
              value={newRoleDesc}
              onChange={(e) => setNewRoleDesc(e.target.value)}
            />
          </label>
        </div>
      </AppDrawer>
    </div>
  )
}
