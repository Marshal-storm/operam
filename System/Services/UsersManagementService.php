<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\UsersModel;

/**
 * Alta/edición de usuarios internos con Rol + Segmento (Todos|Operam|Construsync).
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
            ->setField('nombre', DataTypes::String, true)
            ->setField('password_hash', DataTypes::String, false, null, null, true)
            ->setField('rol', DataTypes::String, false, 'operador')
            ->setField('rol_id', DataTypes::Integer, false)
            ->setField('segmento_id', DataTypes::Integer, false)
            ->setField('activo', DataTypes::Boolean, false, true)
            ->setField('ultimo_acceso', DataTypes::DateTime, false);
    }

    public function handle(Context $context): Result
    {
        $auth = $context->getData('auth', []);
        if (!RbacAuthHelper::can($auth, 'config_usuarios', 'ver')) {
            return Result::fail('Sin permiso para administrar usuarios.', 403);
        }

        $method = $context->getMethod();
        $id = $context->getData('_resource_id');

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listPaginated($context->getRequest()), 'Usuarios');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($auth, 'config_usuarios', 'crear')) {
                return Result::fail('Sin permiso para crear usuarios.', 403);
            }

            return $this->createUser($context->getRequest());
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
            if (!RbacAuthHelper::can($auth, 'config_usuarios', 'editar')) {
                return Result::fail('Sin permiso para editar usuarios.', 403);
            }

            return $this->updateUser($userId, $context->getRequest());
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

        $builder = Capsule::table('usuario as u')
            ->leftJoin('rol as r', 'r.id', '=', 'u.rol_id')
            ->leftJoin('segmento as s', 's.id', '=', 'u.segmento_id');

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('u.email', 'like', $like)
                    ->orWhere('u.nombre', 'like', $like)
                    ->orWhere('r.nombre', 'like', $like);
            });
        }

        if (isset($query['rol_id']) && $query['rol_id'] !== '' && $query['rol_id'] !== null) {
            $builder->where('u.rol_id', (int) $query['rol_id']);
        }

        $segmento = trim((string) ($query['segmento'] ?? ''));
        if ($segmento !== '' && strtolower($segmento) !== 'todos') {
            if (strtolower($segmento) === 'sin_segmento' || strtolower($segmento) === 'all_null') {
                $builder->whereNull('u.segmento_id');
            } else {
                $builder->where('s.nombre', $segmento);
            }
        }

        $estado = strtolower(trim((string) ($query['estado'] ?? '')));
        if ($estado === 'activos' || $estado === 'activo') {
            $builder->where('u.activo', true);
        } elseif ($estado === 'inactivos' || $estado === 'inactivo') {
            $builder->where('u.activo', false);
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderBy('u.id')
            ->forPage($page, $perPage)
            ->get([
                'u.id',
                'u.email',
                'u.nombre',
                'u.rol',
                'u.rol_id',
                'r.nombre as rol_nombre',
                'r.codigo as rol_codigo',
                'u.segmento_id',
                's.nombre as segmento_nombre',
                'u.activo',
                'u.ultimo_acceso',
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
     */
    private function createUser(array $request): Result
    {
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $nombre = trim((string) ($request['nombre'] ?? ''));
        $password = (string) ($request['password'] ?? '');
        $rolId = isset($request['rol_id']) ? (int) $request['rol_id'] : null;
        $segmentoId = array_key_exists('segmento_id', $request) && $request['segmento_id'] !== null && $request['segmento_id'] !== ''
            ? (int) $request['segmento_id']
            : null;
        $activo = array_key_exists('activo', $request) ? (bool) $request['activo'] : true;

        if ($email === '' || $nombre === '' || $password === '' || $rolId === null) {
            return Result::fail('email, nombre, password y rol_id son obligatorios.', 422);
        }

        if (UsersModel::query()->where('email', $email)->exists()) {
            return Result::fail('Ya existe un usuario con ese correo.', 422);
        }

        if (!Capsule::table('rol')->where('id', $rolId)->where('activo', true)->exists()) {
            return Result::fail('Rol inválido.', 422);
        }

        if ($segmentoId !== null && !Capsule::table('segmento')->where('id', $segmentoId)->exists()) {
            return Result::fail('Segmento inválido.', 422);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = (int) Capsule::table('usuario')->insertGetId([
            'email' => $email,
            'nombre' => $nombre,
            'password_hash' => $this->hashPassword($password),
            'rol' => $this->legacyRoleFromRolId($rolId),
            'rol_id' => $rolId,
            'segmento_id' => $segmentoId,
            'activo' => $activo,
            'ultimo_acceso' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        return Result::ok($this->findUserPayload($userId), 'Usuario creado', 201);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function updateUser(int $userId, array $request): Result
    {
        /** @var UsersModel|null $user */
        $user = UsersModel::query()->find($userId);
        if (!$user) {
            return Result::fail('Usuario no encontrado.', 404);
        }

        $prevRolId = $user->rol_id !== null ? (int) $user->rol_id : null;
        $prevSegmentoId = $user->segmento_id !== null ? (int) $user->segmento_id : null;
        $prevActivo = (bool) $user->activo;

        $updates = [];
        if (isset($request['nombre'])) {
            $updates['nombre'] = trim((string) $request['nombre']);
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
        if (array_key_exists('rol_id', $request) && $request['rol_id'] !== null && $request['rol_id'] !== '') {
            $rolId = (int) $request['rol_id'];
            if (!Capsule::table('rol')->where('id', $rolId)->exists()) {
                return Result::fail('Rol inválido.', 422);
            }
            $updates['rol_id'] = $rolId;
            $updates['rol'] = $this->legacyRoleFromRolId($rolId);
        }
        if (array_key_exists('segmento_id', $request)) {
            if ($request['segmento_id'] === null || $request['segmento_id'] === '') {
                $updates['segmento_id'] = null;
            } else {
                $segmentoId = (int) $request['segmento_id'];
                if (!Capsule::table('segmento')->where('id', $segmentoId)->exists()) {
                    return Result::fail('Segmento inválido.', 422);
                }
                $updates['segmento_id'] = $segmentoId;
            }
        }
        if (array_key_exists('activo', $request)) {
            $updates['activo'] = (bool) $request['activo'];
        }
        if (!empty($request['password'])) {
            $updates['password_hash'] = $this->hashPassword((string) $request['password']);
        }

        if ($updates === []) {
            return Result::fail('No hay campos para actualizar.', 422);
        }

        $updates['updated_at'] = gmdate('Y-m-d H:i:s');
        Capsule::table('usuario')->where('id', $userId)->update($updates);

        $newRolId = array_key_exists('rol_id', $updates)
            ? (int) $updates['rol_id']
            : $prevRolId;
        $newSegmentoId = array_key_exists('segmento_id', $updates)
            ? ($updates['segmento_id'] !== null ? (int) $updates['segmento_id'] : null)
            : $prevSegmentoId;
        $newActivo = array_key_exists('activo', $updates)
            ? (bool) $updates['activo']
            : $prevActivo;

        $critical =
            $newRolId !== $prevRolId
            || $newSegmentoId !== $prevSegmentoId
            || ($prevActivo && !$newActivo);

        $revoked = 0;
        if ($critical) {
            $revoked = RbacAuthHelper::revokeSessionsForUser($userId);
        }

        $payload = $this->findUserPayload($userId) ?? [];
        $payload['sessions_revoked'] = $revoked;
        $payload['requires_reauth'] = $critical;

        return Result::ok($payload, 'Usuario actualizado');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserPayload(int $userId): ?array
    {
        $row = Capsule::table('usuario as u')
            ->leftJoin('rol as r', 'r.id', '=', 'u.rol_id')
            ->leftJoin('segmento as s', 's.id', '=', 'u.segmento_id')
            ->where('u.id', $userId)
            ->first([
                'u.id',
                'u.email',
                'u.nombre',
                'u.rol',
                'u.rol_id',
                'r.nombre as rol_nombre',
                'r.codigo as rol_codigo',
                'u.segmento_id',
                's.nombre as segmento_nombre',
                'u.activo',
                'u.ultimo_acceso',
                'u.created_at',
            ]);

        return $row ? $this->mapUserRow($row) : null;
    }

    /**
     * @param object $row
     * @return array<string, mixed>
     */
    private function mapUserRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'email' => (string) $row->email,
            'nombre' => (string) $row->nombre,
            'rol' => (string) $row->rol,
            'rol_id' => $row->rol_id !== null ? (int) $row->rol_id : null,
            'rol_nombre' => $row->rol_nombre !== null ? (string) $row->rol_nombre : null,
            'rol_codigo' => $row->rol_codigo !== null ? (string) $row->rol_codigo : null,
            'segmento_id' => $row->segmento_id !== null ? (int) $row->segmento_id : null,
            'segmento_nombre' => $row->segmento_nombre !== null ? (string) $row->segmento_nombre : 'Todos',
            'activo' => (bool) $row->activo,
            'ultimo_acceso' => $row->ultimo_acceso,
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

    private function legacyRoleFromRolId(int $rolId): string
    {
        $codigo = (string) (Capsule::table('rol')->where('id', $rolId)->value('codigo') ?? '');
        if ($codigo === 'admin_general') {
            return 'admin';
        }
        if ($codigo === 'ejecutivo_ventas') {
            return 'lectura';
        }

        return 'operador';
    }
}
