import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Clock, Cpu, Save } from 'lucide-react'
import { useEffect, useState } from 'react'
import { z } from 'zod'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppInput } from '@/components/ui/AppInput'
import {
  AppModal,
  AppModalContent,
  AppModalDescription,
  AppModalHeader,
  AppModalTitle,
} from '@/components/ui/AppModal'
import { AppSwitch } from '@/components/ui/AppSwitch'
import { Skeleton } from '@/components/ui/skeleton'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { useToast } from '@/context/ToastContext'
import { usePermissions } from '@/hooks/usePermissions'
import { formatDateEsMx } from '@/lib/utils'
import { positiveIntSchema } from '@/lib/validationSchemas'
import {
  fetchSystemSettings,
  purgeAuditLogs,
  SYSTEM_SETTINGS_QUERY_KEY,
  AUDIT_LOGS_QUERY_KEY,
  updateSystemSettings,
} from '@/services/systemService'

const portalTimeoutsSchema = z.object({
  portal_idle_timeout_minutes: positiveIntSchema({
    message: 'Ingresa un entero entre 1 y 240 minutos.',
    min: 1,
    max: 240,
  }),
  portal_magic_link_ttl_minutes: positiveIntSchema({
    message: 'Ingresa un entero entre 1 y 1,440 minutos.',
    min: 1,
    max: 1440,
  }),
})

