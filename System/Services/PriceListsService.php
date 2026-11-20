<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PriceListsModel;
use System\Support\AuditLogger;
use Throwable;

final class PriceListsService extends ModelService
{
    protected function modelClass(): string
    {
        return PriceListsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('segment_id', DataTypes::Integer, true)
            ->setField('name', DataTypes::String, true)
            ->setField('currency', DataTypes::String, true)
            ->setField('is_default', DataTypes::Boolean, false, false)
            ->setField('valid_from', DataTypes::Date, true)
            ->setField('valid_until', DataTypes::Date, false)
            ->setField('is_active', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $auth = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery($auth, $context->getRequest());

        if ($method === 'GET' && $id === null) {
            return Result::ok($this->listEnriched($auth), 'Listas de precios');
        }
        if ($method === 'POST' && $id === null) {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }

            return $this->persistWithUniqueDefault($request, null, $context, 'create');
        }
        if ($id === null) {
            return Result::fail('Se requiere id de lista.', 400);
        }

        $listId = (int) $id;
        if ($method === 'PUT') {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }

            return $this->persistWithUniqueDefault($request, $listId, $context, 'update');
        }
        if ($method === 'PATCH') {
            return $this->persistWithUniqueDefault($request, $listId, $context, 'patch');
        }
        if ($method === 'DELETE') {
            $before = $this->priceListSnapshot($listId);
            $deactivated = $this->patch($listId, ['is_active' => false], $context);
            if ($deactivated->isSuccess()) {
                $this->auditPriceList($auth, $listId, 'status_change', $before);
            }

            return $deactivated;
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * Guarda la lista y, si queda como default, desmarca las demás del mismo segmento y moneda.
     *
     * @param array<string, mixed> $request
     */
    private function persistWithUniqueDefault(
        array $request,
        ?int $listId,
        Context $context,
        string $mode,
    ): Result {
        // La bitácora se escribe después del commit para no ensuciar la transacción de negocio.
        /** @var list<array{auth: array<string, mixed>, id: int, action: string, description: string, before: array<string, mixed>|null, after: array<string, mixed>|null, primary: bool}> $queued */
        $queued = [];
        try {
            $result = Capsule::connection()->transaction(function () use ($request, $listId, $context, $mode, &$queued): Result {
                $auth = $context->getData('auth', []);
                $before = ($listId !== null && $listId > 0) ? $this->priceListSnapshot($listId) : null;
                $result = match ($mode) {
                    'create' => $this->create($request, $context),
                    'update' => $this->update((int) $listId, $request, $context),
                    default => $this->patch((int) $listId, $request, $context),
                };

                if (!$result->isSuccess()) {
                    throw new RuntimeException($result->getMessage());
                }

                $savedId = $listId ?? (int) (($result->getData()['id'] ?? 0));
                if ($savedId < 1) {
                    return $result;
                }

                $row = Capsule::table('price_lists')->where('id', $savedId)->first();
                if ($row !== null && (bool) (int) $row->is_default) {
                    // Solo se auditan hermanas que sí eran predeterminadas; el UPDATE sigue tocando al resto.
                    $demoted = Capsule::table('price_lists')
                        ->where('segment_id', (int) $row->segment_id)
                        ->where('currency', (string) $row->currency)
                        ->where('id', '!=', $savedId)
                        ->where('is_default', 1)
                        ->get([
                            'id',
                            'segment_id',
                            'name',
                            'currency',
                            'is_default',
                            'valid_from',
                            'valid_until',
                            'is_active',
                        ]);

                    Capsule::table('price_lists')
                        ->where('segment_id', (int) $row->segment_id)
                        ->where('currency', (string) $row->currency)
                        ->where('id', '!=', $savedId)
                        ->update([
                            'is_default' => false,
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                        ]);

                    foreach ($demoted as $sibling) {
                        $siblingBefore = $this->mapPriceListAudit($sibling);
                        $siblingAfter = $siblingBefore;
                        $siblingAfter['is_default'] = false;
                        $queued[] = [
                            'auth' => $auth,
                            'id' => (int) $sibling->id,
                            'action' => 'update',
                            'description' => sprintf('Se quitó la lista predeterminada «%s»', (string) $sibling->name),
                            'before' => $siblingBefore,
                            'after' => $siblingAfter,
                            'primary' => false,
                        ];
                    }
                }

                $action = $mode === 'create'
                    ? 'create'
                    : (($mode === 'patch' && $this->isStatusToggle($request, $before)) ? 'status_change' : 'update');
                $queued[] = [
                    'auth' => $auth,
                    'id' => $savedId,
                    'action' => $action,
                    'description' => '',
                    'before' => $before,
                    'after' => null,
                    'primary' => true,
                ];

                return $result;
            });
        } catch (Throwable $exception) {
            return Result::fail($exception->getMessage(), 422);
        }

        foreach ($queued as $event) {
            if ($event['primary']) {
                $this->auditPriceList($event['auth'], $event['id'], $event['action'], $event['before']);
                continue;
            }
            AuditLogger::log(
                $event['auth'],
                'config_prices',
                'price_list',
                (string) $event['id'],
                $event['action'],
                $event['description'],
                $event['before'],
                $event['after'],
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $auth
     * @return list<array<string, mixed>>
     */
    private function listEnriched(array $auth = []): array
    {
        $builder = Capsule::table('price_lists as lp')
            ->leftJoin('segments as seg', 'seg.id', '=', 'lp.segment_id');

        if (($auth['segment_id'] ?? null) !== null) {
            $builder->where('lp.segment_id', (int) $auth['segment_id']);
        }

        $lists = $builder
            ->orderBy('lp.id')
            ->get([
                'lp.id',
                'lp.name',
                'lp.segment_id',
                'seg.name as segment_name',
                'lp.currency',
                'lp.is_default',
                'lp.valid_from',
                'lp.valid_until',
                'lp.is_active',
            ]);

        // Orden por min_quantity para que el cliente agrupe escalones RF-2 en secuencia.
        $items = Capsule::table('price_list_items as lpi')
            ->leftJoin('services as s', 's.id', '=', 'lpi.service_id')
            ->orderBy('lpi.price_list_id')
            ->orderBy('lpi.service_id')
            ->orderBy('lpi.min_quantity')
            ->orderBy('lpi.frequency')
            ->get([
                'lpi.id',
                'lpi.price_list_id',
                'lpi.service_id',
                's.code as service_code',
                's.name as service_name',
                'lpi.frequency',
                'lpi.min_quantity',
                'lpi.max_quantity',
                'lpi.unit_price',
            ])
            ->groupBy('price_list_id');

        return $lists->map(static function (object $list) use ($items): array {
            $listItems = ($items->get($list->id) ?? collect())->map(static function (object $item): array {
                return [
                    'id' => (int) $item->id,
                    'service_id' => (int) $item->service_id,
                    'service_code' => (string) ($item->service_code ?? ''),
                    'service_name' => (string) ($item->service_name ?? ''),
                    'frequency' => (string) $item->frequency,
                    'min_quantity' => (int) $item->min_quantity,
                    'max_quantity' => $item->max_quantity !== null ? (int) $item->max_quantity : null,
                    'unit_price' => (string) $item->unit_price,
                ];
            })->values()->all();

            return [
                'id' => (int) $list->id,
                'name' => (string) $list->name,
                'segment_name' => (string) ($list->segment_name ?? ''),
                'segment_id' => (int) $list->segment_id,
                'currency' => (string) $list->currency,
                'is_default' => (bool) $list->is_default,
                'valid_from' => (string) $list->valid_from,
                'valid_until' => $list->valid_until !== null ? (string) $list->valid_until : null,
                'is_active' => (bool) $list->is_active,
                'items' => $listItems,
            ];
        })->all();
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, mixed>|null $before
     */
    private function auditPriceList(array $auth, int $id, string $action, ?array $before): void
    {
        $after = $this->priceListSnapshot($id);
        $name = (string) ($after['name'] ?? $before['name'] ?? 'lista de precios');
        $active = (bool) ($after['is_active'] ?? false);
        $description = match ($action) {
            'create' => "Se creó la lista de precios «{$name}»",
            'status_change' => $active
                ? "Se activó la lista de precios «{$name}»"
                : "Se desactivó la lista de precios «{$name}»",
            default => "Se actualizó la lista de precios «{$name}»",
        };

        AuditLogger::log(
            $auth,
            'config_prices',
            'price_list',
            (string) $id,
            $action,
            $description,
            $before,
            $after,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function priceListSnapshot(int $id): ?array
    {
        $row = Capsule::table('price_lists')->where('id', $id)->first([
            'id',
            'segment_id',
            'name',
            'currency',
            'is_default',
            'valid_from',
            'valid_until',
            'is_active',
        ]);

        return $row === null ? null : $this->mapPriceListAudit($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPriceListAudit(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'segment_id' => (int) $row->segment_id,
            'name' => (string) $row->name,
            'currency' => (string) $row->currency,
            'is_default' => (bool) (int) $row->is_default,
            'valid_from' => $row->valid_from !== null ? (string) $row->valid_from : null,
            'valid_until' => $row->valid_until !== null ? (string) $row->valid_until : null,
            'is_active' => (bool) (int) $row->is_active,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $before
     */
    private function isStatusToggle(array $input, ?array $before): bool
    {
        $ignore = ['segment', 'page', 'per_page', 'search'];
        $keys = [];
        foreach ($input as $key => $value) {
            $name = (string) $key;
            if (in_array($name, $ignore, true)) {
                continue;
            }
            if ($name === 'segment_id' && $before !== null && array_key_exists('segment_id', $before)) {
                if ($value === null || $value === '' || (int) $value === (int) $before['segment_id']) {
                    continue;
                }
            }
            $keys[] = $name;
        }

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $name) {
            if (!in_array($name, ['is_active', 'status'], true)) {
                return false;
            }
        }

        return true;
    }
}
