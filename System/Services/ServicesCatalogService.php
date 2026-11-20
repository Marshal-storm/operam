<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\ServicesCatalogModel;

final class ServicesCatalogService extends ModelService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    protected function modelClass(): string
    {
        return ServicesCatalogModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('segment_id', DataTypes::Integer, true)
            ->setField('default_issuer_id', DataTypes::Integer, false)
            ->setField('code', DataTypes::String, true)
            ->setField('name', DataTypes::String, true)
            ->setField('description', DataTypes::String, false)
            ->setField('service_type', DataTypes::String, true)
            ->setField('unit_of_measure', DataTypes::String, true)
            ->setField('allow_package_conversion', DataTypes::Boolean, false, false)
            ->setField('package_service_id', DataTypes::Integer, false)
            ->setField('package_factor', DataTypes::Integer, false, 5)
            ->setField('is_active', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $auth = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery($auth, $context->getRequest());

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listPaginated($request), 'Catálogo de servicios');
        }
        if ($method === 'POST' && $id === null) {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }
            $normalized = $this->normalizePackageLink($request);
            $linkError = $this->assertPackageLink($normalized);
            if ($linkError !== null) {
                return Result::fail($linkError, 422);
            }

            return $this->create($normalized, $context);
        }
        if ($id === null) {
            return Result::fail('Se requiere id de servicio.', 400);
        }

        $serviceId = (int) $id;
        if ($method === 'PUT') {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }
            $normalized = $this->normalizePackageLink($request);
            $linkError = $this->assertPackageLink($normalized, $serviceId);
            if ($linkError !== null) {
                return Result::fail($linkError, 422);
            }

            return $this->update($serviceId, $normalized, $context);
        }
        if ($method === 'PATCH') {
            $normalized = $this->normalizePackageLink($request, true);
            $merged = $normalized;
            if (!array_key_exists('allow_package_conversion', $merged)
                || !array_key_exists('package_service_id', $merged)
            ) {
                $existing = Capsule::table('services')->where('id', $serviceId)->first();
                if ($existing !== null) {
                    if (!array_key_exists('allow_package_conversion', $merged)) {
                        $merged['allow_package_conversion'] = (bool) $existing->allow_package_conversion;
                    }
                    if (!array_key_exists('package_service_id', $merged)) {
                        $merged['package_service_id'] = $existing->package_service_id;
                    }
                    if (!array_key_exists('package_factor', $merged)) {
                        $merged['package_factor'] = (int) ($existing->package_factor ?? 5);
                    }
                }
            }
            $linkError = $this->assertPackageLink($merged, $serviceId);
            if ($linkError !== null) {
                return Result::fail($linkError, 422);
            }

            return $this->patch($serviceId, $normalized, $context);
        }
        if ($method === 'DELETE') {
            return $this->patch($serviceId, ['is_active' => false], $context);
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

        $builder = $this->baseQuery();

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('s.code', 'like', $like)
                    ->orWhere('s.name', 'like', $like)
                    ->orWhere('s.description', 'like', $like);
            });
        }

        $segment = trim((string) ($query['segment'] ?? ''));
        if ($segment !== '' && strtolower($segment) !== 'all' && strtolower($segment) !== 'todos') {
            $builder->where('seg.name', $segment);
        }

        $serviceType = trim((string) ($query['service_type'] ?? ''));
        if ($serviceType !== '' && strtolower($serviceType) !== 'all' && strtolower($serviceType) !== 'todos') {
            $builder->where('s.service_type', $serviceType);
        }

        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if ($status === 'active' || $status === 'available') {
            $builder->where('s.is_active', true);
        } elseif ($status === 'inactive') {
            $builder->where('s.is_active', false);
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderBy('s.id')
            ->forPage($page, $perPage)
            ->get([
                's.id',
                's.code',
                's.name',
                's.description',
                's.segment_id',
                'seg.name as segment_name',
                's.default_issuer_id',
                'e.name as default_issuer_name',
                's.service_type',
                's.unit_of_measure',
                's.allow_package_conversion',
                's.package_service_id',
                's.package_factor',
                'pkg.code as package_service_code',
                'pkg.name as package_service_name',
                's.is_active',
            ]);

        $items = $rows->map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'description' => $row->description !== null ? (string) $row->description : null,
                'segment_name' => (string) ($row->segment_name ?? ''),
                'segment_id' => (int) $row->segment_id,
                'default_issuer_name' => $row->default_issuer_name !== null
                    ? (string) $row->default_issuer_name
                    : null,
                'default_issuer_id' => $row->default_issuer_id !== null
                    ? (int) $row->default_issuer_id
                    : null,
                'service_type' => (string) $row->service_type,
                'unit_of_measure' => (string) $row->unit_of_measure,
                'allow_package_conversion' => (bool) $row->allow_package_conversion,
                'package_service_id' => $row->package_service_id !== null
                    ? (int) $row->package_service_id
                    : null,
                'package_service_code' => $row->package_service_code !== null
                    ? (string) $row->package_service_code
                    : null,
                'package_service_name' => $row->package_service_name !== null
                    ? (string) $row->package_service_name
                    : null,
                'package_factor' => (int) ($row->package_factor ?? 5),
                'is_active' => (bool) $row->is_active,
            ];
        })->all();

        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    private function baseQuery(): Builder
    {
        return Capsule::table('services as s')
            ->leftJoin('segments as seg', 'seg.id', '=', 's.segment_id')
            ->leftJoin('issuers as e', 'e.id', '=', 's.default_issuer_id')
            ->leftJoin('services as pkg', 'pkg.id', '=', 's.package_service_id');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizePackageLink(array $input, bool $partial = false): array
    {
        if (array_key_exists('allow_package_conversion', $input)) {
            $raw = $input['allow_package_conversion'];
            $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $input['allow_package_conversion'] = $parsed ?? (bool) (int) $raw;
        }

        if (array_key_exists('package_service_id', $input)) {
            $pkgId = $input['package_service_id'];
            if ($pkgId === null || $pkgId === '' || (int) $pkgId < 1) {
                $input['package_service_id'] = null;
            } else {
                $input['package_service_id'] = (int) $pkgId;
            }
        }

        if (array_key_exists('package_factor', $input)) {
            $factor = filter_var($input['package_factor'], FILTER_VALIDATE_INT);
            $input['package_factor'] = ($factor !== false && $factor > 0) ? $factor : 5;
        } elseif (!$partial) {
            $input['package_factor'] = 5;
        }

        if (
            array_key_exists('allow_package_conversion', $input)
            && $input['allow_package_conversion'] === false
        ) {
            $input['package_service_id'] = null;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function assertPackageLink(array $input, ?int $selfId = null): ?string
    {
        $allows = (bool) ($input['allow_package_conversion'] ?? false);
        if (!$allows) {
            return null;
        }

        $pkgId = isset($input['package_service_id']) ? (int) $input['package_service_id'] : 0;
        if ($pkgId < 1) {
            return 'Debes seleccionar el producto paquete destino (RF-2).';
        }
        if ($selfId !== null && $pkgId === $selfId) {
            return 'Un producto no puede convertirse a sí mismo.';
        }

        $pkg = Capsule::table('services')->where('id', $pkgId)->first();
        if ($pkg === null) {
            return 'El producto paquete seleccionado no existe.';
        }
        if ((string) $pkg->unit_of_measure !== 'pack_5_users') {
            return 'El destino debe ser un producto con unidad pack_5_users.';
        }

        return null;
    }
}
