import { useMemo } from 'react'
import { useAuth } from '@/context/AuthContext'
import {
  CONFIG_MODULE_KEYS,
  type AllowedSegment,
  type ModuleKey,
  type ModulePermission,
} from '@/types/auth'

const EMPTY_PERM: ModulePermission = {
  ver: false,
  crear: false,
  editar: false,
  eliminar: false,
}

function normalizeSegment(nombre: string | null | undefined): AllowedSegment {
  if (nombre === 'Operam' || nombre === 'Construsync') return nombre
  return 'Todos'
}

/**
 * Helpers RBAC multinivel + alcance de segmento forzado desde la sesión JWT.
 */
export function usePermissions() {
  const { user } = useAuth()

  return useMemo(() => {
    const permisos = user?.permisos ?? {}

    const getPerm = (moduleKey: ModuleKey): ModulePermission =>
      permisos[moduleKey] ?? EMPTY_PERM

    const canView = (moduleKey: ModuleKey): boolean => getPerm(moduleKey).ver
    const canCreate = (moduleKey: ModuleKey): boolean => getPerm(moduleKey).crear
    const canEdit = (moduleKey: ModuleKey): boolean => getPerm(moduleKey).editar
    const canDelete = (moduleKey: ModuleKey): boolean => getPerm(moduleKey).eliminar

    /** True si puede ver al menos un submódulo config_*. */
    const canViewAnyConfig = CONFIG_MODULE_KEYS.some((key) => canView(key))

    const visibleConfigModules = CONFIG_MODULE_KEYS.filter((key) => canView(key))

    const allowedSegment = normalizeSegment(user?.segmento_nombre)
    const isSegmentLocked = allowedSegment === 'Operam' || allowedSegment === 'Construsync'
    const lockedSegmentId = user?.segmento_id ?? null

    const segmentBadgeLabel =
      allowedSegment === 'Todos'
        ? 'Alcance: Todos los segmentos'
        : `Alcance exclusivo: ${allowedSegment}`

    return {
      canView,
      canCreate,
      canEdit,
      canDelete,
      canViewAnyConfig,
      visibleConfigModules,
      getPerm,
      allowedSegment,
      isSegmentLocked,
      lockedSegmentId,
      segmentBadgeLabel,
      rolNombre: user?.rol_nombre ?? user?.rol ?? null,
      rolId: user?.rol_id ?? null,
      userId: user?.id ?? null,
    }
  }, [user])
}
