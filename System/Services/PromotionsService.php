<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PromotionsModel;
use System\Support\AuditLogger;

final class PromotionsService extends ModelService
{
    private const MODULE_KEY = 'config_promotions';

    /** Lectura: admin de promos, vendedores o alta de contratos (cupones). */
    private const VIEW_ALIASES = ['config_promotions', 'sales_promotions', 'contracts'];

    protected function modelClass(): string
    {
        return PromotionsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('code', DataTypes::String, true)
            ->setField('name', DataTypes::String, true)
            ->setField('segment_id', DataTypes::Integer, false)
            ->setField('discount_type', DataTypes::String, true)
            ->setField('discount_value', DataTypes::String, true)
            ->setField('applies_to', DataTypes::String, false, 'recurring')
            ->setField('valid_from', DataTypes::Date, true)
            ->setField('valid_until', DataTypes::Date, false)
            ->setField('max_uses', DataTypes::Integer, false)
            ->setField('current_uses', DataTypes::Integer, false, 0)
            ->setField('is_active', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $auth = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery($auth, $context->getRequest());

        if ($method === 'GET' && $id === null) {
            if (!RbacAuthHelper::canAny($auth, self::VIEW_ALIASES, 'view')) {
                return Result::fail('Sin permiso para consultar promociones.', 403);
            }

            return Result::ok($this->listEnriched($auth), 'Promociones');
        }
        if ($method === 'POST' && $id === null) {
            if (!RbacAuthHelper::can($auth, self::MODULE_KEY, 'create')) {
                return Result::fail('Sin permiso para crear promociones.', 403);
            }
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }
            $input = $this->normalizeMoney($request);

            $created = $this->create($input, $context);
            if ($created->isSuccess()) {
                $data = $created->getData();
                $newId = (int) (is_array($data) ? ($data['id'] ?? 0) : 0);
                if ($newId > 0) {
                    $this->auditPromotion($auth, $newId, 'create', null);
                }
            }

            return $created;
        }
        if ($id === null) {
            return Result::fail('Se requiere id de promoción.', 400);
        }

        $promoId = (int) $id;
        if ($method === 'PUT') {
            if (!RbacAuthHelper::can($auth, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar promociones.', 403);
            }
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }

            $before = $this->promotionSnapshot($promoId);
            $updated = $this->update($promoId, $this->normalizeMoney($request), $context);
            if ($updated->isSuccess()) {
                $this->auditPromotion($auth, $promoId, 'update', $before);
            }

            return $updated;
        }
        if ($method === 'PATCH') {
            if (!RbacAuthHelper::can($auth, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar promociones.', 403);
            }
            $payload = $this->normalizeMoney($request);
            $before = $this->promotionSnapshot($promoId);
            $patched = $this->patch($promoId, $payload, $context);
            if ($patched->isSuccess()) {
                $action = $this->isStatusToggle($payload, $before) ? 'status_change' : 'update';
                $this->auditPromotion($auth, $promoId, $action, $before);
            }

            return $patched;
        }
        if ($method === 'DELETE') {
            if (!RbacAuthHelper::can($auth, self::MODULE_KEY, 'delete')) {
                return Result::fail('Sin permiso para desactivar promociones.', 403);
            }
            $before = $this->promotionSnapshot($promoId);
            $patched = $this->patch($promoId, ['is_active' => false], $context);
            if ($patched->isSuccess()) {
                $this->auditPromotion($auth, $promoId, 'status_change', $before);
            }

            return $patched;
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeMoney(array $input): array
    {
        if (isset($input['discount_value'])) {
            $raw = trim((string) $input['discount_value']);
            if (preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
                if (!str_contains($raw, '.')) {
                    $raw .= '.00';
                } elseif (preg_match('/^\d+\.\d$/', $raw)) {
                    $raw .= '0';
                }
                $input['discount_value'] = $raw;
            }
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $auth
     * @return list<array<string, mixed>>
     */
    private function listEnriched(array $auth = []): array
    {
        $builder = Capsule::table('promotions as p')
            ->leftJoin('segments as seg', 'seg.id', '=', 'p.segment_id');

        if (($auth['segment_id'] ?? null) !== null) {
            $builder->where(static function ($q) use ($auth): void {
                $q->where('p.segment_id', (int) $auth['segment_id'])
                    ->orWhereNull('p.segment_id');
            });
        }

        $rows = $builder
            ->orderByRaw('COALESCE(p.updated_at, p.created_at) DESC')
            ->orderByDesc('p.id')
            ->get([
                'p.id',
                'p.code',
                'p.name',
                'p.segment_id',
                'seg.name as segment_name',
                'p.discount_type',
                'p.discount_value',
                'p.applies_to',
                'p.valid_from',
                'p.valid_until',
                'p.max_uses',
                'p.current_uses',
                'p.is_active',
            ]);

        return $rows->map(static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'segment_name' => $row->segment_name !== null ? (string) $row->segment_name : null,
                'segment_id' => $row->segment_id !== null ? (int) $row->segment_id : null,
                'discount_type' => (string) $row->discount_type,
                'discount_value' => (string) $row->discount_value,
                'applies_to' => (string) $row->applies_to,
                'valid_from' => (string) $row->valid_from,
                'valid_until' => $row->valid_until !== null ? (string) $row->valid_until : null,
                'max_uses' => $row->max_uses !== null ? (int) $row->max_uses : null,
                'current_uses' => (int) $row->current_uses,
            'is_active' => (bool) $row->is_active,
        ];
        })->all();
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, mixed>|null $before
     */
    private function auditPromotion(array $auth, int $id, string $action, ?array $before): void
    {
        $after = $this->promotionSnapshot($id);
        $name = (string) ($after['name'] ?? $before['name'] ?? 'promoción');
        $code = trim((string) ($after['code'] ?? $before['code'] ?? ''));
        $label = $code !== '' ? $name . ' (' . $code . ')' : $name;
        $active = (bool) ($after['is_active'] ?? false);
        $description = match ($action) {
            'create' => "Se creó la promoción {$label}",
            'status_change' => $active
                ? "Se activó la promoción {$label}"
                : "Se desactivó la promoción {$label}",
            default => "Se actualizó la promoción {$label}",
        };

        AuditLogger::log(
            $auth,
            'config_promotions',
            'promotion',
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
    private function promotionSnapshot(int $id): ?array
    {
        $row = Capsule::table('promotions')->where('id', $id)->first([
            'id',
            'code',
            'name',
            'segment_id',
            'discount_type',
            'discount_value',
            'applies_to',
            'valid_from',
            'valid_until',
            'max_uses',
            'current_uses',
            'is_active',
        ]);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'segment_id' => $row->segment_id !== null ? (int) $row->segment_id : null,
            'discount_type' => (string) $row->discount_type,
            'discount_value' => (string) $row->discount_value,
            'applies_to' => (string) $row->applies_to,
            'valid_from' => $row->valid_from !== null ? (string) $row->valid_from : null,
            'valid_until' => $row->valid_until !== null ? (string) $row->valid_until : null,
            'max_uses' => $row->max_uses !== null ? (int) $row->max_uses : null,
            'current_uses' => (int) $row->current_uses,
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
