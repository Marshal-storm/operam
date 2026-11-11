<?php

declare(strict_types=1);

namespace System\Core;

/**
 * Contexto de petición del framework `operam/servicios`.
 */
final class Context
{
    /** @var array<string, mixed> */
    private array $data = [];

    private ?Result $result = null;

    private string $uri = '';

    private string $method = 'GET';

    /** @param array<string, mixed> $request */
    public function __construct(
        private array $request = [],
        private array $headers = [],
    ) {
    }

    public function setUri(string $uri): void
    {
        $this->uri = $uri;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function setMethod(string $method): void
    {
        $this->method = strtoupper($method);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function getData(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allData(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function getRequest(): array
    {
        return $this->request;
    }

    /**
     * Devuelve el valor del header o null si falta / está vacío.
     * Importante: nunca devolver "" — rompe cadenas `??` (p. ej. IP en login).
     */
    public function getHeader(string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) !== $needle) {
                continue;
            }

            if (is_array($value)) {
                if (!array_key_exists(0, $value) || $value[0] === null || $value[0] === '') {
                    return null;
                }

                $resolved = trim((string) $value[0]);

                return $resolved === '' ? null : $resolved;
            }

            $resolved = trim((string) $value);

            return $resolved === '' ? null : $resolved;
        }

        return null;
    }

    public function setResult(Result $result): void
    {
        $this->result = $result;
    }

    public function getResult(): ?Result
    {
        return $this->result;
    }

    public function hasResult(): bool
    {
        return $this->result instanceof Result;
    }
}
