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

            return $this->create($request, $context);
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

            return $this->update($serviceId, $request, $context);
        }
        if ($method === 'PATCH') {
            return $this->patch($serviceId, $request, $context);
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
            ->leftJoin('emisor as e', 'e.id', '=', 's.emisor_default_id');
    }
}
