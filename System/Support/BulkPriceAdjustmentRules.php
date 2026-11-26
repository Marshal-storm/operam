<?php

declare(strict_types=1);

namespace System\Support;

use InvalidArgumentException;
use System\Support\Jobs\BillingCycleRules;

/**
 * Motor puro del Ajuste Masivo de Precios (RF-3.6) — BCMath escala 2, sin I/O.
 * El nuevo precio sustituye al pactado y quema el cupón (`discount_amount = 0.00`).
 */
final class BulkPriceAdjustmentRules
{
    private const SCALE = 2;

    /** Un decremento del 100% dejaría precios en cero: se exige > -100. */
    public const MIN_PERCENTAGE = '-99.99';

    /** Límite de DECIMAL(6,2) con margen operativo razonable. */
    public const MAX_PERCENTAGE = '999.99';

    public const MIN_REASON_LENGTH = 5;

    /**
     * Valida y normaliza el porcentaje (ej. `4.5` → `4.50`, `-5` → `-5.00`); nunca 0.
     */
    public static function normalizePercentage(mixed $raw): string
    {
        $value = trim(str_replace([',', ' ', '%'], '', (string) ($raw ?? '')));
        if (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }
        if ($value === '' || preg_match('/^-?\d{1,4}(\.\d{1,2})?$/', $value) !== 1) {
            throw new InvalidArgumentException('El porcentaje debe ser numérico con hasta 2 decimales (ej. 4.50 o -5.00).');
        }
        $normalized = bcadd($value, '0', self::SCALE);
        if (bccomp($normalized, '0.00', self::SCALE) === 0) {
            throw new InvalidArgumentException('El porcentaje de ajuste debe ser distinto de 0.');
        }
        if (bccomp($normalized, self::MIN_PERCENTAGE, self::SCALE) < 0 || bccomp($normalized, self::MAX_PERCENTAGE, self::SCALE) > 0) {
            throw new InvalidArgumentException(sprintf(
                'El porcentaje debe estar entre %s%% y %s%%.',
                self::MIN_PERCENTAGE,
                self::MAX_PERCENTAGE,
            ));
        }

        return $normalized;
    }

    /** Nuevo precio unitario = precio × (1 + % / 100), redondeo half-up al centavo. */
    public static function adjustUnitPrice(string $unitPrice, string $percentage): string
    {
        $price = FinancialCalculator::normalizeMoney($unitPrice);
        $factor = bcadd('1', bcdiv(FinancialCalculator::normalizeMoney($percentage), '100', 6), 6);
        $adjusted = BillingCycleRules::roundHalfUp(bcmul($price, $factor, 6));

        return bccomp($adjusted, '0.00', self::SCALE) < 0 ? '0.00' : $adjusted;
    }

    /**
     * Recalcula una línea recurrente con el nuevo precio (cupón quemado).
     *
     * @return array{unit_price:string,discount_amount:string,subtotal:string,mrr_mxn:string}
     */
    public static function adjustLine(
        int $quantity,
        string $unitPrice,
        string $billingFrequency,
        string $exchangeRate,
        string $percentage,
    ): array {
        $newUnitPrice = self::adjustUnitPrice($unitPrice, $percentage);
        $recalc = ContractLifecycleEngine::recalculateLineNetOfCoupon(
            $quantity,
            $newUnitPrice,
            'recurring',
            $billingFrequency,
            $exchangeRate,
        );

        return [
            'unit_price' => $newUnitPrice,
            'discount_amount' => $recalc['discount_amount'],
            'subtotal' => $recalc['subtotal'],
            'mrr_mxn' => $recalc['mrr_mxn'],
        ];
    }

    /**
     * Impacto en el MRR del contrato: se restan las líneas afectadas y se suman ya ajustadas;
     * las líneas no tocadas conservan su MRR intacto.
     *
     * @param list<array{old_mrr_mxn:string,new_mrr_mxn:string}> $affectedLines
     * @return array{mrr_before:string,mrr_after:string,mrr_delta_mxn:string}
     */
    public static function contractImpact(string $contractMrrBefore, array $affectedLines): array
    {
        $after = FinancialCalculator::normalizeMoney($contractMrrBefore);
        foreach ($affectedLines as $line) {
            $after = bcsub($after, FinancialCalculator::normalizeMoney($line['old_mrr_mxn']), self::SCALE);
            $after = bcadd($after, FinancialCalculator::normalizeMoney($line['new_mrr_mxn']), self::SCALE);
        }
        if (bccomp($after, '0.00', self::SCALE) < 0) {
            $after = '0.00';
        }

        return ContractLifecycleEngine::impact($contractMrrBefore, $after);
    }

    /** Folio del lote: `AJ-2026-001` (secuencia anual). */
    public static function formatBatchCode(int $year, int $sequence): string
    {
        return sprintf('AJ-%04d-%03d', $year, max(1, $sequence));
    }

    /** Siguiente secuencia a partir del último folio del año (o 1 si no existe). */
    public static function nextSequence(?string $lastBatchCode, int $year): int
    {
        if ($lastBatchCode === null || preg_match('/^AJ-(\d{4})-(\d+)$/', $lastBatchCode, $m) !== 1) {
            return 1;
        }

        return (int) $m[1] === $year ? (int) $m[2] + 1 : 1;
    }

    public static function normalizeReason(mixed $raw): string
    {
        $reason = trim((string) ($raw ?? ''));
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new InvalidArgumentException('El motivo del ajuste es obligatorio (mínimo 5 caracteres).');
        }

        return mb_substr($reason, 0, 200);
    }
}
