<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PromotionsModel;
use System\Support\AuditLogger;
use System\Support\PromotionCalculator;

final class PromotionsService extends ModelService
{
    private const MODULE_KEY = 'config_promotions';

    /** Lectura: admin de promos, vendedores o alta de contratos (cupones). */
    private const VIEW_ALIASES = ['config_promotions', 'sales_promotions', 'contracts'];

    private const PROMO_MODES = ['price_discount', 'extra_units'];

    protected function modelClass(): string
    {
        return PromotionsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('code', DataTypes::String, true)
            ->setField('name', DataTypes::String, true)
            ->setField('promo_mode', DataTypes::String, false, 'price_discount')
            ->setField('segment_id', DataTypes::Integer, false)
            ->setField('service_id', DataTypes::Integer, false)
            ->setField('min_quantity', DataTypes::Integer, false)
            ->setField('extra_units_pct', DataTypes::String, false)
            ->setField('discount_type', DataTypes::String, false, 'percentage')
            ->setField('discount_value', DataTypes::String, false, '0.00')
            ->setField('applies_to', DataTypes::String, false, 'recurring')
            ->setField('valid_from', DataTypes::Date, true)
            ->setField('valid_until', DataTypes::Date, false)
            ->setField('max_uses', DataTypes::Integer, false)
            ->setField('current_uses', DataTypes::Integer, false, 0)
            ->setField('is_active', DataTypes::Boolean, false, true)
            ->setField('visible_in_portal', DataTypes::Boolean, false, true);
    }

