<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\BenefitRulesModel;
use System\Support\AuditLogger;
use System\Support\BenefitRulesEngine;

/**
 * CRUD de reglas de beneficio — `/api/benefit-rules` (RF-2.6).
 * RBAC vía `config_catalog`.
 */
final class BenefitRulesService extends ModelService
{
    private const MODULE_KEY = 'config_catalog';

    private const VIEW_ALIASES = ['config_catalog', 'contracts'];

    private const RESET_PERIODS = ['monthly', 'anniversary'];

    /** @var array<string, mixed> */
    private array $authContext = [];

    protected function modelClass(): string
    {
        return BenefitRulesModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('source_service_id', DataTypes::Integer, true)
            ->setField('benefit_service_id', DataTypes::Integer, true)
            ->setField('units_per_unit', DataTypes::Integer, true)
            ->setField('reset_period', DataTypes::String, false, 'monthly')
            ->setField('is_cumulative', DataTypes::Boolean, false, false)
            ->setField('active', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $request = $context->getRequest();
        $uri = strtolower(trim($context->getUri(), '/'));
        $parts = $uri === '' ? [] : explode('/', $uri);
        $action = isset($parts[1]) && !ctype_digit((string) $parts[1])
            ? strtolower((string) $parts[1])
            : '';

        // Vista previa sin persistir (alta de contrato).
        if ($action === 'preview' && $method === 'POST') {
            if (!RbacAuthHelper::canAny($this->authContext, self::VIEW_ALIASES, 'view')) {
                return Result::fail('Sin permiso para previsualizar beneficios.', 403);
            }
            $lines = is_array($request['lines'] ?? null) ? $request['lines'] : [];
            $normalized = [];
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $sid = (int) ($line['service_id'] ?? 0);
                if ($sid < 1) {
                    continue;
                }
                $pkg = (int) ($line['package_factor'] ?? 0);
                if ($pkg < 1) {
                    $svc = Capsule::table('services')->where('id', $sid)->value('package_factor');
                    $pkg = (int) ($svc ?? 0);
                }
                $normalized[] = [
                    'service_id' => $sid,
                    'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
                    'package_factor' => $pkg,
                ];
            }
            $grants = BenefitRulesEngine::previewForLines($normalized);

            return Result::ok([
                'grants' => $grants,
                'message' => BenefitRulesEngine::previewMessage($grants),
            ], 'Vista previa de beneficios');
        }

        if ($method === 'GET' && $id === null) {
            if (!RbacAuthHelper::canAny($this->authContext, self::VIEW_ALIASES, 'view')) {
                return Result::fail('Sin permiso para consultar reglas de beneficio.', 403);
            }

            return Result::ok($this->listEnriched($request), 'Reglas de beneficio');
        }

        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                return Result::fail('Sin permiso para crear reglas de beneficio.', 403);
            }
            $prepared = $this->preparePayload($request, false);
            if ($prepared instanceof Result) {
                return $prepared;
            }
            $created = $this->create($prepared, $context);
            if ($created->isSuccess()) {
                $newId = (int) (($created->getData()['id'] ?? 0));
                if ($newId > 0) {
                    $this->touchUpdatedAt($newId);
                    $this->auditRule($newId, 'create', null);
                }
            }

