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
 * CRUD de roles + matriz viva `role_permissions`.
 * Claves de módulo en inglés (contracts, clients, config_catalog, config_issuers…).
 * Acciones de permiso: view, create, edit, delete.
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
            ->setField('code', DataTypes::String, true)
            ->setField('name', DataTypes::String, true)
            ->setField('description', DataTypes::String, false)
            ->setField('is_system', DataTypes::Boolean, false, false)
            ->setField('is_active', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $auth = $context->getData('auth', []);
        if (!RbacAuthHelper::can($auth, 'config_roles', 'view')) {
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
            if (!RbacAuthHelper::can($auth, 'config_roles', 'edit')) {
                return Result::fail('Sin permiso para editar permisos.', 403);
            }

            return $this->savePermissions((int) $id, $context->getRequest());
        }

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listRolesWithPermissions(), 'Roles');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($auth, 'config_roles', 'create')) {
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
            if (!RbacAuthHelper::can($auth, 'config_roles', 'edit')) {
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
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (SystemModulesModel $module): array => [
                'id' => (int) $module->id,
                'module_key' => (string) $module->module_key,
                'name' => (string) $module->name,
                'group_key' => (string) $module->group_key,
                'sort_order' => (int) $module->sort_order,
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
            ->where('role_id', $roleId)
            ->get()
            ->keyBy('module_key');

        $modules = SystemModulesModel::query()->orderBy('sort_order')->get();
        $permissions = [];
        foreach ($modules as $module) {
            $moduleKey = (string) $module->module_key;
            /** @var RolePermissionsModel|null $row */
            $row = $perms->get($moduleKey);
            $permissions[$moduleKey] = [
                'view' => $row ? (bool) $row->can_view : false,
                'create' => $row ? (bool) $row->can_create : false,
                'edit' => $row ? (bool) $row->can_edit : false,
                'delete' => $row ? (bool) $row->can_delete : false,
            ];
        }

        return [
            'id' => (int) $role->id,
            'code' => (string) $role->code,
            'name' => (string) $role->name,
            'description' => $role->description !== null ? (string) $role->description : null,
            'is_system' => (bool) $role->is_system,
            'is_active' => (bool) $role->is_active,
            'permissions' => $permissions,
        ];
    }

    private function createRole(Context $context): Result
    {
        $request = $context->getRequest();
        $code = strtolower(trim((string) ($request['code'] ?? '')));
        $name = trim((string) ($request['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('code y name son obligatorios.', 422);
        }

        if (!preg_match('/^[a-z0-9_]{3,40}$/', $code)) {
            return Result::fail('code inválido (solo a-z, 0-9 y guion bajo).', 422);
        }

        if (RolesModel::query()->where('code', $code)->exists()) {
            return Result::fail('Ya existe un rol con ese código.', 422);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $roleId = (int) Capsule::table('roles')->insertGetId([
            'code' => $code,
            'name' => $name,
            'description' => isset($request['description'])
                ? (string) $request['description']
                : null,
            'is_system' => false,
            'is_active' => true,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $modules = SystemModulesModel::query()->pluck('module_key');
        foreach ($modules as $moduleKey) {
            Capsule::table('role_permissions')->insert([
                'role_id' => $roleId,
                'module_key' => (string) $moduleKey,
                'can_view' => false,
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);
        }

        /** @var array<string, mixed>|null $permissionsInput */
        $permissionsInput = $request['permissions'] ?? null;
        if (is_array($permissionsInput)) {
            $this->upsertPermissions($roleId, $permissionsInput, $nowUtc);
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

        /** @var array<string, mixed>|null $permissions */
        $permissions = $request['permissions'] ?? null;
        if (!is_array($permissions) || $permissions === []) {
            return Result::fail('Se requiere el mapa de permisos.', 422);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $this->upsertPermissions($roleId, $permissions, $nowUtc);

        Capsule::table('roles')->where('id', $roleId)->update(['updated_at' => $nowUtc]);

        // Cambio crítico: invalida JWT de todos los usuarios con este rol.
        $revoked = RbacAuthHelper::revokeSessionsForRole($roleId);

        $payload = $this->findRolePayload($roleId) ?? [];
        $payload['sessions_revoked'] = $revoked;
        $payload['requires_reauth'] = $revoked > 0;

        return Result::ok($payload, 'Permisos actualizados');
    }

    /**
     * @param array<string, mixed> $permissions
     */
    private function upsertPermissions(int $roleId, array $permissions, string $nowUtc): void
    {
        $validKeys = SystemModulesModel::query()->pluck('module_key')->all();
        $validSet = array_fill_keys(array_map('strval', $validKeys), true);

        foreach ($permissions as $moduleKey => $flags) {
            $moduleKey = (string) $moduleKey;
            if (!isset($validSet[$moduleKey]) || !is_array($flags)) {
                continue;
            }
            $payload = [
                'can_view' => (bool) ($flags['view'] ?? $flags['can_view'] ?? false),
                'can_create' => (bool) ($flags['create'] ?? $flags['can_create'] ?? false),
                'can_edit' => (bool) ($flags['edit'] ?? $flags['can_edit'] ?? false),
                'can_delete' => (bool) ($flags['delete'] ?? $flags['can_delete'] ?? false),
                'updated_at' => $nowUtc,
            ];

            $existing = Capsule::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('module_key', $moduleKey)
                ->first();

            if ($existing) {
                Capsule::table('role_permissions')->where('id', $existing->id)->update($payload);
            } else {
                Capsule::table('role_permissions')->insert(array_merge($payload, [
                    'role_id' => $roleId,
                    'module_key' => $moduleKey,
                    'created_at' => $nowUtc,
                ]));
            }
        }
    }
}
