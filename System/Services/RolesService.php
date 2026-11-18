<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\RolePermissionsModel;
use System\Models\RolesModel;
use System\Models\SystemModulesModel;

/**
 * CRUD de roles + matriz viva rol_permiso.
 */
final class RolesService extends ModelService
{
    protected function modelClass(): string
    {
        return RolesModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('codigo', DataTypes::String, true)
            ->setField('nombre', DataTypes::String, true)
            ->setField('descripcion', DataTypes::String, false)
            ->setField('es_sistema', DataTypes::Boolean, false, false)
            ->setField('activo', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $auth = $context->getData('auth', []);
        if (!RbacAuthHelper::can($auth, 'config_roles', 'ver')) {
            return Result::fail('Sin permiso para administrar roles.', 403);
        }

        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $uri = strtolower(trim($context->getUri(), '/'));
        $parts = $uri === '' ? [] : explode('/', $uri);

        // GET /roles/modules — catálogo de módulos
        if ($method === 'GET' && ($parts[1] ?? '') === 'modules') {
            return Result::ok($this->listModules(), 'Módulos del sistema');
        }

        // PUT/PATCH /roles/{id}/permissions — guarda matriz CRUD
        if (
            $id !== null
            && ($parts[2] ?? '') === 'permissions'
            && ($method === 'PUT' || $method === 'PATCH')
        ) {
            if (!RbacAuthHelper::can($auth, 'config_roles', 'editar')) {
                return Result::fail('Sin permiso para editar permisos.', 403);
            }

            return $this->savePermissions((int) $id, $context->getRequest());
        }

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listRolesWithPermissions(), 'Roles');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($auth, 'config_roles', 'crear')) {
                return Result::fail('Sin permiso para crear roles.', 403);
            }