            return $created;
        }

        if ($id === null) {
            return Result::fail('Se requiere id de regla de beneficio.', 400);
        }

        $ruleId = (int) $id;
        if ($method === 'PUT' || $method === 'PATCH') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar reglas de beneficio.', 403);
            }
            $before = $this->ruleSnapshot($ruleId);
            if ($before === null) {
                return Result::fail('Regla de beneficio no encontrada.', 404);
            }
            $prepared = $this->preparePayload($request, $method === 'PATCH');
            if ($prepared instanceof Result) {
                return $prepared;
            }
            $result = $method === 'PUT'
                ? $this->update($ruleId, $prepared, $context)
                : $this->patch($ruleId, $prepared, $context);
            if ($result->isSuccess()) {
                $this->touchUpdatedAt($ruleId);
                $actionName = $this->isStatusToggle($prepared, $before) ? 'status_change' : 'update';
                $this->auditRule($ruleId, $actionName, $before);
            }

            return $result;
        }

        if ($method === 'DELETE') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'delete')) {
                return Result::fail('Sin permiso para desactivar reglas de beneficio.', 403);
            }
            $before = $this->ruleSnapshot($ruleId);
            $patched = $this->patch($ruleId, ['active' => false], $context);
            if ($patched->isSuccess()) {
                $this->touchUpdatedAt($ruleId);
                $this->auditRule($ruleId, 'status_change', $before);
            }

            return $patched;
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|Result
     */
    private function preparePayload(array $input, bool $partial): array|Result
    {
        $out = [];
        if (!$partial || array_key_exists('source_service_id', $input)) {
            $src = (int) ($input['source_service_id'] ?? 0);
            if ($src < 1) {
                return Result::fail('source_service_id es requerido.', 422);
            }
            if (!Capsule::table('services')->where('id', $src)->exists()) {
                return Result::fail('Servicio origen no encontrado.', 422);
            }
            $out['source_service_id'] = $src;
        }
        if (!$partial || array_key_exists('benefit_service_id', $input)) {
            $ben = (int) ($input['benefit_service_id'] ?? 0);
            if ($ben < 1) {
                return Result::fail('benefit_service_id es requerido.', 422);
            }
            if (!Capsule::table('services')->where('id', $ben)->exists()) {
                return Result::fail('Servicio de beneficio no encontrado.', 422);
            }
            $out['benefit_service_id'] = $ben;
        }
        if (isset($out['source_service_id'], $out['benefit_service_id'])
            && $out['source_service_id'] === $out['benefit_service_id']
        ) {
            return Result::fail('El servicio origen y el beneficio deben ser distintos.', 422);
        }
        if (!$partial || array_key_exists('units_per_unit', $input)) {
            $upu = (int) ($input['units_per_unit'] ?? 0);
            if ($upu < 1) {
                return Result::fail('units_per_unit debe ser ≥ 1.', 422);
            }
            $out['units_per_unit'] = $upu;
        }
        if (!$partial || array_key_exists('reset_period', $input)) {
            $rp = strtolower(trim((string) ($input['reset_period'] ?? 'monthly')));
            if (!in_array($rp, self::RESET_PERIODS, true)) {
                return Result::fail('reset_period debe ser monthly o anniversary.', 422);
            }
            $out['reset_period'] = $rp;
        }
        if (array_key_exists('is_cumulative', $input)) {
            $out['is_cumulative'] = filter_var($input['is_cumulative'], FILTER_VALIDATE_BOOLEAN);
        } elseif (!$partial) {
            $out['is_cumulative'] = false;
        }
        if (array_key_exists('active', $input)) {
            $out['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN);
        } elseif (!$partial) {
            $out['active'] = true;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function listEnriched(array $query): array
    {
        $builder = Capsule::table('benefit_rules as br')
            ->leftJoin('services as src', 'src.id', '=', 'br.source_service_id')
            ->leftJoin('services as ben', 'ben.id', '=', 'br.benefit_service_id');

        if (isset($query['active']) && $query['active'] !== '' && $query['active'] !== 'all') {
            $builder->where('br.active', filter_var($query['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        $rows = $builder
            ->orderByRaw('COALESCE(br.updated_at, br.created_at) DESC')
            ->orderByDesc('br.id')
            ->get([
                'br.id',
                'br.source_service_id',
                'src.code as source_service_code',
                'src.name as source_service_name',
                'br.benefit_service_id',
                'ben.code as benefit_service_code',
                'ben.name as benefit_service_name',
                'br.units_per_unit',
                'br.reset_period',
                'br.is_cumulative',
                'br.active',
                'br.created_at',
                'br.updated_at',
            ]);

        return $rows->map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'source_service_id' => (int) $row->source_service_id,
                'source_service_code' => (string) ($row->source_service_code ?? ''),
                'source_service_name' => (string) ($row->source_service_name ?? ''),
                'benefit_service_id' => (int) $row->benefit_service_id,
                'benefit_service_code' => (string) ($row->benefit_service_code ?? ''),
                'benefit_service_name' => (string) ($row->benefit_service_name ?? ''),
                'units_per_unit' => (int) $row->units_per_unit,
                'reset_period' => (string) $row->reset_period,
                'is_cumulative' => (bool) $row->is_cumulative,
                'active' => (bool) $row->active,
                'created_at' => (string) $row->created_at,
                'updated_at' => (string) $row->updated_at,
            ];
        })->all();
    }

    private function touchUpdatedAt(int $id): void
    {
        Capsule::table('benefit_rules')->where('id', $id)->update([
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ruleSnapshot(int $id): ?array
    {
        $rows = $this->listEnriched(['active' => 'all']);
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        $row = Capsule::table('benefit_rules')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'source_service_id' => (int) $row->source_service_id,
            'benefit_service_id' => (int) $row->benefit_service_id,
            'units_per_unit' => (int) $row->units_per_unit,
            'reset_period' => (string) $row->reset_period,
            'is_cumulative' => (bool) $row->is_cumulative,
            'active' => (bool) $row->active,
        ];
    }

    /**
     * @param array<string, mixed>|null $before
     */
    private function auditRule(int $id, string $action, ?array $before): void
    {
        $after = $this->ruleSnapshot($id);
        $label = sprintf(
            'regla #%d (%s → %s)',
            $id,
            (string) ($after['source_service_name'] ?? $before['source_service_name'] ?? $id),
            (string) ($after['benefit_service_name'] ?? $before['benefit_service_name'] ?? ''),
        );
        $active = (bool) ($after['active'] ?? false);
        $description = match ($action) {
            'create' => "Se creó la {$label}",
            'status_change' => $active
                ? "Se activó la {$label}"
                : "Se desactivó la {$label}",
            default => "Se actualizó la {$label}",
        };

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'benefit_rule',
            (string) $id,
            $action,
            $description,
            $before,
            $after,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $before
     */
    private function isStatusToggle(array $input, ?array $before): bool
    {
        $keys = array_keys($input);
        if ($keys === []) {
            return false;
        }
        foreach ($keys as $name) {
            if (!in_array((string) $name, ['active', 'is_active', 'status'], true)) {
                return false;
            }
        }

        return true;
    }
}
