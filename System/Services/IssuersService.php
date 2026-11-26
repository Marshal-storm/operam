<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\IssuersModel;
use System\Support\AuditLogger;

/**
 * CRUD de emisores (RF-1) — endpoint `/api/issuers` (alias `/api/emisores`, `/api/emitters`).
 * Refs sensibles se enmascaran si el JWT no tiene `edit` en `config_issuers`.
 */
final class IssuersService extends ModelService
{
    private const MODULE_KEY = 'config_issuers';

    /** Selects de emisor en contratos/clientes/instancias. */
    private const VIEW_ALIASES = [
        'config_issuers',
        'contracts',
        'clients',
        'config_instances',
        'config_catalog',
    ];

    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    private const MASK = '••••••••';

    private const SENSITIVE_FIELDS = [
        'secret_ref',
        'integropay_merchant_token_ref',
        'integropay_device_token_ref',
        'integropay_webhook_token_ref',
    ];

    /** Datos SPEI públicos para el cliente (texto libre, no son secretos). */
    private const SPEI_FIELDS = [
        'spei_bank_name',
        'spei_clabe',
        'spei_account_holder',
        'spei_reference_hint',
    ];

    /** Columnas leídas por listados y detalle (sin alias de tabla). */
    private const COLUMNS = [
        'id',
        'name',
        'rfc',
        'api_url',
        'secret_ref',
        'integropay_merchant_token_ref',
        'integropay_device_token_ref',
        'integropay_webhook_token_ref',
        'spei_bank_name',
        'spei_clabe',
        'spei_account_holder',
        'spei_reference_hint',
        'is_active',
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
            ->setField('name', DataTypes::String, true)
            ->setField('rfc', DataTypes::String, true)
            ->setField('api_url', DataTypes::String, false)
            ->setField('secret_ref', DataTypes::String, false, null, null, true)
            ->setField('integropay_merchant_token_ref', DataTypes::String, false, null, null, true)
            ->setField('integropay_device_token_ref', DataTypes::String, false, null, null, true)
            ->setField('integropay_webhook_token_ref', DataTypes::String, false, null, null, true)
            ->setField('spei_bank_name', DataTypes::String, false)
            ->setField('spei_clabe', DataTypes::String, false)
            ->setField('spei_account_holder', DataTypes::String, false)
            ->setField('spei_reference_hint', DataTypes::String, false)
            ->setField('is_active', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $request = $context->getRequest();

        if ($method === 'GET' && $id === null) {
            if (!RbacAuthHelper::canAny($this->authContext, self::VIEW_ALIASES, 'view')) {
                return Result::fail('Sin permiso para consultar emisores.', 403);
            }
            // Con filtros/paginación → respuesta enriquecida; sin ellos → lista plana (selects).
            if ($this->wantsPaginated($request)) {
                if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                    return Result::fail('Sin permiso para administrar emisores.', 403);
                }

                return Result::ok($this->listPaginated($request), 'Emisores');
            }

            return Result::ok($this->listForSelects(), 'Emisores');
        }

        if ($method === 'GET' && $id !== null) {
            if (!RbacAuthHelper::canAny($this->authContext, self::VIEW_ALIASES, 'view')) {
                return Result::fail('Sin permiso para consultar emisores.', 403);
            }
            $row = $this->findEnriched((int) $id);
            if ($row === null) {
                return Result::fail('Emisor no encontrado.', 404);
            }

            return Result::ok($row, 'Emisor');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                return Result::fail('Sin permiso para crear emisores.', 403);
            }
            $normalized = $this->normalizeInput($request);
            $rfcError = $this->validateRfc($normalized['rfc'] ?? null) ?? $this->validateSpei($normalized);
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
            if ($newId > 0) {
                $this->auditIssuer($newId, 'create', null);
            }

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
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar emisores.', 403);
            }
            $normalized = $this->normalizeInput($request);
            $rfcError = $this->validateRfc($normalized['rfc'] ?? null) ?? $this->validateSpei($normalized);
            if ($rfcError !== null) {
                return Result::fail($rfcError, 422);
            }
            $dup = $this->assertUniqueRfc((string) $normalized['rfc'], $issuerId);
            if ($dup !== null) {
                return Result::fail($dup, 422);
            }

            $before = $this->issuerRaw($issuerId);
            $updated = $this->update($issuerId, $normalized, $context);
            if (!$updated->isSuccess()) {
                return $updated;
            }
            $this->auditIssuer($issuerId, 'update', $before);

