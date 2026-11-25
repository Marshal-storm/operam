<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\UsersModel;
use System\Support\AuditLogger;

/**
 * Alta/edición de usuarios internos con rol y segmento (Todos, Operam o Construsync).
 * La columna legacy `rol` no existe: el vínculo es solo `role_id`.
 */
final class UsersManagementService extends ModelService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    protected function modelClass(): string
    {
        return UsersModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('email', DataTypes::Email, true)
            ->setField('name', DataTypes::String, true)
            ->setField('password_hash', DataTypes::String, false, null, null, true)
            ->setField('role_id', DataTypes::Integer, false)
            ->setField('segment_id', DataTypes::Integer, false)
            ->setField('is_active', DataTypes::Boolean, false, true)
            ->setField('can_authorize_exceptions', DataTypes::Boolean, false, false)
            ->setField('last_login_at', DataTypes::DateTime, false);
    }

    public function handle(Context $context): Result
    {
        $auth = $context->getData('auth', []);
        if (!RbacAuthHelper::can($auth, 'config_users', 'view')) {
            return Result::fail('Sin permiso para administrar usuarios.', 403);
        }

        $method = $context->getMethod();
        $id = $context->getData('_resource_id');

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listPaginated($context->getRequest()), 'Usuarios');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($auth, 'config_users', 'create')) {
                return Result::fail('Sin permiso para crear usuarios.', 403);
            }

            return $this->createUser($context->getRequest(), $auth);
        }

        if ($id === null) {
            return Result::fail('Se requiere id de usuario.', 400);
        }

        $userId = (int) $id;
        if ($method === 'GET') {
            $row = $this->findUserPayload($userId);
            if ($row === null) {
                return Result::fail('Usuario no encontrado.', 404);
            }

            return Result::ok($row, 'Usuario');
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            if (!RbacAuthHelper::can($auth, 'config_users', 'edit')) {
                return Result::fail('Sin permiso para editar usuarios.', 403);
            }

            return $this->updateUser($userId, $context->getRequest(), $auth);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
     */
    private function listPaginated(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $builder = Capsule::table('users as u')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->leftJoin('segments as s', 's.id', '=', 'u.segment_id');

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('u.email', 'like', $like)
                    ->orWhere('u.name', 'like', $like)
                    ->orWhere('r.name', 'like', $like);
            });
        }

        if (isset($query['role_id']) && $query['role_id'] !== '' && $query['role_id'] !== null) {
            $builder->where('u.role_id', (int) $query['role_id']);
        }

        $segment = trim((string) ($query['segment'] ?? ''));
        $segmentKey = strtolower($segment);
        if ($segment !== '' && $segmentKey !== 'all' && $segmentKey !== 'todos') {
            if ($segmentKey === 'unassigned' || $segmentKey === 'none') {
                $builder->whereNull('u.segment_id');
            } else {
                $builder->where('s.name', $segment);
            }
        }

        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if ($status === 'active') {
            $builder->where('u.is_active', true);
        } elseif ($status === 'inactive') {
            $builder->where('u.is_active', false);
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderByRaw('COALESCE(u.updated_at, u.created_at) DESC')
            ->orderByDesc('u.id')
            ->forPage($page, $perPage)
            ->get([
                'u.id',
                'u.email',
                'u.name',
                'u.role_id',
                'r.name as role_name',
                'r.code as role_code',
                'u.segment_id',
                's.name as segment_name',
                'u.is_active',
                'u.can_authorize_exceptions',
                'u.last_login_at',
                'u.created_at',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapUserRow($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $auth
     */
    private function createUser(array $request, array $auth): Result
    {
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $name = trim((string) ($request['name'] ?? ''));
        $password = (string) ($request['password'] ?? '');
        $roleId = isset($request['role_id']) ? (int) $request['role_id'] : null;
        $segmentId = array_key_exists('segment_id', $request) && $request['segment_id'] !== null && $request['segment_id'] !== ''
            ? (int) $request['segment_id']
            : null;
        $isActive = array_key_exists('is_active', $request) ? (bool) $request['is_active'] : true;
        $canAuthorizeExceptions = array_key_exists('can_authorize_exceptions', $request)
            ? (bool) $request['can_authorize_exceptions']
            : false;

        if ($email === '' || $name === '' || $password === '' || $roleId === null) {
            return Result::fail('email, name, password y role_id son obligatorios.', 422);
        }

        if (UsersModel::query()->where('email', $email)->exists()) {
            return Result::fail('Ya existe un usuario con ese correo.', 422);
        }

        if (!Capsule::table('roles')->where('id', $roleId)->where('is_active', true)->exists()) {
            return Result::fail('Rol inválido.', 422);
        }

        if ($segmentId !== null && !Capsule::table('segments')->where('id', $segmentId)->exists()) {
            return Result::fail('Segmento inválido.', 422);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = (int) Capsule::table('users')->insertGetId([
            'email' => $email,
            'name' => $name,
            'password_hash' => $this->hashPassword($password),
            'role_id' => $roleId,
            'segment_id' => $segmentId,
            'is_active' => $isActive,
            'can_authorize_exceptions' => $canAuthorizeExceptions,
            'last_login_at' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $created = $this->findUserPayload($userId);
        AuditLogger::log(
            $auth,
            'config_users',
            'user',
            (string) $userId,
            'create',
            sprintf('Se creó el usuario %s', (string) ($created['name'] ?? $name)),
            null,
            $created,
        );

        return Result::ok($created, 'Usuario creado', 201);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $auth
     */
    private function updateUser(int $userId, array $request, array $auth): Result
    {
        /** @var UsersModel|null $user */
        $user = UsersModel::query()->find($userId);
        if (!$user) {
            return Result::fail('Usuario no encontrado.', 404);
        }

        $prevRoleId = $user->role_id !== null ? (int) $user->role_id : null;
        $prevSegmentId = $user->segment_id !== null ? (int) $user->segment_id : null;
        $prevActive = (bool) $user->is_active;

        $updates = [];
        if (isset($request['name'])) {
            $updates['name'] = trim((string) $request['name']);
        }
        if (isset($request['email'])) {
            $email = strtolower(trim((string) $request['email']));
            $exists = UsersModel::query()
                ->where('email', $email)
                ->where('id', '!=', $userId)
                ->exists();
            if ($exists) {
                return Result::fail('Ya existe un usuario con ese correo.', 422);
            }
            $updates['email'] = $email;
        }
        if (array_key_exists('role_id', $request) && $request['role_id'] !== null && $request['role_id'] !== '') {
            $roleId = (int) $request['role_id'];
            if (!Capsule::table('roles')->where('id', $roleId)->exists()) {
                return Result::fail('Rol inválido.', 422);
            }
            $updates['role_id'] = $roleId;
        }
        if (array_key_exists('segment_id', $request)) {
            if ($request['segment_id'] === null || $request['segment_id'] === '') {
                $updates['segment_id'] = null;
            } else {
                $segmentId = (int) $request['segment_id'];
                if (!Capsule::table('segments')->where('id', $segmentId)->exists()) {
                    return Result::fail('Segmento inválido.', 422);
                }
                $updates['segment_id'] = $segmentId;
            }
        }
        if (array_key_exists('is_active', $request)) {
            $updates['is_active'] = (bool) $request['is_active'];
        }
        if (array_key_exists('can_authorize_exceptions', $request)) {
            $updates['can_authorize_exceptions'] = (bool) $request['can_authorize_exceptions'];
        }
        if (!empty($request['password'])) {
            $updates['password_hash'] = $this->hashPassword((string) $request['password']);
        }

        if ($updates === []) {
            return Result::fail('No hay campos para actualizar.', 422);
        }

        $before = $this->findUserPayload($userId);
        $updates['updated_at'] = gmdate('Y-m-d H:i:s');
        Capsule::table('users')->where('id', $userId)->update($updates);

        $newRoleId = array_key_exists('role_id', $updates)
            ? (int) $updates['role_id']
            : $prevRoleId;
        $newSegmentId = array_key_exists('segment_id', $updates)
            ? ($updates['segment_id'] !== null ? (int) $updates['segment_id'] : null)
            : $prevSegmentId;
        $newActive = array_key_exists('is_active', $updates)
            ? (bool) $updates['is_active']
            : $prevActive;

        $critical =
            $newRoleId !== $prevRoleId
            || $newSegmentId !== $prevSegmentId
            || ($prevActive && !$newActive);

        $revoked = 0;
        if ($critical) {
            $revoked = RbacAuthHelper::revokeSessionsForUser($userId);
        }

        $after = $this->findUserPayload($userId) ?? [];
        $auditNew = $after;
        // La contraseña no se registra; solo una bandera sin la palabra que AuditLogger redacta.
        if (!empty($request['password'])) {
            $auditNew['credentials_rotated'] = true;
        }
        $statusOnly = array_key_exists('is_active', $updates)
            && array_diff(array_keys($updates), ['updated_at', 'is_active']) === [];
        $displayName = (string) ($after['name'] ?? (is_array($before) ? ($before['name'] ?? null) : null) ?? 'usuario');
        if ($statusOnly) {
            $action = 'status_change';
            $description = ((bool) ($after['is_active'] ?? false))
                ? "Se activó el usuario {$displayName}"
                : "Se desactivó el usuario {$displayName}";
        } else {
            $action = 'update';
            $description = "Se actualizó el usuario {$displayName}";
            if (!empty($request['password'])) {
                $description .= ' (contraseña restablecida)';
            }
        }
        AuditLogger::log(
            $auth,
            'config_users',
            'user',
            (string) $userId,
            $action,
            $description,
            $before,
            $auditNew,
        );
        if ($revoked > 0) {
            AuditLogger::log(
                $auth,
                'config_users',
                'session',
                (string) $userId,
                'revoke_session',
                sprintf('Se revocaron %d sesión(es) del usuario %s', $revoked, $displayName),
                null,
                [
                    'user_id' => $userId,
                    'sessions_revoked' => $revoked,
                ],
            );
        }

        $after['sessions_revoked'] = $revoked;
        $after['requires_reauth'] = $critical;

        return Result::ok($after, 'Usuario actualizado');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserPayload(int $userId): ?array
    {
        $row = Capsule::table('users as u')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->leftJoin('segments as s', 's.id', '=', 'u.segment_id')
            ->where('u.id', $userId)
            ->first([
                'u.id',
                'u.email',
                'u.name',
                'u.role_id',
                'r.name as role_name',
                'r.code as role_code',
                'u.segment_id',
                's.name as segment_name',
                'u.is_active',
                'u.can_authorize_exceptions',
                'u.last_login_at',
                'u.created_at',
            ]);

        return $row ? $this->mapUserRow($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapUserRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'email' => (string) $row->email,
            'name' => (string) $row->name,
            'role_id' => $row->role_id !== null ? (int) $row->role_id : null,
            'role_name' => $row->role_name !== null ? (string) $row->role_name : null,
            'role_code' => $row->role_code !== null ? (string) $row->role_code : null,
            'segment_id' => $row->segment_id !== null ? (int) $row->segment_id : null,
            'segment_name' => $row->segment_name !== null ? (string) $row->segment_name : 'Todos',
            'is_active' => (bool) $row->is_active,
            'can_authorize_exceptions' => (bool) ($row->can_authorize_exceptions ?? false),
            'last_login_at' => $row->last_login_at,
            'created_at' => $row->created_at,
        ];
    }

    private function hashPassword(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID);
        }

        return password_hash($plain, PASSWORD_BCRYPT);
    }
}
