<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Models\UsersModel;

/**
 * Construye el perfil RBAC (rol, segmento, mapa de permisos) para JWT y sesión.
 */
final class RbacAuthHelper
{
    /**
     * @return array{
     *   role_id: int|null,
     *   role_name: string|null,
     *   role_code: string|null,
     *   segment_id: int|null,
     *   segment_name: string,
     *   permissions: array<string, array{view: bool, create: bool, edit: bool, delete: bool}>
     * }
     */
    public static function buildProfileForUser(UsersModel $user): array
    {
        $roleId = $user->role_id !== null ? (int) $user->role_id : null;
        $roleName = null;
        $roleCode = null;
        $permissions = [];

        if ($roleId !== null) {
            $role = Capsule::table('roles')->where('id', $roleId)->first();
            if ($role) {
                $roleName = (string) $role->name;
                $roleCode = (string) $role->code;
            }

            $rows = Capsule::table('role_permissions')
                ->where('role_id', $roleId)
                ->get(['module_key', 'can_view', 'can_create', 'can_edit', 'can_delete']);

            foreach ($rows as $row) {
                $permissions[(string) $row->module_key] = [
                    'view' => (bool) $row->can_view,
                    'create' => (bool) $row->can_create,
                    'edit' => (bool) $row->can_edit,
                    'delete' => (bool) $row->can_delete,
                ];
            }
        }

        $segmentId = $user->segment_id !== null ? (int) $user->segment_id : null;
        $segmentName = 'Todos';
        if ($segmentId !== null) {
            $segment = Capsule::table('segments')->where('id', $segmentId)->value('name');
            $segmentName = $segment ? (string) $segment : 'Todos';
        }

        return [
            'role_id' => $roleId,
            'role_name' => $roleName,
            'role_code' => $roleCode,
            'segment_id' => $segmentId,
            'segment_name' => $segmentName,
            'permissions' => $permissions,
        ];
    }

    /**
     * Fuerza el filtro de segmento cuando el JWT tiene segment_id fijo.
     *
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function applySegmentFilterToQuery(array $auth, array $query): array
    {
        $segmentId = $auth['segment_id'] ?? null;
        $segmentName = (string) ($auth['segment_name'] ?? 'Todos');
        if ($segmentId === null || $segmentName === '' || $segmentName === 'Todos') {
            return $query;
        }

        $query['segment'] = $segmentName;
        $query['segment_id'] = (int) $segmentId;

        return $query;
    }

    /**
     * Valida que un payload con segment_id no escape el alcance del usuario.
     *
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $payload
     */
    public static function assertSegmentWriteAllowed(array $auth, array $payload): ?string
    {
        $lockedId = $auth['segment_id'] ?? null;
        if ($lockedId === null) {
            return null;
        }

        if (!array_key_exists('segment_id', $payload)) {
            return null;
        }

        $incoming = $payload['segment_id'];
        if ($incoming === null || $incoming === '' || (int) $incoming !== (int) $lockedId) {
            return 'No puedes asignar o consultar un segmento fuera de tu alcance.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $auth
     */
    public static function can(array $auth, string $moduleKey, string $action): bool
    {
        /** @var array<string, array{view?: bool, create?: bool, edit?: bool, delete?: bool}> $permissions */
        $permissions = $auth['permissions'] ?? [];
        $module = $permissions[$moduleKey] ?? null;
        if ($module === null) {
            return false;
        }

        return (bool) ($module[$action] ?? false);
    }

    /**
     * Revoca todas las sesiones activas de un usuario (cambio de rol, segmento o estado).
     */
    public static function revokeSessionsForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int) Capsule::table('active_sessions')
            ->where('user_id', $userId)
            ->where('is_revoked', false)
            ->update(['is_revoked' => true]);
    }

    /**
     * Revoca sesiones de todos los usuarios con un rol (cambio de matriz de permisos).
     */
    public static function revokeSessionsForRole(int $roleId): int
    {
        if ($roleId <= 0) {
            return 0;
        }

        $userIds = Capsule::table('users')
            ->where('role_id', $roleId)
            ->pluck('id')
            ->all();

        if ($userIds === []) {
            return 0;
        }

        return (int) Capsule::table('active_sessions')
            ->whereIn('user_id', $userIds)
            ->where('is_revoked', false)
            ->update(['is_revoked' => true]);
    }
}
