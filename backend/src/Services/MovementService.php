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
        if (isset($attributes['mrr_antes'], $attributes['mrr_despues']) && !isset($attributes['mrr_delta_mxn'])) {
            $antes = Money::of((string) $attributes['mrr_antes']);
            $despues = Money::of((string) $attributes['mrr_despues']);
            $attributes['mrr_delta_mxn'] = $despues->minus($antes)->toDecimalString();
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
        throw new RuntimeException('movimiento es inmutable (RNF-3): no se permite update.');
    }

    public function delete(\Illuminate\Database\Eloquent\Model $model): bool
    {
        throw new RuntimeException('movimiento es inmutable (RNF-3): no se permite delete.');
    }
}
