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
            ->setField('segmento_id', DataTypes::Integer, true)
            ->setField('emisor_default_id', DataTypes::Integer, false)
            ->setField('codigo', DataTypes::String, true)
            ->setField('nombre', DataTypes::String, true)
            ->setField('descripcion', DataTypes::String, false)
            ->setField('tipo', DataTypes::String, true)
            ->setField('unidad_medida', DataTypes::String, true)
            ->setField('permite_conversion_paquete', DataTypes::Boolean, false, false)
            ->setField('servicio_paquete_id', DataTypes::Integer, false)
            ->setField('factor_paquete', DataTypes::Integer, false, 5)
            ->setField('activo', DataTypes::Boolean, false, true);
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
            if (($auth['segmento_id'] ?? null) !== null) {
                $request['segmento_id'] = (int) $auth['segmento_id'];
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
            if (($auth['segmento_id'] ?? null) !== null) {
                $request['segmento_id'] = (int) $auth['segmento_id'];
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
            if (!array_key_exists('permite_conversion_paquete', $merged)
                || !array_key_exists('servicio_paquete_id', $merged)
            ) {
                $existing = Capsule::table('servicio')->where('id', $serviceId)->first();
                if ($existing !== null) {
                    if (!array_key_exists('permite_conversion_paquete', $merged)) {
                        $merged['permite_conversion_paquete'] = (bool) $existing->permite_conversion_paquete;
                    }
                    if (!array_key_exists('servicio_paquete_id', $merged)) {
                        $merged['servicio_paquete_id'] = $existing->servicio_paquete_id;
                    }
                    if (!array_key_exists('factor_paquete', $merged)) {
                        $merged['factor_paquete'] = (int) ($existing->factor_paquete ?? 5);
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
            return $this->patch($serviceId, ['activo' => false], $context);
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
                $q->where('s.codigo', 'like', $like)
                    ->orWhere('s.nombre', 'like', $like)
                    ->orWhere('s.descripcion', 'like', $like);
            });
        }

        $segmento = trim((string) ($query['segmento'] ?? ''));
        if ($segmento !== '' && strtolower($segmento) !== 'todos') {
            $builder->where('seg.nombre', $segmento);
        }

        $tipo = trim((string) ($query['tipo'] ?? ''));
        if ($tipo !== '' && strtolower($tipo) !== 'todos') {
            $builder->where('s.tipo', $tipo);
        }

        $estado = strtolower(trim((string) ($query['estado'] ?? '')));
        if ($estado === 'disponibles' || $estado === 'activos') {
            $builder->where('s.activo', true);
        } elseif ($estado === 'inactivos') {
            $builder->where('s.activo', false);
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderBy('s.id')
            ->forPage($page, $perPage)
            ->get([
                's.id',
                's.codigo',
                's.nombre',
                's.descripcion',
                's.segmento_id',
                'seg.nombre as segmento',
                's.emisor_default_id',
                'e.nombre as emisor_default',
                's.tipo',
                's.unidad_medida',
                's.permite_conversion_paquete',
                's.servicio_paquete_id',
                's.factor_paquete',
                'pkg.codigo as servicio_paquete_codigo',
                'pkg.nombre as servicio_paquete_nombre',
                's.activo',
            ]);

        $items = $rows->map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'codigo' => (string) $row->codigo,
                'nombre' => (string) $row->nombre,
                'descripcion' => $row->descripcion !== null ? (string) $row->descripcion : null,
                'segmento' => (string) ($row->segmento ?? ''),
                'segmento_id' => (int) $row->segmento_id,
                'emisor_default' => $row->emisor_default !== null ? (string) $row->emisor_default : null,
                'emisor_default_id' => $row->emisor_default_id !== null ? (int) $row->emisor_default_id : null,
                'tipo' => (string) $row->tipo,
                'unidad_medida' => (string) $row->unidad_medida,
                'permite_conversion_paquete' => (bool) $row->permite_conversion_paquete,
                'servicio_paquete_id' => $row->servicio_paquete_id !== null
                    ? (int) $row->servicio_paquete_id
                    : null,
                'servicio_paquete_codigo' => $row->servicio_paquete_codigo !== null
                    ? (string) $row->servicio_paquete_codigo
                    : null,
                'servicio_paquete_nombre' => $row->servicio_paquete_nombre !== null
                    ? (string) $row->servicio_paquete_nombre
                    : null,
                'factor_paquete' => (int) ($row->factor_paquete ?? 5),
                'activo' => (bool) $row->activo,
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
        return Capsule::table('servicio as s')
            ->leftJoin('segmento as seg', 'seg.id', '=', 's.segmento_id')
            ->leftJoin('emisor as e', 'e.id', '=', 's.emisor_default_id')
            ->leftJoin('servicio as pkg', 'pkg.id', '=', 's.servicio_paquete_id');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizePackageLink(array $input, bool $partial = false): array
    {
        if (array_key_exists('permite_conversion_paquete', $input)) {
            $raw = $input['permite_conversion_paquete'];
            $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $input['permite_conversion_paquete'] = $parsed ?? (bool) (int) $raw;
        }

        if (array_key_exists('servicio_paquete_id', $input)) {
            $pkgId = $input['servicio_paquete_id'];
            if ($pkgId === null || $pkgId === '' || (int) $pkgId < 1) {
                $input['servicio_paquete_id'] = null;
            } else {
                $input['servicio_paquete_id'] = (int) $pkgId;
            }
        }

        if (array_key_exists('factor_paquete', $input)) {
            $factor = filter_var($input['factor_paquete'], FILTER_VALIDATE_INT);
            $input['factor_paquete'] = ($factor !== false && $factor > 0) ? $factor : 5;
        } elseif (!$partial) {
            $input['factor_paquete'] = 5;
        }

        if (
            array_key_exists('permite_conversion_paquete', $input)
            && $input['permite_conversion_paquete'] === false
        ) {
            $input['servicio_paquete_id'] = null;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function assertPackageLink(array $input, ?int $selfId = null): ?string
    {
        $allows = (bool) ($input['permite_conversion_paquete'] ?? false);
        if (!$allows) {
            return null;
        }

        $pkgId = isset($input['servicio_paquete_id']) ? (int) $input['servicio_paquete_id'] : 0;
        if ($pkgId < 1) {
            return 'Debes seleccionar el producto paquete destino (RF-2).';
        }
        if ($selfId !== null && $pkgId === $selfId) {
            return 'Un producto no puede convertirse a sí mismo.';
        }

        $pkg = Capsule::table('servicio')->where('id', $pkgId)->first();
        if ($pkg === null) {
            return 'El producto paquete seleccionado no existe.';
        }
        if ((string) $pkg->unidad_medida !== 'paquete_5_usuarios') {
            return 'El destino debe ser un producto con unidad paquete_5_usuarios.';
        }

        return null;
    }
}
