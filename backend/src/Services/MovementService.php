<?php

declare(strict_types=1);

namespace Operam\Services;

use Operam\Models\Movement;
use Operam\Support\Money;
use RuntimeException;

/**
 * Alta inmutable de movimientos MRR (RNF-3).
 * Calcula deltas con Money/brick-math (RNF-1).
 */
final class MovementService extends ModelService
{
    protected function modelClass(): string
    {
        return Movement::class;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Movement
    {
        if (isset($attributes['mrr_before'], $attributes['mrr_after']) && !isset($attributes['mrr_delta_mxn'])) {
            $before = Money::of((string) $attributes['mrr_before']);
            $after = Money::of((string) $attributes['mrr_after']);
            $attributes['mrr_delta_mxn'] = $after->minus($before)->toDecimalString();
        }

        if (!isset($attributes['created_at'])) {
            $attributes['created_at'] = gmdate('Y-m-d H:i:s');
        }

        /** @var Movement $model */
        $model = parent::create($attributes);

        return $model;
    }

    public function update(\Illuminate\Database\Eloquent\Model $model, array $attributes): Movement
    {
        throw new RuntimeException('La bitácora de movimientos es inmutable (RNF-3): no se permite update.');
    }

    public function delete(\Illuminate\Database\Eloquent\Model $model): bool
    {
        throw new RuntimeException('La bitácora de movimientos es inmutable (RNF-3): no se permite delete.');
    }
}
