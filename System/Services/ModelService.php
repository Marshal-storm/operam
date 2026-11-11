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
}
