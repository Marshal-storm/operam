<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\ClientsModel;
use System\Support\AuditLogger;
use System\Support\ClientRules;

/**
 * CRUD del directorio de clientes — endpoint `/api/clients` (alias `/api/clientes`).
 * Paginación en servidor, alcance de segmento RBAC y auditoría obligatoria.
 */
final class ClientsService extends ModelService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    private const MODULE_KEY = 'clients';

    /** @var array<string, mixed> */
    private array $authContext = [];

    protected function modelClass(): string
    {
        return ClientsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('segment_id', DataTypes::Integer, true)
            ->setField('issuer_id', DataTypes::Integer, true)
            ->setField('erp_client_id', DataTypes::String, false)
            ->setField('name', DataTypes::String, true)
            ->setField('legal_name', DataTypes::String, true)
            ->setField('rfc', DataTypes::String, true)
            ->setField('contact_name', DataTypes::String, false)
            ->setField('contact_email', DataTypes::String, false)
            ->setField('contact_phone', DataTypes::String, false)
            ->setField('credit_days', DataTypes::Integer, false)
            ->setField('stamp_on_issue', DataTypes::Boolean, false, false)
            ->setField('exclude_suspension', DataTypes::Boolean, false, false)
            ->setField('active', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $request = RbacAuthHelper::applySegmentFilterToQuery(
            $this->authContext,
            $context->getRequest(),
        );

        if ($method === 'GET') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                return Result::fail('Sin permiso para consultar clientes.', 403);
            }

            if ($id === null) {
                return Result::ok($this->listPaginated($request), 'Clientes');
            }

            $row = $this->findEnriched((int) $id);
            if ($row === null) {
                return Result::fail('Cliente no encontrado.', 404);
            }
            if (!$this->isWithinSegmentScope((int) $row['segment_id'])) {
                return Result::fail('Cliente fuera de tu alcance de segmento.', 403);
            }

            return Result::ok($row, 'Cliente');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                return Result::fail('Sin permiso para crear clientes.', 403);
            }

            $prepared = $this->prepareWritePayload($request, false);
            if ($prepared instanceof Result) {
                return $prepared;
            }

            $created = $this->create($prepared, $context);
            if (!$created->isSuccess()) {
                return $created;
            }
            $newId = (int) (($created->getData()['id'] ?? 0));
            if ($newId > 0) {
                $this->touchUtcTimestamps($newId, true);
                $this->auditClient($newId, 'create', null);
            }

            return Result::ok(
                $this->findEnriched($newId) ?? $created->getData(),
                'Cliente creado',
                201,
            );
        }

        if ($id === null) {
            return Result::fail('Se requiere id de cliente.', 400);
        }

        $clientId = (int) $id;
        $existing = $this->clientRaw($clientId);
        if ($existing === null) {
            return Result::fail('Cliente no encontrado.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $existing->segment_id)) {
            return Result::fail('Cliente fuera de tu alcance de segmento.', 403);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar clientes.', 403);
            }

            $partial = $method === 'PATCH';
            $prepared = $this->prepareWritePayload($request, $partial, $clientId);
            if ($prepared instanceof Result) {
                return $prepared;
            }

            $before = $existing;
            $result = $partial
                ? $this->patch($clientId, $prepared, $context)
                : $this->update($clientId, $prepared, $context);
            if (!$result->isSuccess()) {
                return $result;
            }

            $this->touchUtcTimestamps($clientId, false);
            $action = $this->isStatusOnly($prepared) ? 'status_change' : 'update';
            $this->auditClient($clientId, $action, $before);

            return Result::ok(
                $this->findEnriched($clientId) ?? $result->getData(),
                $action === 'status_change' ? 'Estado del cliente actualizado' : 'Cliente actualizado',
            );
        }

        if ($method === 'DELETE') {
            $contractsCount = $this->contractsCount($clientId);
            if ($contractsCount > 0) {
                return Result::fail(
                    'No se puede eliminar: el cliente tiene contratos históricos. Desactívalo (active = false) para retirarlo del directorio.',
                    422,
                );
            }

            // Sin contratos: desactivar si no hay can_delete; borrar físico solo con permiso delete.
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'delete')) {
                if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                    return Result::fail('Sin permiso para eliminar o desactivar clientes.', 403);
                }

                $before = $existing;
                $patched = $this->patch($clientId, ['active' => false], $context);
                if (!$patched->isSuccess()) {
                    return $patched;
                }
                $this->touchUtcTimestamps($clientId, false);
                $this->auditClient($clientId, 'status_change', $before);

                return Result::ok(
                    $this->findEnriched($clientId) ?? $patched->getData(),
                    'Cliente desactivado (sin borrado físico)',
                );
            }

            $before = $existing;
            $model = $this->findModel($clientId);
            if ($model === null) {
                return Result::fail('Cliente no encontrado.', 404);
            }
            $model->delete();
            $this->auditClient($clientId, 'delete', $before, true);

            return Result::ok(['id' => $clientId], 'Cliente eliminado');
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int,
     *   summary: array{
     *     total: int,
     *     active_count: int,
     *     auto_stamp_count: int,
     *     excluded_suspension_count: int
     *   }
     * }
     */
    private function listPaginated(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $builder = $this->baseEnrichedQuery();
        $this->applyListFilters($builder, $query, true);

        $total = (int) (clone $builder)->count('cli.id');

        $rows = $builder
            ->orderByRaw('COALESCE(cli.updated_at, cli.created_at) DESC, cli.id DESC')
            ->forPage($page, $perPage)
            ->get($this->selectColumns());

        $items = $rows->map(fn (object $row): array => $this->mapRow($row))->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil(max(1, $total) / $perPage)),
            'summary' => $this->buildSummary($query),
        ];
    }

    /**
     * KPIs de portafolio: respetan alcance de segmento (y emisor si se filtró).
     *
     * @param array<string, mixed> $query
     * @return array{
     *   total: int,
     *   active_count: int,
     *   auto_stamp_count: int,
     *   excluded_suspension_count: int
     * }
     */
    private function buildSummary(array $query): array
    {
        $builder = Capsule::table('clients as cli');
        $this->applySegmentScope($builder, $query);

        $issuerId = isset($query['issuer_id']) && $query['issuer_id'] !== '' && $query['issuer_id'] !== null
            ? (int) $query['issuer_id']
            : null;
        if ($issuerId !== null && $issuerId > 0) {
            $builder->where('cli.issuer_id', $issuerId);
        }

        $row = $builder
            ->selectRaw(
                'COUNT(*) as total,
                 SUM(CASE WHEN cli.active = 1 THEN 1 ELSE 0 END) as active_count,
                 SUM(CASE WHEN cli.stamp_on_issue = 1 THEN 1 ELSE 0 END) as auto_stamp_count,
                 SUM(CASE WHEN cli.exclude_suspension = 1 THEN 1 ELSE 0 END) as excluded_suspension_count'
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active_count' => (int) ($row->active_count ?? 0),
            'auto_stamp_count' => (int) ($row->auto_stamp_count ?? 0),
            'excluded_suspension_count' => (int) ($row->excluded_suspension_count ?? 0),
        ];
    }

    private function baseEnrichedQuery(): Builder
    {
        $activeContracts = Capsule::table('contracts')
            ->selectRaw('client_id, COUNT(*) as active_contracts_count')
            ->where('status', 'active')
            ->groupBy('client_id');

        return Capsule::table('clients as cli')
            ->leftJoin('segments as s', 's.id', '=', 'cli.segment_id')
            ->leftJoin('issuers as i', 'i.id', '=', 'cli.issuer_id')
            ->leftJoinSub($activeContracts, 'ac', 'ac.client_id', '=', 'cli.id');
    }

    /**
     * @return list<string>
     */
    private function selectColumns(): array
    {
        return [
            'cli.id',
            'cli.segment_id',
            'cli.issuer_id',
            'cli.erp_client_id',
            'cli.name',
            'cli.legal_name',
            'cli.rfc',
            'cli.contact_name',
            'cli.contact_email',
            'cli.contact_phone',
            'cli.credit_days',
            'cli.stamp_on_issue',
            'cli.exclude_suspension',
            'cli.active',
            'cli.created_at',
            'cli.updated_at',
            's.name as segment_name',
            'i.name as issuer_name',
            'ac.active_contracts_count',
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function applyListFilters(Builder $builder, array $query, bool $includeSearchAndStatus): void
    {
        $this->applySegmentScope($builder, $query);

        if ($includeSearchAndStatus) {
            $search = trim((string) ($query['search'] ?? ''));
            if ($search !== '') {
                $like = '%' . $search . '%';
                $builder->where(static function (Builder $q) use ($like): void {
                    $q->where('cli.name', 'like', $like)
                        ->orWhere('cli.legal_name', 'like', $like)
                        ->orWhere('cli.rfc', 'like', $like)
                        ->orWhere('cli.erp_client_id', 'like', $like)
                        ->orWhere('cli.contact_email', 'like', $like);
                });
            }

            if (array_key_exists('active', $query) && $query['active'] !== '' && $query['active'] !== null) {
                $active = filter_var($query['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($active === null) {
                    $active = (bool) (int) $query['active'];
                }
                $builder->where('cli.active', $active ? 1 : 0);
            } else {
                $status = strtolower(trim((string) ($query['status'] ?? '')));
                if ($status === 'active') {
                    $builder->where('cli.active', 1);
                } elseif ($status === 'inactive') {
                    $builder->where('cli.active', 0);
                }
            }
        }

        $issuerId = isset($query['issuer_id']) && $query['issuer_id'] !== '' && $query['issuer_id'] !== null
            ? (int) $query['issuer_id']
            : null;
        if ($issuerId !== null && $issuerId > 0) {
            $builder->where('cli.issuer_id', $issuerId);
        }

        if (array_key_exists('exclude_suspension', $query)
            && $query['exclude_suspension'] !== ''
            && $query['exclude_suspension'] !== null
        ) {
            $flag = filter_var($query['exclude_suspension'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($flag === null) {
                $flag = (bool) (int) $query['exclude_suspension'];
            }
            $builder->where('cli.exclude_suspension', $flag ? 1 : 0);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function applySegmentScope(Builder $builder, array $query): void
    {
        $locked = $this->authContext['segment_id'] ?? null;
        if ($locked !== null) {
            $builder->where('cli.segment_id', (int) $locked);

            return;
        }

        $segmentId = isset($query['segment_id']) && $query['segment_id'] !== '' && $query['segment_id'] !== null
            ? (int) $query['segment_id']
            : null;
        if ($segmentId !== null && $segmentId > 0) {
            $builder->where('cli.segment_id', $segmentId);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEnriched(int $id): ?array
    {
        $row = $this->baseEnrichedQuery()
            ->where('cli.id', $id)
            ->first($this->selectColumns());

        return $row !== null ? $this->mapRow($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'segment_id' => (int) $row->segment_id,
            'issuer_id' => (int) $row->issuer_id,
            'erp_client_id' => $row->erp_client_id !== null && (string) $row->erp_client_id !== ''
                ? (string) $row->erp_client_id
                : null,
            'name' => (string) $row->name,
            'legal_name' => (string) $row->legal_name,
            'rfc' => (string) $row->rfc,
            'contact_name' => $row->contact_name !== null && (string) $row->contact_name !== ''
                ? (string) $row->contact_name
                : null,
            'contact_email' => $row->contact_email !== null && (string) $row->contact_email !== ''
                ? (string) $row->contact_email
                : null,
            'contact_phone' => $row->contact_phone !== null && (string) $row->contact_phone !== ''
                ? (string) $row->contact_phone
                : null,
            'credit_days' => (int) $row->credit_days,
            'stamp_on_issue' => (bool) (int) $row->stamp_on_issue,
            'exclude_suspension' => (bool) (int) $row->exclude_suspension,
            'active' => (bool) (int) $row->active,
            'segment_name' => $row->segment_name !== null ? (string) $row->segment_name : null,
            'issuer_name' => $row->issuer_name !== null ? (string) $row->issuer_name : null,
            'active_contracts_count' => (int) ($row->active_contracts_count ?? 0),
            'created_at' => $row->created_at !== null ? (string) $row->created_at : null,
            'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
        ];
    }

    /**
     * Normaliza, valida RBAC de segmento, RFC y herencia de días de crédito.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|Result
     */
    private function prepareWritePayload(array $input, bool $partial, ?int $clientId = null): array|Result
    {
        $denied = RbacAuthHelper::assertSegmentWriteAllowed($this->authContext, $input);
        if ($denied !== null) {
            return Result::fail($denied, 403);
        }

        $lockedSegment = $this->authContext['segment_id'] ?? null;
        if ($lockedSegment !== null) {
            $input['segment_id'] = (int) $lockedSegment;
        }

        $out = $this->normalizeInput($input, $partial);

        if (!$partial || array_key_exists('rfc', $out)) {
            $rfcError = ClientRules::validateRfcFormat((string) ($out['rfc'] ?? ''));
            if ($rfcError !== null) {
                return Result::fail($rfcError, 422);
            }
            $out['rfc'] = ClientRules::normalizeRfc((string) $out['rfc']);
            $dup = $this->assertUniqueRfc($out['rfc'], $clientId);
            if ($dup !== null) {
                return Result::fail($dup, 422);
            }
        }

        if (!$partial || array_key_exists('segment_id', $out) || array_key_exists('credit_days', $out)) {
            $segmentId = isset($out['segment_id'])
                ? (int) $out['segment_id']
                : ($clientId !== null ? (int) ($this->clientRaw($clientId)?->segment_id ?? 0) : 0);

            if ($segmentId <= 0 && !$partial) {
                return Result::fail('El segmento es obligatorio.', 422);
            }

            if ($segmentId > 0 && !$this->segmentExists($segmentId)) {
                return Result::fail('El segmento indicado no existe.', 422);
            }

            $needsCredit = !$partial
                || array_key_exists('credit_days', $out)
                || (array_key_exists('segment_id', $out) && !array_key_exists('credit_days', $input));

            if ($needsCredit) {
                $defaultDays = $segmentId > 0 ? $this->segmentDefaultCreditDays($segmentId) : null;
                $rawDays = array_key_exists('credit_days', $input) ? $input['credit_days'] : null;
                // En creación sin valor → heredar; en patch con segmento nuevo sin credit_days → heredar.
                if ($rawDays === null || $rawDays === '') {
                    $resolved = ClientRules::resolveCreditDays(null, $defaultDays);
                } else {
                    $resolved = ClientRules::resolveCreditDays($rawDays, $defaultDays);
                }
                if (isset($resolved['error'])) {
                    return Result::fail((string) $resolved['error'], 422);
                }
                $out['credit_days'] = (int) $resolved['credit_days'];
            }
        }

        if (!$partial || array_key_exists('issuer_id', $out)) {
            $issuerId = (int) ($out['issuer_id'] ?? 0);
            if ($issuerId <= 0 && !$partial) {
                return Result::fail('El emisor facturador es obligatorio.', 422);
            }
            if ($issuerId > 0 && !$this->issuerExists($issuerId)) {
                return Result::fail('El emisor indicado no existe.', 422);
            }
        }

        if (!$partial || array_key_exists('name', $out)) {
            $name = trim((string) ($out['name'] ?? ''));
            if ($name === '') {
                return Result::fail('El nombre comercial es obligatorio.', 422);
            }
            $out['name'] = mb_substr($name, 0, 150);
        }

        if (!$partial || array_key_exists('legal_name', $out)) {
            $legal = trim((string) ($out['legal_name'] ?? ''));
            if ($legal === '') {
                return Result::fail('La razón social es obligatoria.', 422);
            }
            $out['legal_name'] = mb_substr($legal, 0, 200);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input, bool $partial = false): array
    {
        $out = [];

        $stringFields = [
            'erp_client_id',
            'name',
            'legal_name',
            'rfc',
            'contact_name',
            'contact_email',
            'contact_phone',
        ];
        foreach ($stringFields as $field) {
            if (!array_key_exists($field, $input) && $partial) {
                continue;
            }
            if (!array_key_exists($field, $input) && !$partial) {
                if (in_array($field, ['erp_client_id', 'contact_name', 'contact_email', 'contact_phone'], true)) {
                    $out[$field] = null;
                }
                continue;
            }
            $value = trim((string) ($input[$field] ?? ''));
            if ($field === 'rfc') {
                $out[$field] = ClientRules::normalizeRfc($value);
            } elseif ($field === 'contact_email') {
                $out[$field] = $value === '' ? null : mb_strtolower($value);
            } else {
                $out[$field] = $value === '' ? null : $value;
            }
        }

        foreach (['segment_id', 'issuer_id', 'credit_days'] as $field) {
            if (!array_key_exists($field, $input) && $partial) {
                continue;
            }
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null || $input[$field] === '') {
                if ($field === 'credit_days') {
                    // Se resuelve después con herencia.
                    $out[$field] = null;
                }
                continue;
            }
            $out[$field] = (int) $input[$field];
        }

        foreach (['stamp_on_issue', 'exclude_suspension', 'active'] as $field) {
            if (!array_key_exists($field, $input) && $partial) {
                continue;
            }
            if (!array_key_exists($field, $input)) {
                if (!$partial) {
                    $out[$field] = $field === 'active';
                }
                continue;
            }
            $parsed = filter_var($input[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $out[$field] = $parsed ?? (bool) (int) $input[$field];
        }

        return $out;
    }

    private function assertUniqueRfc(string $rfc, ?int $exceptId = null): ?string
    {
        $q = Capsule::table('clients')->where('rfc', $rfc);
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }
        if ($q->exists()) {
            return 'Ya existe un cliente con ese RFC.';
        }

        return null;
    }

    private function segmentExists(int $segmentId): bool
    {
        return Capsule::table('segments')->where('id', $segmentId)->exists();
    }

    private function issuerExists(int $issuerId): bool
    {
        return Capsule::table('issuers')->where('id', $issuerId)->exists();
    }

    private function segmentDefaultCreditDays(int $segmentId): ?int
    {
        $value = Capsule::table('segments')->where('id', $segmentId)->value('default_credit_days');

        return $value !== null ? (int) $value : null;
    }

    private function contractsCount(int $clientId): int
    {
        return (int) Capsule::table('contracts')->where('client_id', $clientId)->count();
    }

    /**
     * Marca created_at/updated_at en UTC explícito (RNF-11 / §3.4).
     * Capsule con time_zone=+00:00; no depende de ON UPDATE ni de la sesión SYSTEM de Phinx.
     */
    private function touchUtcTimestamps(int $clientId, bool $alsoCreatedAt): void
    {
        if ($clientId < 1) {
            return;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $payload = ['updated_at' => $nowUtc];
        if ($alsoCreatedAt) {
            $payload['created_at'] = $nowUtc;
        }

        Capsule::table('clients')->where('id', $clientId)->update($payload);
    }

    private function isWithinSegmentScope(int $segmentId): bool
    {
        $locked = $this->authContext['segment_id'] ?? null;
        if ($locked === null) {
            return true;
        }

        return (int) $locked === $segmentId;
    }

    private function clientRaw(int $id): ?object
    {
        return Capsule::table('clients')->where('id', $id)->first([
            'id',
            'segment_id',
            'issuer_id',
            'erp_client_id',
            'name',
            'legal_name',
            'rfc',
            'contact_name',
            'contact_email',
            'contact_phone',
            'credit_days',
            'stamp_on_issue',
            'exclude_suspension',
            'active',
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isStatusOnly(array $input): bool
    {
        $keys = array_map(static fn (mixed $key): string => (string) $key, array_keys($input));

        return $keys !== [] && array_diff($keys, ['active']) === [];
    }

    private function auditClient(
        int $id,
        string $action,
        ?object $beforeRow,
        bool $deleted = false,
    ): void {
        $afterRow = $deleted ? null : $this->clientRaw($id);
        $before = $beforeRow !== null ? $this->clientAuditFromRow($beforeRow) : null;
        $after = $afterRow !== null ? $this->clientAuditFromRow($afterRow) : null;

        $label = $this->clientLabel($after ?? $before ?? []);
        $active = (bool) ($after['active'] ?? false);
        $description = match ($action) {
            'create' => "Se creó el cliente {$label}",
            'status_change' => $active
                ? "Se activó el cliente {$label}"
                : "Se desactivó el cliente {$label}",
            'delete' => "Se eliminó el cliente {$label}",
            default => "Se actualizó el cliente {$label}",
        };

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'clients',
            (string) $id,
            $action,
            $description,
            $before,
            $after,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function clientAuditFromRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'segment_id' => (int) $row->segment_id,
            'issuer_id' => (int) $row->issuer_id,
            'erp_client_id' => $row->erp_client_id !== null ? (string) $row->erp_client_id : null,
            'name' => (string) $row->name,
            'legal_name' => (string) $row->legal_name,
            'rfc' => (string) $row->rfc,
            'contact_name' => $row->contact_name !== null ? (string) $row->contact_name : null,
            'contact_email' => $row->contact_email !== null ? (string) $row->contact_email : null,
            'contact_phone' => $row->contact_phone !== null ? (string) $row->contact_phone : null,
            'credit_days' => (int) $row->credit_days,
            'stamp_on_issue' => (bool) (int) $row->stamp_on_issue,
            'exclude_suspension' => (bool) (int) $row->exclude_suspension,
            'active' => (bool) (int) $row->active,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function clientLabel(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        $rfc = trim((string) ($row['rfc'] ?? ''));
        if ($name !== '' && $rfc !== '') {
            return $name . ' (' . $rfc . ')';
        }

        return $name !== '' ? $name : ($rfc !== '' ? $rfc : 'sin nombre');
    }
}
