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
            ->setField('code', DataTypes::String, true)
            ->setField('name', DataTypes::String, true)
            ->setField('issuer_id', DataTypes::Integer, true)
            ->setField('segment_id', DataTypes::Integer, true)
            ->setField('access_url', DataTypes::String, false)
            ->setField('server_ip', DataTypes::String, false)
            ->setField('status', DataTypes::String, true);
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
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
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
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }

            return $this->update($instanceId, $request, $context);
        }
        if ($method === 'PATCH') {
            return $this->patch($instanceId, $request, $context);
        }
        if ($method === 'DELETE') {
            return $this->patch($instanceId, ['status' => 'decommissioned'], $context);
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
     *   by_issuer:list<array<string,mixed>>
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
                $q->where('i.name', 'like', $like)
                    ->orWhere('i.code', 'like', $like)
                    ->orWhere('i.server_ip', 'like', $like)
                    ->orWhere('i.access_url', 'like', $like);
            });
        }

        if (!empty($query['issuer_id'])) {
            $builder->where('i.issuer_id', (int) $query['issuer_id']);
        }

        $segment = trim((string) ($query['segment'] ?? ''));
        if ($segment !== '' && strtolower($segment) !== 'all' && strtolower($segment) !== 'todos') {
            $builder->where('seg.name', $segment);
        }

        $status = trim((string) ($query['status'] ?? ''));
        if ($status !== '' && strtolower($status) !== 'all' && strtolower($status) !== 'todos') {
            $builder->where('i.status', $status);
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderBy('i.id')
            ->forPage($page, $perPage)
            ->get([
                'i.id',
                'i.code',
                'i.name',
                'i.issuer_id',
                'e.name as issuer_name',
                'i.segment_id',
                'seg.name as segment_name',
                'i.access_url',
                'i.server_ip',
                'i.status',
            ]);

        $items = $rows->map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'issuer_name' => (string) ($row->issuer_name ?? ''),
                'issuer_id' => (int) $row->issuer_id,
                'segment_name' => (string) ($row->segment_name ?? ''),
                'segment_id' => (int) $row->segment_id,
                'access_url' => $row->access_url !== null ? (string) $row->access_url : null,
                'server_ip' => $row->server_ip !== null ? (string) $row->server_ip : null,
                'status' => (string) $row->status,
            ];
        })->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'by_issuer' => $this->issuerSummary(),
        ];
    }

    /**
     * Resumen por emisor del catálogo (incluye emisores sin instancias).
     *
     * @return list<array<string, mixed>>
     */
    private function issuerSummary(): array
    {
        // Incluye emisores inactivos para que las tarjetas informativas tengan conteos reales.
        $rows = Capsule::table('issuers as e')
            ->leftJoin('instances as i', 'i.issuer_id', '=', 'e.id')
            ->selectRaw(
                'e.id as issuer_id, e.name as issuer_name, ' .
                'COUNT(i.id) as total, ' .
                "SUM(CASE WHEN i.status = 'operational' THEN 1 ELSE 0 END) as operational, " .
                "SUM(CASE WHEN i.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance, " .
                "SUM(CASE WHEN i.status = 'suspended' THEN 1 ELSE 0 END) as suspended"
            )
            ->groupBy('e.id', 'e.name')
            ->orderBy('e.name')
            ->get();

        return $rows->map(static function (object $row): array {
            return [
                'issuer_id' => (int) $row->issuer_id,
                'issuer_name' => (string) ($row->issuer_name ?? 'Sin emisor'),
                'total' => (int) $row->total,
                'operational' => (int) $row->operational,
                'maintenance' => (int) $row->maintenance,
                'suspended' => (int) $row->suspended,
            ];
        })->all();
    }

    private function baseQuery(): Builder
    {
        return Capsule::table('instances as i')
            ->leftJoin('segments as seg', 'seg.id', '=', 'i.segment_id')
            ->leftJoin('issuers as e', 'e.id', '=', 'i.issuer_id');
    }
}