            return Result::ok($this->findEnriched($issuerId) ?? $updated->getData(), 'Emisor actualizado');
        }

        if ($method === 'PATCH') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar emisores.', 403);
            }
            $normalized = $this->normalizeInput($request, true);
            $speiError = $this->validateSpei($normalized);
            if ($speiError !== null) {
                return Result::fail($speiError, 422);
            }
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

            $before = $this->issuerRaw($issuerId);
            $patched = $this->patch($issuerId, $normalized, $context);
            if (!$patched->isSuccess()) {
                return $patched;
            }
            $action = $this->isStatusOnly($normalized) ? 'status_change' : 'update';
            $this->auditIssuer($issuerId, $action, $before);

            return Result::ok($this->findEnriched($issuerId) ?? $patched->getData(), 'Emisor actualizado');
        }

        if ($method === 'DELETE') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'delete')) {
                return Result::fail('Sin permiso para desactivar emisores.', 403);
            }
            $before = $this->issuerRaw($issuerId);
            $patched = $this->patch($issuerId, ['is_active' => false], $context);
            if (!$patched->isSuccess()) {
                return $patched;
            }
            $this->auditIssuer($issuerId, 'status_change', $before);

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
        $rows = Capsule::table('issuers')
            ->orderByRaw('COALESCE(updated_at, created_at) DESC')
            ->orderByDesc('id')
            ->get(self::COLUMNS);

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
            || (isset($query['status']) && trim((string) $query['status']) !== '');
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
        $counts = Capsule::table('instances')
            ->selectRaw('issuer_id, COUNT(*) as linked_instances_count')
            ->groupBy('issuer_id');

        $builder = Capsule::table('issuers as e')
            ->leftJoinSub($counts, 'ic', 'ic.issuer_id', '=', 'e.id');

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('e.name', 'like', $like)
                    ->orWhere('e.rfc', 'like', $like);
            });
        }

        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if ($status === 'active') {
            $builder->where('e.is_active', 1);
        } elseif ($status === 'inactive') {
            $builder->where('e.is_active', 0);
        }

        $total = (int) (clone $builder)->count('e.id');

        $rows = $builder
            ->orderByRaw('COALESCE(e.updated_at, e.created_at) DESC')
            ->orderByDesc('e.id')
            ->forPage($page, $perPage)
            ->get([
                ...array_map(static fn (string $column): string => 'e.' . $column, self::COLUMNS),
                'ic.linked_instances_count',
            ]);

        $items = $rows->map(function (object $row): array {
            $mapped = $this->mapRow($row);
            $mapped['linked_instances_count'] = (int) ($row->linked_instances_count ?? 0);

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
        $row = Capsule::table('issuers as e')->where('e.id', $id)->first(
            array_map(static fn (string $column): string => 'e.' . $column, self::COLUMNS),
        );

        if ($row === null) {
            return null;
        }

        $mapped = $this->mapRow($row);
        $mapped['linked_instances_count'] = (int) Capsule::table('instances')
            ->where('issuer_id', $id)
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

        if (array_key_exists('name', $out)) {
            $out['name'] = trim((string) $out['name']);
        }

        foreach (['api_url', ...self::SPEI_FIELDS, ...self::SENSITIVE_FIELDS] as $field) {
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

        if (array_key_exists('is_active', $out)) {
            $raw = $out['is_active'];
            $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $out['is_active'] = $parsed ?? (bool) (int) $raw;
        } elseif (!$partial) {
            $out['is_active'] = true;
        }

        return $out;
    }

    /**
     * La CLABE interbancaria (SPEI) son exactamente 18 dígitos; vacía = sin configurar.
     *
     * @param array<string, mixed> $normalized
     */
    private function validateSpei(array $normalized): ?string
    {
        $clabe = $normalized['spei_clabe'] ?? null;
        if ($clabe === null || $clabe === '') {
            return null;
        }
        if (!preg_match('/^\d{18}$/', (string) $clabe)) {
            return 'La CLABE debe tener exactamente 18 dígitos numéricos.';
        }

        return null;
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
        $q = Capsule::table('issuers')->where('rfc', $rfc);
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
        $webhook = $this->hasReference($row->integropay_webhook_token_ref ?? null)
            ? (string) $row->integropay_webhook_token_ref
            : null;

        $canEdit = RbacAuthHelper::can($this->authContext, 'config_issuers', 'edit');

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
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
            'has_webhook_token' => $webhook !== null,
            'integropay_webhook_token_ref' => $canEdit
                ? $webhook
                : ($webhook !== null ? self::MASK : null),
            'spei_bank_name' => $this->nullableText($row, 'spei_bank_name'),
            'spei_clabe' => $this->nullableText($row, 'spei_clabe'),
            'spei_account_holder' => $this->nullableText($row, 'spei_account_holder'),
            'spei_reference_hint' => $this->nullableText($row, 'spei_reference_hint'),
            'is_active' => (bool) (int) $row->is_active,
        ];
    }

    /**
     * Bitácora tras escritura exitosa. Las refs no viajan en claro: solo presencia
     * y una bandera de rotación cuyo nombre no dispara el redactado de AuditLogger.
     */
    private function auditIssuer(int $id, string $action, ?object $beforeRow): void
    {
        $afterRow = $this->issuerRaw($id);
        $before = $beforeRow !== null ? $this->issuerAuditFromRow($beforeRow) : null;
        $after = $afterRow !== null ? $this->issuerAuditFromRow($afterRow) : null;
        if ($before !== null && $after !== null && $beforeRow !== null && $afterRow !== null) {
            $this->markReferenceRotations($beforeRow, $afterRow, $after);
        }

        $label = $this->issuerLabel($after ?? $before ?? []);
        $active = (bool) ($after['is_active'] ?? false);
        $description = match ($action) {
            'create' => "Se creó el emisor {$label}",
            'status_change' => $active
                ? "Se activó el emisor {$label}"
                : "Se desactivó el emisor {$label}",
            default => "Se actualizó el emisor {$label}",
        };

        AuditLogger::log(
            $this->authContext,
            'config_issuers',
            'issuer',
            (string) $id,
            $action,
            $description,
            $before,
            $after,
        );
    }

    private function issuerRaw(int $id): ?object
    {
        return Capsule::table('issuers')->where('id', $id)->first(self::COLUMNS);
    }

    /**
     * @return array<string, mixed>
     */
    private function issuerAuditFromRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'rfc' => (string) $row->rfc,
            'api_url' => $row->api_url !== null && (string) $row->api_url !== ''
                ? (string) $row->api_url
                : null,
            'has_erp_reference' => $this->hasReference($row->secret_ref ?? null),
            'has_merchant_reference' => $this->hasReference($row->integropay_merchant_token_ref ?? null),
            'has_device_reference' => $this->hasReference($row->integropay_device_token_ref ?? null),
            'has_webhook_reference' => $this->hasReference($row->integropay_webhook_token_ref ?? null),
            'spei_configured' => $this->nullableText($row, 'spei_clabe') !== null,
            'is_active' => (bool) (int) $row->is_active,
        ];
    }

    /**
     * Marca rotación solo cuando ambos valores existen y cambian (el alta/baja ya va en has_*).
     *
     * @param array<string, mixed> $after
     */
    private function markReferenceRotations(object $beforeRow, object $afterRow, array &$after): void
    {
        $pairs = [
            'secret_ref' => 'erp_reference_rotated',
            'integropay_merchant_token_ref' => 'merchant_reference_rotated',
            'integropay_device_token_ref' => 'device_reference_rotated',
            'integropay_webhook_token_ref' => 'webhook_reference_rotated',
        ];
        foreach ($pairs as $column => $flag) {
            $previous = trim((string) ($beforeRow->{$column} ?? ''));
            $current = trim((string) ($afterRow->{$column} ?? ''));
            if ($previous !== '' && $current !== '' && $previous !== $current) {
                $after[$flag] = true;
            }
        }
    }

    private function nullableText(object $row, string $field): ?string
    {
        $value = trim((string) ($row->{$field} ?? ''));

        return $value !== '' ? $value : null;
    }

    private function hasReference(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function issuerLabel(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        $rfc = trim((string) ($row['rfc'] ?? ''));
        if ($name !== '' && $rfc !== '') {
            return $name . ' (' . $rfc . ')';
        }

        return $name !== '' ? $name : ($rfc !== '' ? $rfc : 'sin nombre');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isStatusOnly(array $input): bool
    {
        $keys = array_map(static fn (mixed $key): string => (string) $key, array_keys($input));

        return $keys !== [] && array_diff($keys, ['is_active']) === [];
    }
}
