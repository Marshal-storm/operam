<?php

declare(strict_types=1);

namespace Operam\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * Servicio base tipado (PHP 8.3) con hooks before_*, after_* y error_*.
 * Sustituye patrones legacy sin tipado de Illuminate 8.
 *
 * @template TModel of Model
 */
abstract class ModelService
{
    /** @var array<string, list<Closure>> */
    private array $hooks = [
        'before_create' => [],
        'after_create' => [],
        'before_update' => [],
        'after_update' => [],
        'before_delete' => [],
        'after_delete' => [],
        'error_create' => [],
        'error_update' => [],
        'error_delete' => [],
    ];

    /** @return class-string<TModel> */
    abstract protected function modelClass(): string;

    public function beforeCreate(Closure $callback): void
    {
        $this->hooks['before_create'][] = $callback;
    }

    public function afterCreate(Closure $callback): void
    {
        $this->hooks['after_create'][] = $callback;
    }

    public function beforeUpdate(Closure $callback): void
    {
        $this->hooks['before_update'][] = $callback;
    }

    public function afterUpdate(Closure $callback): void
    {
        $this->hooks['after_update'][] = $callback;
    }

    public function beforeDelete(Closure $callback): void
    {
        $this->hooks['before_delete'][] = $callback;
    }

    public function afterDelete(Closure $callback): void
    {
        $this->hooks['after_delete'][] = $callback;
    }

    public function onErrorCreate(Closure $callback): void
    {
        $this->hooks['error_create'][] = $callback;
    }

    public function onErrorUpdate(Closure $callback): void
    {
        $this->hooks['error_update'][] = $callback;
    }

    public function onErrorDelete(Closure $callback): void
    {
        $this->hooks['error_delete'][] = $callback;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function create(array $attributes): Model
    {
        $class = $this->modelClass();
        /** @var TModel $model */
        $model = new $class();

        try {
            $this->runHooks('before_create', $model, $attributes);
            $model->fill($attributes);
            $model->save();
            $this->runHooks('after_create', $model, $attributes);

            return $model;
        } catch (Throwable $exception) {
            $this->runHooks('error_create', $model, $attributes, $exception);
            throw $exception;
        }
    }

    /**
     * @param TModel $model
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model
    {
        try {
            $this->runHooks('before_update', $model, $attributes);
            $model->fill($attributes);
            $model->save();
            $this->runHooks('after_update', $model, $attributes);

            return $model;
        } catch (Throwable $exception) {
            $this->runHooks('error_update', $model, $attributes, $exception);
            throw $exception;
        }
    }

    /** @param TModel $model */
    public function delete(Model $model): bool
    {
        try {
            $this->runHooks('before_delete', $model, []);
            $deleted = (bool) $model->delete();
            $this->runHooks('after_delete', $model, []);

            return $deleted;
        } catch (Throwable $exception) {
            $this->runHooks('error_delete', $model, [], $exception);
            throw $exception;
        }
    }

    /**
     * @param TModel $model
     * @param array<string, mixed> $attributes
     */
    private function runHooks(
        string $name,
        Model $model,
        array $attributes,
        ?Throwable $exception = null,
    ): void {
        if (!array_key_exists($name, $this->hooks)) {
            throw new RuntimeException("Hook desconocido: {$name}");
        }

        foreach ($this->hooks[$name] as $callback) {
            $callback($model, $attributes, $exception);
        }
    }
}
