<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Eloquent\Model;
use System\Core\Context;
use System\Core\DataFormat;
use System\Core\DataValidator;
use System\Core\Result;

/**
 * ModelService del framework: validación + formateo + Result obligatorio.
 */
abstract class ModelService extends CustomService
{
    protected DataValidator $validator;

    /** Si true, el recurso no se expone por rutas públicas del router. */
    protected bool $private = false;

    public function __construct(array $hooks = [])
    {
        parent::__construct($hooks);
        $this->validator = new DataValidator();
        $this->configureFields();
    }

    abstract protected function modelClass(): string;

    abstract protected function configureFields(): void;

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function handle(Context $context): Result
    {
        return Result::ok(null, 'ModelService listo');
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, Context $context): Result
    {
        $this->runHook('before_create', $context);
        if ($context->hasResult() && !$context->getResult()?->isSuccess()) {
            return $context->getResult();
        }

        $validation = $this->validator->startValidation($input);
        if (!$validation['ok']) {
            return Result::fail(implode(' ', $validation['errors']), 422);
        }

        $payload = DataFormat::formatInput($validation['data'], $this->validator->getFields());
        $class = $this->modelClass();
        /** @var Model $model */
        $model = new $class();
        $model->fill($payload);
        $model->save();

        $context->setData('row', $model->toArray());
        $this->runHook('after_create', $context);
        $this->runHook('after_all', $context);

        $output = DataFormat::formatOutput(
            $context->getData('row', $model->toArray()),
            $this->validator->getFields()
        );

        return Result::ok($output, 'Creado', 201);
    }

    /**
     * Actualización completa (PUT) — valida todos los campos requeridos.
     *
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input, Context $context): Result
    {
        $this->runHook('before_update', $context);
        if ($context->hasResult() && !$context->getResult()?->isSuccess()) {
            return $context->getResult();
        }

        $model = $this->findModel($id);
        if ($model === null) {
            return Result::fail('Registro no encontrado.', 404);
        }

        $validation = $this->validator->startValidation($input);
        if (!$validation['ok']) {
            return Result::fail(implode(' ', $validation['errors']), 422);
        }

        $payload = DataFormat::formatInput($validation['data'], $this->validator->getFields());
        $model->fill($payload);
        $model->save();

        $context->setData('row', $model->fresh()?->toArray() ?? $model->toArray());
        $this->runHook('after_update', $context);
        $this->runHook('after_all', $context);

        $output = DataFormat::formatOutput(
            $context->getData('row', $model->toArray()),
            $this->validator->getFields()
        );

        return Result::ok($output, 'Actualizado');
    }

    /**
     * Actualización parcial (PATCH) — solo campos enviados.
     *
     * @param array<string, mixed> $input
     */
    public function patch(int $id, array $input, Context $context): Result
    {
        $this->runHook('before_patch', $context);
        if ($context->hasResult() && !$context->getResult()?->isSuccess()) {
            return $context->getResult();
        }

        $model = $this->findModel($id);
        if ($model === null) {
            return Result::fail('Registro no encontrado.', 404);
        }

        $fields = $this->validator->getFields();
        $partial = [];
        foreach ($input as $key => $value) {
            if (!isset($fields[$key])) {
                continue;
            }
            $partial[$key] = $value;
        }

        if ($partial === []) {
            return Result::fail('No hay campos válidos para actualizar.', 422);
        }

        // Validación parcial: marca temporalmente no-requeridos los ausentes.
        $tempValidator = new DataValidator();
        foreach ($fields as $name => $rules) {
            $isPresent = array_key_exists($name, $partial);
            $tempValidator->setField(
                $name,
                $rules['type'],
                $isPresent && ($rules['required'] ?? false),
                $rules['default'] ?? null,
                $rules['options'] ?? null,
                (bool) ($rules['hide_output'] ?? false),
            );
        }

        $validation = $tempValidator->startValidation($partial);
        if (!$validation['ok']) {
            return Result::fail(implode(' ', $validation['errors']), 422);
        }

        $payload = DataFormat::formatInput($validation['data'], $fields);
        // Solo aplicar claves presentes en el PATCH (evitar defaults fantasma).
        $toFill = array_intersect_key($payload, $partial);
        $model->fill($toFill);
        $model->save();

        $context->setData('row', $model->fresh()?->toArray() ?? $model->toArray());
        $this->runHook('after_patch', $context);
        $this->runHook('after_all', $context);

        $output = DataFormat::formatOutput(
            $context->getData('row', $model->toArray()),
            $fields
        );

        return Result::ok($output, 'Actualizado');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $class = $this->modelClass();
        /** @var Model $model */
        $model = new $class();
        $rows = $model->newQuery()->get()->toArray();

        /** @var list<array<string, mixed>> $formatted */
        $formatted = DataFormat::formatOutput($rows, $this->validator->getFields());

        return $formatted;
    }

    protected function findModel(int $id): ?Model
    {
        $class = $this->modelClass();
        /** @var Model $model */
        $model = new $class();

        /** @var Model|null $found */
        $found = $model->newQuery()->find($id);

        return $found;
    }
}
