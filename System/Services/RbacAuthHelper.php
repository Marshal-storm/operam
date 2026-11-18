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
     *   rol_id: int|null,
     *   rol_nombre: string|null,
     *   rol_codigo: string|null,
     *   segmento_id: int|null,
     *   segmento_nombre: string,
     *   permisos: array<string, array{ver: bool, crear: bool, editar: bool, eliminar: bool}>
     * }
     */
    public static function buildProfileForUser(UsersModel $user): array
    {
        $rolId = $user->rol_id !== null ? (int) $user->rol_id : null;
        $rolNombre = null;
        $rolCodigo = null;
        $permisos = [];

        if ($rolId !== null) {
            $rol = Capsule::table('rol')->where('id', $rolId)->first();
            if ($rol) {
                $rolNombre = (string) $rol->nombre;
                $rolCodigo = (string) $rol->codigo;
            }

            $rows = Capsule::table('rol_permiso')
                ->where('rol_id', $rolId)
                ->get(['modulo_clave', 'puede_ver', 'puede_crear', 'puede_editar', 'puede_eliminar']);

            foreach ($rows as $row) {
                $permisos[(string) $row->modulo_clave] = [
                    'ver' => (bool) $row->puede_ver,
                    'crear' => (bool) $row->puede_crear,
                    'editar' => (bool) $row->puede_editar,
                    'eliminar' => (bool) $row->puede_eliminar,
                ];
            }
        }

        $segmentoId = $user->segmento_id !== null ? (int) $user->segmento_id : null;
        $segmentoNombre = 'Todos';
        if ($segmentoId !== null) {
            $seg = Capsule::table('segmento')->where('id', $segmentoId)->value('nombre');
            $segmentoNombre = $seg ? (string) $seg : 'Todos';
        }

        return [
            'rol_id' => $rolId,
            'rol_nombre' => $rolNombre,
            'rol_codigo' => $rolCodigo,
            'segmento_id' => $segmentoId,
            'segmento_nombre' => $segmentoNombre,
            'permisos' => $permisos,
        ];
    }

    /**
     * Fuerza el filtro de segmento cuando el JWT tiene segmento_id fijo.
     *
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function applySegmentFilterToQuery(array $auth, array $query): array
    {
        $segmentoId = $auth['segmento_id'] ?? null;
        $segmentoNombre = (string) ($auth['segmento_nombre'] ?? 'Todos');
        if ($segmentoId === null || $segmentoNombre === '' || $segmentoNombre === 'Todos') {
            return $query;
        }

        $query['segmento'] = $segmentoNombre;
        $query['segmento_id'] = (int) $segmentoId;

        return $query;
    }

    /**
     * Valida que un payload con segmento_id no escape el alcance del usuario.
     *
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $payload
     */
    public static function assertSegmentWriteAllowed(array $auth, array $payload): ?string
    {
        $lockedId = $auth['segmento_id'] ?? null;
        if ($lockedId === null) {
            return null;
        }

        if (!array_key_exists('segmento_id', $payload)) {
            return null;
        }

        $incoming = $payload['segmento_id'];
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
        /** @var array<string, array{ver?: bool, crear?: bool, editar?: bool, eliminar?: bool}> $permisos */
        $permisos = $auth['permisos'] ?? [];
        $module = $permisos[$moduleKey] ?? null;
        if ($module === null) {
            return false;
        }

        return (bool) ($module[$action] ?? false);
    }

    /**
     * Revoca todas las sesiones activas de un usuario (cambio de rol/segmento/activo).
     */
    public static function revokeSessionsForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int) Capsule::table('sesion_activa')
            ->where('usuario_id', $userId)
            ->where('revocada', false)
            ->update(['revocada' => true]);
    }

    /**
     * Revoca sesiones de todos los usuarios con un rol (cambio de matriz permisos).
     */
    public static function revokeSessionsForRole(int $rolId): int
    {
        if ($rolId <= 0) {
            return 0;
        }

        $userIds = Capsule::table('usuario')
            ->where('rol_id', $rolId)
            ->pluck('id')
            ->all();

        if ($userIds === []) {
            return 0;
        }

        return (int) Capsule::table('sesion_activa')
            ->whereIn('usuario_id', $userIds)
            ->where('revocada', false)
            ->update(['revocada' => true]);
    }
}
