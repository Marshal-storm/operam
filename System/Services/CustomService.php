<?php

declare(strict_types=1);

namespace System\Services;

use Closure;
use System\Core\Context;
use System\Core\Result;

/**
 * Servicio base del framework. Todo método de negocio devuelve Result.
 */
abstract class CustomService
{
    /** @var array<string, list<Closure>> */
    protected array $hooks = [];

    /** @param array<string, list<Closure>> $hooks */
    public function __construct(array $hooks = [])
    {
        $this->hooks = $hooks;
    }

    protected function runHook(string $name, Context $context): void
    {
        foreach ($this->hooks[$name] ?? [] as $callback) {
            $callback($context);
            if ($context->hasResult() && !$context->getResult()?->isSuccess()) {
                return;
            }
        }
    }

    abstract public function handle(Context $context): Result;
}
