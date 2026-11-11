<?php

declare(strict_types=1);

namespace Operam\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Aritmética monetaria exacta (RNF-1) con brick/math + escala 2.
 * Jamás usar float para cálculos financieros.
 */
final class Money
{
    private const SCALE = 2;

    private function __construct(
        private readonly BigDecimal $amount,
    ) {
    }

    public static function of(string|int|BigDecimal $amount): self
    {
        if ($amount instanceof BigDecimal) {
            return new self($amount->toScale(self::SCALE, RoundingMode::HALF_UP));
        }

        if (is_int($amount)) {
            return new self(BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::HALF_UP));
        }

        $normalized = trim($amount);
        if ($normalized === '' || !preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException("Monto inválido: {$amount}");
        }

        return new self(BigDecimal::of($normalized)->toScale(self::SCALE, RoundingMode::HALF_UP));
    }

    public function plus(self $other): self
    {
        return new self(
            $this->amount->plus($other->amount)->toScale(self::SCALE, RoundingMode::HALF_UP)
        );
    }

    public function minus(self $other): self
    {
        return new self(
            $this->amount->minus($other->amount)->toScale(self::SCALE, RoundingMode::HALF_UP)
        );
    }

    public function negated(): self
    {
        return new self($this->amount->negated()->toScale(self::SCALE, RoundingMode::HALF_UP));
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    /** Representación DECIMAL(14,2) como string. */
    public function toDecimalString(): string
    {
        return (string) $this->amount->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }
}
