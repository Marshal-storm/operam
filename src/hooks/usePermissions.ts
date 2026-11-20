import { useMemo } from 'react'
import { useAuth } from '@/context/AuthContext'
import {
  CONFIG_MODULE_KEYS,
  type AllowedSegment,
  type ModuleKey,
  type ModulePermission,
} from '@/types/auth'

const EMPTY_PERM: ModulePermission = {
  view: false,
  create: false,
  edit: false,
  delete: false,
}

/**
 * Helpers RBAC multinivel + alcance de segmento forzado desde la sesión JWT.
 * `canView` lee `permissions[key].view`.
 * El alcance se basa en `segment_id` (NULL = Todos), no en nombres quemados.
 */
export function usePermissions() {
  const { user } = useAuth()

  return useMemo(() => {
    const permissions = user?.permissions ?? {}

    const getPerm = (moduleKey: ModuleKey): ModulePermission =>
      permissions[moduleKey] ?? EMPTY_PERM

    const canView = (moduleKey: ModuleKey): boolean =>
      Boolean(permissions[moduleKey]?.view)
    const canCreate = (moduleKey: ModuleKey): boolean => getPerm(moduleKey).create
    const canEdit = (moduleKey: ModuleKey): boolean => getPerm(moduleKey).edit
    const canDelete = (moduleKey: ModuleKey): boolean => getPerm(moduleKey).delete

    /** True si puede ver al menos un submódulo config_*. */
    const canViewAnyConfig = CONFIG_MODULE_KEYS.some((key) => canView(key))

    const visibleConfigModules = CONFIG_MODULE_KEYS.filter((key) => canView(key))

    const lockedSegmentId = user?.segment_id ?? null
    const isSegmentLocked = lockedSegmentId !== null
    const allowedSegment: AllowedSegment = isSegmentLocked
      ? String(user?.segment_name || '—')
      : 'Todos'

    const segmentBadgeLabel =
      !isSegmentLocked
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
      roleName: user?.role_name ?? user?.role_code ?? null,
      roleId: user?.role_id ?? null,
      userId: user?.id ?? null,
    }
  }, [user])
}
