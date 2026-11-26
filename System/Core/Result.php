<?php

declare(strict_types=1);

namespace System\Core;

/**
 * Contenedor de respuesta obligatorio de todo servicio `operam/servicios`.
 */
final class Result
{
    public function __construct(
        private bool $success = true,
        private mixed $data = null,
        private string $message = '',
        private int $httpCode = 200,
        /** @var array<string, mixed> */
        private array $meta = [],
        private ?string $code = null,
    ) {
    }

    public static function ok(mixed $data = null, string $message = '', int $httpCode = 200): self
    {
        return new self(true, $data, $message, $httpCode);
    }

    public static function fail(string $message, int $httpCode = 400, mixed $data = null): self
    {
        return new self(false, $data, $message, $httpCode);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /** @return array<string, mixed> */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /** @param array<string, mixed> $meta */
    public function withMeta(array $meta): self
    {
        $clone = clone $this;
        $clone->meta = $meta;

        return $clone;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function withCode(?string $code): self
    {
        $clone = clone $this;
        $clone->code = $code;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'meta' => $this->meta,
        ];
        if ($this->code !== null && $this->code !== '') {
            $out['code'] = $this->code;
        }

        return $out;
    }
}
