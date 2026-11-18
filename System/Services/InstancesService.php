<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\InstancesModel;

final class InstancesService extends ModelService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    protected function modelClass(): string
    {
        return InstancesModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('codigo', DataTypes::String, true)
            ->setField('nombre', DataTypes::String, true)
            ->setField('emisor_id', DataTypes::Integer, true)
            ->setField('segmento_id', DataTypes::Integer, true)
            ->setField('url_acceso', DataTypes::String, false)
            ->setField('ip_servidor', DataTypes::String, false)
            ->setField('estado', DataTypes::String, true);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $auth = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery($auth, $context->getRequest());

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listPaginated($request), 'Instancias');
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
            return Result::fail('Se requiere id de instancia.', 400);
        }

        $instanceId = (int) $id;
        if ($method === 'PUT') {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segmento_id'] ?? null) !== null) {
                $request['segmento_id'] = (int) $auth['segmento_id'];
            }

            return $this->update($instanceId, $request, $context);
        }
        if ($method === 'PATCH') {
            return $this->patch($instanceId, $request, $context);
        }
        if ($method === 'DELETE') {
            return $this->patch($instanceId, ['estado' => 'baja'], $context);
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   items:list<array<string,mixed>>,
     *   total:int,
     *   page:int,
     *   per_page:int,
     *   total_pages:int,
     *   by_emitter:list<array<string,mixed>>
     * }
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
                $q->where('i.nombre', 'like', $like)
                    ->orWhere('i.codigo', 'like', $like)
                    ->orWhere('i.ip_servidor', 'like', $like)
                    ->orWhere('i.url_acceso', 'like', $like);
            });
        }

        if (!empty($query['emisor_id'])) {
            $builder->where('i.emisor_id', (int) $query['emisor_id']);
        }

        $segmento = trim((string) ($query['segmento'] ?? ''));
        if ($segmento !== '' && strtolower($segmento) !== 'todos') {
            $builder->where('seg.nombre', $segmento);
        }

        $estado = trim((string) ($query['estado'] ?? ''));
        if ($estado !== '' && strtolower($estado) !== 'todos') {
            $builder->where('i.estado', $estado);
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderBy('i.id')
            ->forPage($page, $perPage)
            ->get([
                'i.id',
                'i.codigo',
                'i.nombre',
                'i.emisor_id',
                'e.nombre as emisor',
                'i.segmento_id',
                'seg.nombre as segmento',
                'i.url_acceso',
                'i.ip_servidor',
                'i.estado',
            ]);

        $items = $rows->map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'codigo' => (string) $row->codigo,
                'nombre' => (string) $row->nombre,
                'emisor' => (string) ($row->emisor ?? ''),
                'emisor_id' => (int) $row->emisor_id,
                'segmento' => (string) ($row->segmento ?? ''),
                'segmento_id' => (int) $row->segmento_id,
                'url_acceso' => $row->url_acceso !== null ? (string) $row->url_acceso : null,
                'ip_servidor' => $row->ip_servidor !== null ? (string) $row->ip_servidor : null,
                'estado' => (string) $row->estado,
            ];
        })->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'by_emitter' => $this->emitterSummary(),
        ];
    }

    /**
     * Resumen por emisor del catálogo (incluye emisores sin instancias).
     *
     * @return list<array<string, mixed>>
     */
    private function emitterSummary(): array
    {
        // Incluye emisores inactivos para que las tarjetas informativas tengan conteos reales.
        $rows = Capsule::table('emisor as e')
            ->leftJoin('instancia as i', 'i.emisor_id', '=', 'e.id')
            ->selectRaw(
                'e.id as emisor_id, e.nombre as emisor, ' .
                'COUNT(i.id) as total, ' .
                'SUM(CASE WHEN i.estado = \'operativa\' THEN 1 ELSE 0 END) as operativas, ' .
                'SUM(CASE WHEN i.estado = \'mantenimiento\' THEN 1 ELSE 0 END) as mantenimiento, ' .
                'SUM(CASE WHEN i.estado = \'suspendida\' THEN 1 ELSE 0 END) as suspendidas'
            )
            ->groupBy('e.id', 'e.nombre')
            ->orderBy('e.nombre')
            ->get();

        return $rows->map(static function (object $row): array {
            return [
                'emisor_id' => (int) $row->emisor_id,
                'emisor' => (string) ($row->emisor ?? 'Sin emisor'),
                'total' => (int) $row->total,
                'operativas' => (int) $row->operativas,
                'mantenimiento' => (int) $row->mantenimiento,
                'suspendidas' => (int) $row->suspendidas,
            ];
        })->all();
    }

    private function baseQuery(): Builder
    {
        return Capsule::table('instancia as i')
            ->leftJoin('segmento as seg', 'seg.id', '=', 'i.segmento_id')
            ->leftJoin('emisor as e', 'e.id', '=', 'i.emisor_id');
    }
}
