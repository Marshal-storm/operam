<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\IssuersModel;

/**
 * CRUD de emisores (RF-1) — endpoint `/api/issuers` (alias `/api/emisores`, `/api/emitters`).
 * Refs sensibles se enmascaran si el JWT no tiene `puede_editar` en `config_emisores`.
 */
final class IssuersService extends ModelService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    private const MASK = '••••••••';

    private const SENSITIVE_FIELDS = [
        'secret_ref',
        'integropay_merchant_token_ref',
        'integropay_device_token_ref',
    ];

    /** @var array<string, mixed> */
    private array $authContext = [];

    protected function modelClass(): string
    {
        return IssuersModel::class;
    }

    protected function configureFields(): void
    {
        // hide_output = true: no se exponen en formatOutput genérico del framework.
        $this->validator
            ->setField('nombre', DataTypes::String, true)
            ->setField('rfc', DataTypes::String, true)
            ->setField('api_url', DataTypes::String, false)
            ->setField('secret_ref', DataTypes::String, false, null, null, true)
            ->setField('integropay_merchant_token_ref', DataTypes::String, false, null, null, true)
            ->setField('integropay_device_token_ref', DataTypes::String, false, null, null, true)
            ->setField('activo', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $request = $context->getRequest();

        if ($method === 'GET' && $id === null) {
            // Con filtros/paginación → respuesta enriquecida; sin ellos → lista plana (selects).
            if ($this->wantsPaginated($request)) {
                return Result::ok($this->listPaginated($request), 'Emisores');
            }

            return Result::ok($this->listForSelects(), 'Emisores');
        }

        if ($method === 'GET' && $id !== null) {
            $row = $this->findEnriched((int) $id);
            if ($row === null) {
                return Result::fail('Emisor no encontrado.', 404);
            }

            return Result::ok($row, 'Emisor');
        }

        if ($method === 'POST' && $id === null) {
            $normalized = $this->normalizeInput($request);
            $rfcError = $this->validateRfc($normalized['rfc'] ?? null);
            if ($rfcError !== null) {
                return Result::fail($rfcError, 422);
            }
            $dup = $this->assertUniqueRfc((string) $normalized['rfc']);
            if ($dup !== null) {
                return Result::fail($dup, 422);
            }

            $created = $this->create($normalized, $context);
            if (!$created->isSuccess()) {
                return $created;
            }
            $newId = (int) (($created->getData()['id'] ?? 0));

            return Result::ok(
                $this->findEnriched($newId) ?? $created->getData(),
                'Emisor creado',
                201,
            );
        }

        if ($id === null) {
            return Result::fail('Se requiere id de emisor.', 400);
        }

        $issuerId = (int) $id;

        if ($method === 'PUT') {
            $normalized = $this->normalizeInput($request);
            $rfcError = $this->validateRfc($normalized['rfc'] ?? null);
            if ($rfcError !== null) {
                return Result::fail($rfcError, 422);
            }
            $dup = $this->assertUniqueRfc((string) $normalized['rfc'], $issuerId);
            if ($dup !== null) {
                return Result::fail($dup, 422);
            }

            $updated = $this->update($issuerId, $normalized, $context);
            if (!$updated->isSuccess()) {
                return $updated;
            }

            return Result::ok($this->findEnriched($issuerId) ?? $updated->getData(), 'Emisor actualizado');
        }

        if ($method === 'PATCH') {
            $normalized = $this->normalizeInput($request, true);
            if (array_key_exists('rfc', $normalized)) {
                $rfcError = $this->validateRfc($normalized['rfc']);
                if ($rfcError !== null) {
                    return Result::fail($rfcError, 422);
                }
                $dup = $this->assertUniqueRfc((string) $normalized['rfc'], $issuerId);
                if ($dup !== null) {
                    return Result::fail($dup, 422);
                }
            }

            $patched = $this->patch($issuerId, $normalized, $context);
            if (!$patched->isSuccess()) {
                return $patched;
            }

            return Result::ok($this->findEnriched($issuerId) ?? $patched->getData(), 'Emisor actualizado');
        }

        if ($method === 'DELETE') {
            $patched = $this->patch($issuerId, ['activo' => false], $context);
            if (!$patched->isSuccess()) {
                return $patched;
            }

            return Result::ok($this->findEnriched($issuerId) ?? $patched->getData(), 'Emisor desactivado');
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * Lista plana para selects (Instancias / Catálogo).
     *
     * @return list<array<string, mixed>>
     */
    private function listForSelects(): array
    {
        $rows = Capsule::table('emisor')
            ->orderBy('nombre')
            ->get([
                'id',
                'nombre',
                'rfc',
                'api_url',
                'secret_ref',
                'integropay_merchant_token_ref',
                'integropay_device_token_ref',
                'activo',
            ]);

        return $rows->map(fn (object $row): array => $this->mapRow($row))->all();
    }

    /**
     * @param array<string, mixed> $query
     */
    private function wantsPaginated(array $query): bool
    {
        return isset($query['page'])
            || isset($query['per_page'])
            || (isset($query['search']) && trim((string) $query['search']) !== '')
            || (isset($query['estado']) && trim((string) $query['estado']) !== '');
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   items:list<array<string,mixed>>,
     *   total:int,
     *   page:int,
     *   per_page:int,
     *   total_pages:int
     * }
     */
    private function listPaginated(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        // Subconsulta de conteo: evita GROUP BY frágil con ONLY_FULL_GROUP_BY.
        $counts = Capsule::table('instancia')
            ->selectRaw('emisor_id, COUNT(*) as instancias_vinculadas')
            ->groupBy('emisor_id');

        $builder = Capsule::table('emisor as e')
            ->leftJoinSub($counts, 'ic', 'ic.emisor_id', '=', 'e.id');

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('e.nombre', 'like', $like)
                    ->orWhere('e.rfc', 'like', $like);
            });
        }

        $estado = strtolower(trim((string) ($query['estado'] ?? '')));
        if (in_array($estado, ['activos', 'activo'], true)) {
            $builder->where('e.activo', 1);
        } elseif (in_array($estado, ['inactivos', 'inactivo'], true)) {
            $builder->where('e.activo', 0);
        }

        $total = (int) (clone $builder)->count('e.id');

        $rows = $builder
            ->orderBy('e.nombre')
            ->forPage($page, $perPage)
            ->get([
                'e.id',
                'e.nombre',
                'e.rfc',
                'e.api_url',
                'e.secret_ref',
                'e.integropay_merchant_token_ref',
                'e.integropay_device_token_ref',
                'e.activo',
                'ic.instancias_vinculadas',
            ]);

        $items = $rows->map(function (object $row): array {
            $mapped = $this->mapRow($row);
            $mapped['instancias_vinculadas'] = (int) ($row->instancias_vinculadas ?? 0);

            return $mapped;
        })->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEnriched(int $id): ?array
    {
        $row = Capsule::table('emisor as e')->where('e.id', $id)->first([
            'e.id',
            'e.nombre',
            'e.rfc',
            'e.api_url',
            'e.secret_ref',
            'e.integropay_merchant_token_ref',
            'e.integropay_device_token_ref',
            'e.activo',
        ]);

        if ($row === null) {
            return null;
        }

        $mapped = $this->mapRow($row);
        $mapped['instancias_vinculadas'] = (int) Capsule::table('instancia')
            ->where('emisor_id', $id)
            ->count();

        return $mapped;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input, bool $partial = false): array
    {
        $out = $input;

        if (array_key_exists('rfc', $out) || !$partial) {
            $out['rfc'] = strtoupper(trim((string) ($out['rfc'] ?? '')));
        }

        if (array_key_exists('nombre', $out)) {
            $out['nombre'] = trim((string) $out['nombre']);
        }

        foreach (['api_url', ...self::SENSITIVE_FIELDS] as $field) {
            if (!array_key_exists($field, $out)) {
                continue;
            }
            $value = trim((string) ($out[$field] ?? ''));
            // Si el cliente reenvía la máscara, no mutar el valor real en BD.
            if ($value === self::MASK) {
                unset($out[$field]);
                continue;
            }
            $out[$field] = $value === '' ? null : $value;
        }

        if (array_key_exists('activo', $out)) {
            $raw = $out['activo'];
            $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $out['activo'] = $parsed ?? (bool) (int) $raw;
        } elseif (!$partial) {
            $out['activo'] = true;
        }

        return $out;
    }

    private function validateRfc(mixed $rfc): ?string
    {
        $value = strtoupper(trim((string) ($rfc ?? '')));
        if ($value === '') {
            return 'El RFC es obligatorio.';
        }
        if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $value)) {
            return 'RFC inválido. Use 12 o 13 caracteres en mayúsculas (formato SAT).';
        }

        return null;
    }

    private function assertUniqueRfc(string $rfc, ?int $exceptId = null): ?string
    {
        $q = Capsule::table('emisor')->where('rfc', $rfc);
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }
        if ($q->exists()) {
            return 'Ya existe un emisor con ese RFC.';
        }

        return null;
    }

    /**
     * Serializa emisor con banderas has_* y enmascara refs si no puede editar.
     *
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        $secret = isset($row->secret_ref) && $row->secret_ref !== null && (string) $row->secret_ref !== ''
            ? (string) $row->secret_ref
            : null;
        $merchant = isset($row->integropay_merchant_token_ref)
            && $row->integropay_merchant_token_ref !== null
            && (string) $row->integropay_merchant_token_ref !== ''
            ? (string) $row->integropay_merchant_token_ref
            : null;
        $device = isset($row->integropay_device_token_ref)
            && $row->integropay_device_token_ref !== null
            && (string) $row->integropay_device_token_ref !== ''
            ? (string) $row->integropay_device_token_ref
            : null;

        $canEdit = RbacAuthHelper::can($this->authContext, 'config_emisores', 'editar');

        return [
            'id' => (int) $row->id,
            'nombre' => (string) $row->nombre,
            'rfc' => (string) $row->rfc,
            'api_url' => $row->api_url !== null ? (string) $row->api_url : null,
            'has_erp_secret' => $secret !== null,
            'has_merchant_token' => $merchant !== null,
            'has_device_token' => $device !== null,
            'secret_ref' => $canEdit ? $secret : ($secret !== null ? self::MASK : null),
            'integropay_merchant_token_ref' => $canEdit
                ? $merchant
                : ($merchant !== null ? self::MASK : null),
            'integropay_device_token_ref' => $canEdit
                ? $device
                : ($device !== null ? self::MASK : null),
            'activo' => (bool) (int) $row->activo,
        ];
    }
}