/** Hoy en America/Mexico_City como `YYYY-MM-DD` para el input de depuración. */
function todayInMexicoCity(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Mexico_City',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

function formatOldest(value: string | null): string {
  if (!value) return 'Sin registros'
  try {
    return formatDateEsMx(value)
  } catch {
    return value
  }
}

/**
 * Sistema — bandera de auditoría y depuración controlada de la bitácora.
 * Permiso `config_system`: ver, editar la bandera y borrar historial (`can_delete`).
 */
export function SystemSettingsPage() {
  const toast = useToast()
  const { withScreenLock } = useLoadingLock()
  const queryClient = useQueryClient()
  const { canView, canEdit, canDelete } = usePermissions()
  const canEditSystem = canEdit('config_system')
  const canPurge = canDelete('config_system')

  const [purgeDate, setPurgeDate] = useState(todayInMexicoCity)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [idleMinutesInput, setIdleMinutesInput] = useState('5')
  const [linkTtlInput, setLinkTtlInput] = useState('15')
  const [portalFormErrors, setPortalFormErrors] = useState<{
    portal_idle_timeout_minutes?: string
    portal_magic_link_ttl_minutes?: string
  }>({})

  const settingsQuery = useQuery({
    queryKey: SYSTEM_SETTINGS_QUERY_KEY,
    queryFn: fetchSystemSettings,
    enabled: canView('config_system'),
  })

  useEffect(() => {
    if (!settingsQuery.data) return
    setIdleMinutesInput(String(settingsQuery.data.portal_idle_timeout_minutes))
    setLinkTtlInput(String(settingsQuery.data.portal_magic_link_ttl_minutes))
    setPortalFormErrors({})
  }, [settingsQuery.data])

  const saveMutation = useMutation({
    mutationFn: (auditLogsEnabled: boolean) =>
      updateSystemSettings({ audit_logs_enabled: auditLogsEnabled }),
    onSuccess: async (snapshot) => {
      queryClient.setQueryData(SYSTEM_SETTINGS_QUERY_KEY, snapshot)
      toast.success(
        snapshot.audit_logs_enabled ? 'Auditoría activada' : 'Auditoría en pausa',
        snapshot.audit_logs_enabled
          ? 'Los eventos del sistema volverán a registrarse en la bitácora.'
          : 'El registro automático quedó en pausa. La depuración sigue disponible.',
      )
    },
    onError: (error) => {
      toast.apiError(error)
    },
  })

  const savePortalTimeoutsMutation = useMutation({
    mutationFn: (input: {
      portal_idle_timeout_minutes: number
      portal_magic_link_ttl_minutes: number
    }) => updateSystemSettings(input),
    onSuccess: (snapshot) => {
      queryClient.setQueryData(SYSTEM_SETTINGS_QUERY_KEY, snapshot)
      toast.success(
        'Configuración del portal guardada',
        `Inactividad: ${snapshot.portal_idle_timeout_minutes} min · Liga mágica: ${snapshot.portal_magic_link_ttl_minutes} min.`,
      )
    },
    onError: (error) => {
      toast.apiError(error)
    },
  })

  const purgeMutation = useMutation({
    mutationFn: (upToDate: string) => purgeAuditLogs({ up_to_date: upToDate }),
    onSuccess: async (result) => {
      setConfirmOpen(false)
      await queryClient.invalidateQueries({ queryKey: SYSTEM_SETTINGS_QUERY_KEY })
      await queryClient.invalidateQueries({ queryKey: AUDIT_LOGS_QUERY_KEY })
      const count = result.deleted_count
      toast.success(
        'Historial depurado',
        count === 0
          ? 'No había registros hasta esa fecha. Quedó un registro testigo.'
          : `Se eliminaron ${count.toLocaleString('es-MX')} registro(s). Quedó un registro testigo.`,
      )
    },
    onError: (error) => {
      toast.apiError(error)
    },
  })

  async function onToggle(next: boolean) {
    if (!canEditSystem || saveMutation.isPending) return
    try {
      await withScreenLock(
        () => saveMutation.mutateAsync(next),
        'Guardando configuración del sistema...',
      )
    } catch {
      /* el toast de error vive en onError */
    }
  }

  async function onSavePortalTimeouts(event: React.FormEvent) {
    event.preventDefault()
    if (!canEditSystem || savePortalTimeoutsMutation.isPending) return

    const parsed = portalTimeoutsSchema.safeParse({
      portal_idle_timeout_minutes: idleMinutesInput,
      portal_magic_link_ttl_minutes: linkTtlInput,
    })
    if (!parsed.success) {
      const nextErrors: {
        portal_idle_timeout_minutes?: string
        portal_magic_link_ttl_minutes?: string
      } = {}
      for (const issue of parsed.error.issues) {
        const key = issue.path[0]
        if (key === 'portal_idle_timeout_minutes' || key === 'portal_magic_link_ttl_minutes') {
          nextErrors[key] = issue.message
        }
      }
      setPortalFormErrors(nextErrors)
      return
    }
    setPortalFormErrors({})

    try {
      await withScreenLock(
        () =>
          savePortalTimeoutsMutation.mutateAsync({
            portal_idle_timeout_minutes: Number(parsed.data.portal_idle_timeout_minutes),
            portal_magic_link_ttl_minutes: Number(parsed.data.portal_magic_link_ttl_minutes),
          }),
        'Guardando tiempos del Portal Cliente...',
      )
    } catch {
      /* el toast de error vive en onError */
    }
  }

  function openPurgeConfirm() {
    if (!canPurge || !/^\d{4}-\d{2}-\d{2}$/.test(purgeDate)) return
    setConfirmOpen(true)
  }

  async function confirmPurge() {
    if (!canPurge || purgeMutation.isPending) return
    try {
      await withScreenLock(
        () => purgeMutation.mutateAsync(purgeDate),
        'Depurando historial de auditoría...',
      )
    } catch {
      /* el toast de error vive en onError */
    }
  }

  const snapshot = settingsQuery.data
  const enabled = snapshot?.audit_logs_enabled ?? true
  const purgeLabel = (() => {
    try {
      return formatDateEsMx(purgeDate)
    } catch {
      return purgeDate
    }
  })()

  return (
    <div className="space-y-6">
      <header className="flex items-start gap-3">
        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
          <Cpu className="h-5 w-5" />
        </span>
        <div>
          <h1 className="font-heading text-2xl font-semibold md:text-3xl">Sistema</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Activa o pausa el registro automático y depura el historial de auditoría.
          </p>
        </div>
      </header>

      {settingsQuery.isLoading ? (
        <Skeleton className="h-48 w-full rounded-2xl" />
      ) : settingsQuery.isError || !snapshot ? (
        <AppCard className="space-y-3 p-5">
          <p className="text-sm text-slate-600 dark:text-zinc-300">
            No se pudo cargar la configuración del sistema.
          </p>
          <AppButton
            type="button"
            variant="outline"
            onClick={() => {
              void settingsQuery.refetch()
            }}
          >
            Reintentar
          </AppButton>
        </AppCard>
      ) : (
        <>
          <AppCard className="space-y-5 p-5 md:p-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <h2 className="font-heading text-base font-semibold">
                  Registro automático de auditoría
                </h2>
                <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">
                  Cuando está en pausa, los eventos nuevos no se guardan. El cambio de esta
                  bandera sí queda registrado.
                </p>
              </div>
              <div className="flex items-center gap-3">
                <AppBadge
                  label={enabled ? 'Activa' : 'En pausa'}
                  tone={enabled ? 'success' : 'warning'}
                />
                <AppSwitch
                  checked={enabled}
                  disabled={!canEditSystem || saveMutation.isPending}
                  label="Registro automático de auditoría"
                  onCheckedChange={(next) => {
                    void onToggle(next)
                  }}
                />
              </div>
            </div>

            <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="rounded-2xl border border-slate-200/80 px-4 py-3 dark:border-white/[0.09]">
                <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
                  Total de registros
                </dt>
                <dd className="mt-1 font-mono text-2xl font-semibold tabular-nums">
                  {snapshot.audit_logs_total.toLocaleString('es-MX')}
                </dd>
              </div>
              <div className="rounded-2xl border border-slate-200/80 px-4 py-3 dark:border-white/[0.09]">
                <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
                  Registro más antiguo
                </dt>
                <dd className="mt-1 font-heading text-lg font-semibold">
                  {formatOldest(snapshot.audit_logs_oldest_at)}
                </dd>
              </div>
            </dl>
          </AppCard>

          <AppCard className="space-y-5 p-5 md:p-6">
            <div className="flex items-start gap-3">
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-500/10 text-[#0066FF] dark:text-[#0A84FF]">
                <Clock className="h-5 w-5" />
              </span>
              <div className="min-w-0">
                <h2 className="font-heading text-base font-semibold">
                  Sesiones del Portal del Cliente
                </h2>
                <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">
                  Controla cuánto tiempo permanece activa la sesión del cliente sin interacción y la vigencia de cada liga mágica antes de abrirse.
                </p>
              </div>
            </div>

            <form onSubmit={(event) => void onSavePortalTimeouts(event)} className="space-y-4">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="min-w-0 space-y-1">
                  <label
                    htmlFor="portal-idle-timeout"
                    className="block text-sm font-medium text-slate-800 dark:text-zinc-100"
                  >
                    Tiempo de inactividad del portal (minutos)
                    <AppInput
                      id="portal-idle-timeout"
                      withLabelSpacing
                      mono
                      numericMode="integer"
                      value={idleMinutesInput}
                      disabled={!canEditSystem || savePortalTimeoutsMutation.isPending}
                      error={Boolean(portalFormErrors.portal_idle_timeout_minutes)}
                      onChange={(event) => {
                        setIdleMinutesInput(event.target.value)
                        if (portalFormErrors.portal_idle_timeout_minutes) {
                          setPortalFormErrors((prev) => ({
                            ...prev,
                            portal_idle_timeout_minutes: undefined,
                          }))
                        }
                      }}
                    />
                  </label>
                  {portalFormErrors.portal_idle_timeout_minutes ? (
                    <p className="text-xs text-red-500">
                      {portalFormErrors.portal_idle_timeout_minutes}
                    </p>
                  ) : (
                    <p className="text-xs text-slate-500 dark:text-zinc-400">
                      La sesión del cliente se mantiene viva mientras interactúa y expira automáticamente tras estos minutos de inactividad (1–240 min).
                    </p>
                  )}
                </div>

                <div className="min-w-0 space-y-1">
                  <label
                    htmlFor="portal-link-ttl"
                    className="block text-sm font-medium text-slate-800 dark:text-zinc-100"
                  >
                    Vigencia de liga mágica antes de abrirse (minutos)
                    <AppInput
                      id="portal-link-ttl"
                      withLabelSpacing
                      mono
                      numericMode="integer"
                      value={linkTtlInput}
                      disabled={!canEditSystem || savePortalTimeoutsMutation.isPending}
                      error={Boolean(portalFormErrors.portal_magic_link_ttl_minutes)}
                      onChange={(event) => {
                        setLinkTtlInput(event.target.value)
                        if (portalFormErrors.portal_magic_link_ttl_minutes) {
                          setPortalFormErrors((prev) => ({
                            ...prev,
                            portal_magic_link_ttl_minutes: undefined,
                          }))
                        }
                      }}
                    />
                  </label>
                  {portalFormErrors.portal_magic_link_ttl_minutes ? (
                    <p className="text-xs text-red-500">
                      {portalFormErrors.portal_magic_link_ttl_minutes}
                    </p>
                  ) : (
                    <p className="text-xs text-slate-500 dark:text-zinc-400">
                      Tiempo máximo para abrir una liga mágica de un solo uso desde que se genera (1–1,440 min).
                    </p>
                  )}
                </div>
              </div>

              {canEditSystem ? (
                <div className="flex justify-end">
                  <AppButton
                    type="submit"
                    isLoading={savePortalTimeoutsMutation.isPending}
                  >
                    <Save className="h-4 w-4" />
                    Guardar configuración
                  </AppButton>
                </div>
              ) : (
                <p className="text-xs text-slate-500 dark:text-zinc-400">
                  Tu rol tiene acceso de solo lectura a la configuración del sistema.
                </p>
              )}
            </form>
          </AppCard>

          <AppCard className="space-y-4 border-amber-400/50 bg-amber-500/[0.07] p-5 md:p-6 dark:border-amber-400/35 dark:bg-amber-400/10">
            <div className="flex items-start gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-500/15 text-amber-700 dark:text-amber-300">
                <AlertTriangle className="h-5 w-5" />
              </span>
              <div>
                <h2 className="font-heading text-base font-semibold text-amber-950 dark:text-amber-100">
                  Depurar historial
                </h2>
                <p className="mt-1 text-xs text-amber-900/80 dark:text-amber-100/80">
                  Borra los registros hasta el final del día elegido (Ciudad de México). La
                  operación es irreversible y deja un registro testigo en la bitácora.
                </p>
              </div>
            </div>

            <label className="block max-w-xs text-sm font-medium text-amber-950 dark:text-amber-50" htmlFor="purge-until">
              Borrar historial hasta la fecha
              <AppInput
                id="purge-until"
                type="date"
                withLabelSpacing
                value={purgeDate}
                disabled={!canPurge || purgeMutation.isPending}
                onChange={(event) => setPurgeDate(event.target.value)}
              />
            </label>

            {canPurge ? (
              <AppButton
                type="button"
                variant="danger"
                disabled={!/^\d{4}-\d{2}-\d{2}$/.test(purgeDate)}
                isLoading={purgeMutation.isPending}
                onClick={openPurgeConfirm}
              >
                Borrar historial
              </AppButton>
            ) : (
              <p className="text-xs text-amber-900/80 dark:text-amber-100/80">
                Tu rol no tiene permiso para depurar la bitácora.
              </p>
            )}
          </AppCard>
        </>
      )}

      <AppModal open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AppModalContent>
          <AppModalHeader>
            <AppModalTitle>Confirmar depuración irreversible</AppModalTitle>
            <AppModalDescription>
              Se eliminarán los registros creados hasta el {purgeLabel}, fin del día en Ciudad
              de México. No se pueden recuperar. La bitácora conservará un registro testigo de
              esta depuración.
            </AppModalDescription>
          </AppModalHeader>
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AppButton
              type="button"
              variant="outline"
              disabled={purgeMutation.isPending}
              onClick={() => setConfirmOpen(false)}
            >
              Cancelar
            </AppButton>
            <AppButton
              type="button"
              variant="danger"
              isLoading={purgeMutation.isPending}
              onClick={() => {
                void confirmPurge()
              }}
            >
              Borrar historial
            </AppButton>
          </div>
        </AppModalContent>
      </AppModal>
    </div>
  )
}