            return $this->createRole($context);
        }

        if ($id === null) {
            return Result::fail('Se requiere id de rol.', 400);
        }

        $roleId = (int) $id;
        if ($method === 'GET') {
            $role = $this->findRolePayload($roleId);
            if ($role === null) {
                return Result::fail('Rol no encontrado.', 404);
            }

            return Result::ok($role, 'Rol');
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            if (!RbacAuthHelper::can($auth, 'config_roles', 'editar')) {
                return Result::fail('Sin permiso para editar roles.', 403);
            }

            return $method === 'PUT'
                ? $this->update($roleId, $context->getRequest(), $context)
                : $this->patch($roleId, $context->getRequest(), $context);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listModules(): array
    {
        return SystemModulesModel::query()
            ->orderBy('orden')
            ->get()
            ->map(static fn (SystemModulesModel $m): array => [
                'id' => (int) $m->id,
                'clave' => (string) $m->clave,
                'nombre' => (string) $m->nombre,
                'grupo' => (string) $m->grupo,
                'orden' => (int) $m->orden,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listRolesWithPermissions(): array
    {
        $roles = RolesModel::query()->orderBy('id')->get();
        $out = [];
        foreach ($roles as $role) {
            $payload = $this->findRolePayload((int) $role->id);
            if ($payload !== null) {
                $out[] = $payload;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRolePayload(int $roleId): ?array
    {
        /** @var RolesModel|null $role */
        $role = RolesModel::query()->find($roleId);
        if (!$role) {
            return null;
        }

        $perms = RolePermissionsModel::query()
            ->where('rol_id', $roleId)
            ->get()
            ->keyBy('modulo_clave');

        $modules = SystemModulesModel::query()->orderBy('orden')->get();
        $permisos = [];
        foreach ($modules as $module) {
            $clave = (string) $module->clave;
            /** @var RolePermissionsModel|null $row */
            $row = $perms->get($clave);
            $permisos[$clave] = [
                'ver' => $row ? (bool) $row->puede_ver : false,
                'crear' => $row ? (bool) $row->puede_crear : false,
                'editar' => $row ? (bool) $row->puede_editar : false,
                'eliminar' => $row ? (bool) $row->puede_eliminar : false,
            ];
        }

        return [
            'id' => (int) $role->id,
            'codigo' => (string) $role->codigo,
            'nombre' => (string) $role->nombre,
            'descripcion' => $role->descripcion !== null ? (string) $role->descripcion : null,
            'es_sistema' => (bool) $role->es_sistema,
            'activo' => (bool) $role->activo,
            'permisos' => $permisos,
        ];
    }

    private function createRole(Context $context): Result
    {
        $request = $context->getRequest();
        $codigo = strtolower(trim((string) ($request['codigo'] ?? '')));
        $nombre = trim((string) ($request['nombre'] ?? ''));
        if ($codigo === '' || $nombre === '') {
            return Result::fail('codigo y nombre son obligatorios.', 422);
        }

        if (!preg_match('/^[a-z0-9_]{3,40}$/', $codigo)) {
            return Result::fail('codigo inválido (solo a-z, 0-9 y guion bajo).', 422);
        }

        if (RolesModel::query()->where('codigo', $codigo)->exists()) {
            return Result::fail('Ya existe un rol con ese código.', 422);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $roleId = (int) Capsule::table('rol')->insertGetId([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'descripcion' => isset($request['descripcion'])
                ? (string) $request['descripcion']
                : null,
            'es_sistema' => false,
            'activo' => true,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $modules = SystemModulesModel::query()->pluck('clave');
        foreach ($modules as $clave) {
            Capsule::table('rol_permiso')->insert([
                'rol_id' => $roleId,
                'modulo_clave' => (string) $clave,
                'puede_ver' => false,
                'puede_crear' => false,
                'puede_editar' => false,
                'puede_eliminar' => false,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);
        }

        /** @var array<string, mixed>|null $permisosInput */
        $permisosInput = $request['permisos'] ?? null;
        if (is_array($permisosInput)) {
            $this->upsertPermissions($roleId, $permisosInput, $nowUtc);
        }

        $payload = $this->findRolePayload($roleId);

        return Result::ok($payload, 'Rol creado', 201);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function savePermissions(int $roleId, array $request): Result
    {
        if (!RolesModel::query()->where('id', $roleId)->exists()) {
            return Result::fail('Rol no encontrado.', 404);
        }

        /** @var array<string, mixed>|null $permisos */
        $permisos = $request['permisos'] ?? null;
        if (!is_array($permisos) || $permisos === []) {
            return Result::fail('Se requiere el mapa permisos.', 422);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $this->upsertPermissions($roleId, $permisos, $nowUtc);

        Capsule::table('rol')->where('id', $roleId)->update(['updated_at' => $nowUtc]);

        // Cambio crítico: invalida JWT de todos los usuarios con este rol.
        $revoked = RbacAuthHelper::revokeSessionsForRole($roleId);

        $payload = $this->findRolePayload($roleId) ?? [];
        $payload['sessions_revoked'] = $revoked;
        $payload['requires_reauth'] = $revoked > 0;

        return Result::ok($payload, 'Permisos actualizados');
    }

    /**
     * @param array<string, mixed> $permisos
     */
    private function upsertPermissions(int $roleId, array $permisos, string $nowUtc): void
    {
        $validKeys = SystemModulesModel::query()->pluck('clave')->all();
        $validSet = array_fill_keys(array_map('strval', $validKeys), true);

        foreach ($permisos as $clave => $flags) {
            $clave = (string) $clave;
            if (!isset($validSet[$clave]) || !is_array($flags)) {
                continue;
            }
            $payload = [
                'puede_ver' => (bool) ($flags['ver'] ?? $flags['puede_ver'] ?? false),
                'puede_crear' => (bool) ($flags['crear'] ?? $flags['puede_crear'] ?? false),
                'puede_editar' => (bool) ($flags['editar'] ?? $flags['puede_editar'] ?? false),
                'puede_eliminar' => (bool) ($flags['eliminar'] ?? $flags['puede_eliminar'] ?? false),
                'updated_at' => $nowUtc,
            ];

            $existing = Capsule::table('rol_permiso')
                ->where('rol_id', $roleId)
                ->where('modulo_clave', $clave)
                ->first();

            if ($existing) {
                Capsule::table('rol_permiso')->where('id', $existing->id)->update($payload);
            } else {
                Capsule::table('rol_permiso')->insert(array_merge($payload, [
                    'rol_id' => $roleId,
                    'modulo_clave' => $clave,
                    'created_at' => $nowUtc,
                ]));
            }
        }
    }
}
