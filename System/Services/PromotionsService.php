<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\DataTypes;
use System\Core\Result;
use System\Models\PromotionsModel;

final class PromotionsService extends ModelService
{
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
            return Result::ok($this->listEnriched($auth), 'Promociones');
        }
        if ($method === 'POST' && $id === null) {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }
            $input = $this->normalizeMoney($request);

            return $this->create($input, $context);
        }
        if ($id === null) {
            return Result::fail('Se requiere id de promoción.', 400);
        }

        $promoId = (int) $id;
        if ($method === 'PUT') {
            $denied = RbacAuthHelper::assertSegmentWriteAllowed($auth, $request);
            if ($denied !== null) {
                return Result::fail($denied, 403);
            }
            if (($auth['segment_id'] ?? null) !== null) {
                $request['segment_id'] = (int) $auth['segment_id'];
            }

            return $this->update($promoId, $this->normalizeMoney($request), $context);
        }
        if ($method === 'PATCH') {
            return $this->patch($promoId, $this->normalizeMoney($request), $context);
        }
        if ($method === 'DELETE') {
            return $this->patch($promoId, ['is_active' => false], $context);
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
            ->orderBy('p.id')
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
}