    public function handle(Context $context): Result
    {
        $method = $context->getMethod();
        $id = $context->getData('_resource_id');
        $auth = $context->getData('auth', []);
        $request = RbacAuthHelper::applySegmentFilterToQuery($auth, $context->getRequest());
        $uri = strtolower(trim($context->getUri(), '/'));
        $parts = $uri === '' ? [] : explode('/', $uri);
        $action = isset($parts[1]) && !ctype_digit((string) $parts[1])
            ? strtolower((string) $parts[1])
            : '';

        // GET /api/promotions/evaluate-consumable?client_id&service_id&quantity
        if ($action === 'evaluate-consumable' && $method === 'GET') {
            if (!RbacAuthHelper::canAny($auth, self::VIEW_ALIASES, 'view')) {
                return Result::fail('Sin permiso para evaluar promociones.', 403);
            }
            $clientId = (int) ($request['client_id'] ?? 0);
            $serviceId = (int) ($request['service_id'] ?? 0);
            $quantity = (int) ($request['quantity'] ?? 0);
            if ($clientId < 1 || $serviceId < 1 || $quantity < 1) {
                return Result::fail('client_id, service_id y quantity (> 0) son obligatorios.', 422);
            }
            $client = Capsule::table('clients')->where('id', $clientId)->first(['id', 'segment_id']);
            if ($client === null) {
                return Result::fail('Cliente no encontrado.', 404);
            }
            $segmentId = (int) $client->segment_id;
            $eval = PromotionCalculator::evaluateExtraUnitsPromo(
                $clientId,
                $segmentId,
                $serviceId,
                $quantity,
                isset($request['date']) ? (string) $request['date'] : null,
            );

            return Result::ok($eval, 'Evaluación de unidades extra');
        }

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
            $prepared = $this->normalizePromotionInput($request);
            if ($prepared instanceof Result) {
                return $prepared;
            }
            $scopes = $prepared['_scopes'];
            unset($prepared['_scopes']);

            $created = $this->create($prepared, $context);
            if ($created->isSuccess()) {
                $data = $created->getData();
                $newId = (int) (is_array($data) ? ($data['id'] ?? 0) : 0);
                if ($newId > 0) {
                    $this->replaceScopes($newId, $scopes);
                    Capsule::table('promotions')->where('id', $newId)->update([
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
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
            $prepared = $this->normalizePromotionInput($request);
            if ($prepared instanceof Result) {
                return $prepared;
            }
            $scopes = $prepared['_scopes'];
            unset($prepared['_scopes']);

            $updated = $this->update($promoId, $prepared, $context);
            if ($updated->isSuccess()) {
                $this->replaceScopes($promoId, $scopes);
                Capsule::table('promotions')->where('id', $promoId)->update([
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->auditPromotion($auth, $promoId, 'update', $before);
            }

            return $updated;
        }
        if ($method === 'PATCH') {
            if (!RbacAuthHelper::can($auth, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para editar promociones.', 403);
            }
            $payload = $this->normalizeMoney($request);
            if (array_key_exists('visible_in_portal', $payload)) {
                $payload['visible_in_portal'] = filter_var(
                    $payload['visible_in_portal'],
                    FILTER_VALIDATE_BOOLEAN,
                );
            }
            $before = $this->promotionSnapshot($promoId);
            $patched = $this->patch($promoId, $payload, $context);
            if ($patched->isSuccess()) {
                Capsule::table('promotions')->where('id', $promoId)->update([
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
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
                Capsule::table('promotions')->where('id', $promoId)->update([
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->auditPromotion($auth, $promoId, 'status_change', $before);
            }

            return $patched;
        }

        return Result::fail('Método no soportado.', 405);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|Result
     */
    private function normalizePromotionInput(array $input): array|Result
    {
        $mode = strtolower(trim((string) ($input['promo_mode'] ?? 'price_discount')));
        if (!in_array($mode, self::PROMO_MODES, true)) {
            return Result::fail('promo_mode debe ser price_discount o extra_units.', 422);
        }

        $input = $this->normalizeMoney($input);
        $input['promo_mode'] = $mode;
        $input['visible_in_portal'] = array_key_exists('visible_in_portal', $input)
            ? filter_var($input['visible_in_portal'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $scopes = $this->extractScopes($input);
        unset($input['scopes'], $input['segment_ids'], $input['client_ids']);

        if ($mode === 'extra_units') {
            $serviceId = (int) ($input['service_id'] ?? 0);
            if ($serviceId < 1) {
                return Result::fail('service_id es obligatorio para promociones de unidades extra.', 422);
            }
            if (!Capsule::table('services')->where('id', $serviceId)->exists()) {
                return Result::fail('Servicio consumible no encontrado.', 422);
            }
            $minQty = (int) ($input['min_quantity'] ?? 0);
            if ($minQty < 1) {
                return Result::fail('min_quantity debe ser ≥ 1.', 422);
            }
            $pctRaw = trim((string) ($input['extra_units_pct'] ?? ''));
            if ($pctRaw === '' || !is_numeric($pctRaw) || bccomp($pctRaw, '0', 2) <= 0) {
                return Result::fail('extra_units_pct debe ser mayor a 0.', 422);
            }
            $input['service_id'] = $serviceId;
            $input['min_quantity'] = $minQty;
            $input['extra_units_pct'] = number_format((float) $pctRaw, 2, '.', '');
            // Columnas de descuento: defaults compatibles (no afectan MRR).
            $input['discount_type'] = $input['discount_type'] ?? 'percentage';
            $input['discount_value'] = $this->normalizeMoney([
                'discount_value' => $input['discount_value'] ?? '0.00',
            ])['discount_value'];
            $input['applies_to'] = $input['applies_to'] ?? 'single';
        } else {
            // Cupón clásico: limpia campos de unidades extra.
            $input['service_id'] = null;
            $input['min_quantity'] = null;
            $input['extra_units_pct'] = null;
            if (empty($input['discount_type'])) {
                return Result::fail('discount_type es obligatorio.', 422);
            }
            if (!isset($input['discount_value'])) {
                return Result::fail('discount_value es obligatorio.', 422);
            }
        }

        $input['_scopes'] = $scopes;

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{segment_id:?int,client_id:?int}>
     */
    private function extractScopes(array $input): array
    {
        $scopes = [];
        if (isset($input['scopes']) && is_array($input['scopes'])) {
            foreach ($input['scopes'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $seg = isset($row['segment_id']) && $row['segment_id'] !== null && $row['segment_id'] !== ''
                    ? (int) $row['segment_id']
                    : null;
                $cli = isset($row['client_id']) && $row['client_id'] !== null && $row['client_id'] !== ''
                    ? (int) $row['client_id']
                    : null;
                if ($seg === null && $cli === null) {
                    continue;
                }
                if ($seg !== null && $seg < 1) {
                    continue;
                }
                if ($cli !== null && $cli < 1) {
                    continue;
                }
                $scopes[] = ['segment_id' => $seg, 'client_id' => $cli];
            }
        }
        if (isset($input['segment_ids']) && is_array($input['segment_ids'])) {
            foreach ($input['segment_ids'] as $sid) {
                $seg = (int) $sid;
                if ($seg > 0) {
                    $scopes[] = ['segment_id' => $seg, 'client_id' => null];
                }
            }
        }
        if (isset($input['client_ids']) && is_array($input['client_ids'])) {
            foreach ($input['client_ids'] as $cid) {
                $cli = (int) $cid;
                if ($cli > 0) {
                    $scopes[] = ['segment_id' => null, 'client_id' => $cli];
                }
            }
        }

        // Deduplicar.
        $unique = [];
        $out = [];
        foreach ($scopes as $scope) {
            $key = ($scope['segment_id'] ?? 'n') . ':' . ($scope['client_id'] ?? 'n');
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $out[] = $scope;
        }

        return $out;
    }

    /**
     * @param list<array{segment_id:?int,client_id:?int}> $scopes
     */
    private function replaceScopes(int $promotionId, array $scopes): void
    {
        Capsule::table('promotion_scopes')->where('promotion_id', $promotionId)->delete();
        if ($scopes === []) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $rows = [];
        foreach ($scopes as $scope) {
            $rows[] = [
                'promotion_id' => $promotionId,
                'segment_id' => $scope['segment_id'],
                'client_id' => $scope['client_id'],
                'created_at' => $now,
            ];
        }
        Capsule::table('promotion_scopes')->insert($rows);
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
            ->leftJoin('segments as seg', 'seg.id', '=', 'p.segment_id')
            ->leftJoin('services as svc', 'svc.id', '=', 'p.service_id');

        if (($auth['segment_id'] ?? null) !== null) {
            $segId = (int) $auth['segment_id'];
            $builder->where(static function ($q) use ($segId): void {
                $q->where('p.segment_id', $segId)
                    ->orWhereNull('p.segment_id')
                    ->orWhereExists(static function ($sub) use ($segId): void {
                        $sub->selectRaw('1')
                            ->from('promotion_scopes as ps')
                            ->whereColumn('ps.promotion_id', 'p.id')
                            ->where('ps.segment_id', $segId);
                    });
            });
        }

        $rows = $builder
            ->orderByRaw('COALESCE(p.updated_at, p.created_at) DESC')
            ->orderByDesc('p.id')
            ->get([
                'p.id',
                'p.code',
                'p.name',
                'p.promo_mode',
                'p.segment_id',
                'seg.name as segment_name',
                'p.service_id',
                'svc.name as service_name',
                'svc.code as service_code',
                'p.min_quantity',
                'p.extra_units_pct',
                'p.discount_type',
                'p.discount_value',
                'p.applies_to',
                'p.valid_from',
                'p.valid_until',
                'p.max_uses',
                'p.current_uses',
                'p.is_active',
                'p.visible_in_portal',
            ]);

        return $rows->map(function (object $row): array {
            $scopes = Capsule::table('promotion_scopes as ps')
                ->leftJoin('segments as s', 's.id', '=', 'ps.segment_id')
                ->leftJoin('clients as c', 'c.id', '=', 'ps.client_id')
                ->where('ps.promotion_id', (int) $row->id)
                ->get([
                    'ps.id',
                    'ps.segment_id',
                    's.name as segment_name',
                    'ps.client_id',
                    'c.name as client_name',
                ])
                ->map(static fn (object $s): array => [
                    'id' => (int) $s->id,
                    'segment_id' => $s->segment_id !== null ? (int) $s->segment_id : null,
                    'segment_name' => $s->segment_name !== null ? (string) $s->segment_name : null,
                    'client_id' => $s->client_id !== null ? (int) $s->client_id : null,
                    'client_name' => $s->client_name !== null ? (string) $s->client_name : null,
                ])
                ->all();

            $promoMode = (string) ($row->promo_mode ?? 'price_discount');

            return [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'promo_mode' => $promoMode,
                'segment_name' => $row->segment_name !== null ? (string) $row->segment_name : null,
                'segment_id' => $row->segment_id !== null ? (int) $row->segment_id : null,
                'service_id' => $row->service_id !== null ? (int) $row->service_id : null,
                'service_name' => $row->service_name !== null ? (string) $row->service_name : null,
                'service_code' => $row->service_code !== null ? (string) $row->service_code : null,
                'min_quantity' => $row->min_quantity !== null ? (int) $row->min_quantity : null,
                'extra_units_pct' => $row->extra_units_pct !== null
                    ? (string) $row->extra_units_pct
                    : null,
                'discount_type' => (string) $row->discount_type,
                'discount_value' => (string) $row->discount_value,
                'applies_to' => (string) $row->applies_to,
                'valid_from' => (string) $row->valid_from,
                'valid_until' => $row->valid_until !== null ? (string) $row->valid_until : null,
                'max_uses' => $row->max_uses !== null ? (int) $row->max_uses : null,
                'current_uses' => (int) $row->current_uses,
                'is_active' => (bool) $row->is_active,
                'visible_in_portal' => (bool) ($row->visible_in_portal ?? true),
                'scopes' => $scopes,
                'benefit_label' => $this->buildBenefitLabel($row),
            ];
        })->all();
    }

    private function buildBenefitLabel(object $row): string
    {
        $mode = (string) ($row->promo_mode ?? 'price_discount');
        if ($mode === 'extra_units') {
            $pct = rtrim(rtrim(number_format((float) ($row->extra_units_pct ?? 0), 2, '.', ''), '0'), '.');
            $min = number_format((int) ($row->min_quantity ?? 0), 0, '.', ',');

            return "+{$pct}% extra desde {$min} u.";
        }
        $type = (string) $row->discount_type;
        $val = (string) $row->discount_value;
        if ($type === 'percentage') {
            return rtrim(rtrim($val, '0'), '.') . '% descuento';
        }
        if ($type === 'free_months') {
            return (int) $val . ' mes(es) gratis';
        }

        return '$' . number_format((float) $val, 2, '.', ',') . ' fijo';
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
        $row = Capsule::table('promotions')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        $scopes = Capsule::table('promotion_scopes')
            ->where('promotion_id', $id)
            ->get(['segment_id', 'client_id'])
            ->map(static fn (object $s): array => [
                'segment_id' => $s->segment_id !== null ? (int) $s->segment_id : null,
                'client_id' => $s->client_id !== null ? (int) $s->client_id : null,
            ])
            ->all();

        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'promo_mode' => (string) ($row->promo_mode ?? 'price_discount'),
            'segment_id' => $row->segment_id !== null ? (int) $row->segment_id : null,
            'service_id' => $row->service_id !== null ? (int) $row->service_id : null,
            'min_quantity' => $row->min_quantity !== null ? (int) $row->min_quantity : null,
            'extra_units_pct' => $row->extra_units_pct !== null ? (string) $row->extra_units_pct : null,
            'discount_type' => (string) $row->discount_type,
            'discount_value' => (string) $row->discount_value,
            'applies_to' => (string) $row->applies_to,
            'valid_from' => $row->valid_from !== null ? (string) $row->valid_from : null,
            'valid_until' => $row->valid_until !== null ? (string) $row->valid_until : null,
            'max_uses' => $row->max_uses !== null ? (int) $row->max_uses : null,
            'current_uses' => (int) $row->current_uses,
            'is_active' => (bool) (int) $row->is_active,
            'visible_in_portal' => (bool) (int) ($row->visible_in_portal ?? 1),
            'scopes' => $scopes,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $before
     */
    private function isStatusToggle(array $input, ?array $before): bool
    {
        $ignore = ['segment', 'page', 'per_page', 'search', 'scopes', 'segment_ids', 'client_ids'];
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
