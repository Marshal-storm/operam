<?php

declare(strict_types=1);

namespace System\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Calculadora pura de dominio financiero (RNF-1 / RF-2 / RNF-3).
 * Usa exclusivamente BCMath con escala 2 — cero floats.
 */
final class FinancialCalculator
{
    private const SCALE = 2;

    /** Categorías del Puente de MRR (excluye transferencia, cuyo delta es siempre 0.00). */
    public const MRR_BRIDGE_CATEGORIES = [
        'nuevo',
        'expansion',
        'ajuste_precio',
        'cambio_frecuencia',
        'contraccion',
        'churn',
        'reactivacion',
    ];

    /**
     * RF-2: descompone usuarios en paquetes de 5 + sueltos y compara totales.
     *
     * @return array{
     *     packagesCount: int,
     *     singleUsersCount: int,
     *     regularTotal: string,
     *     optimizedTotal: string,
     *     savings: string
     * }
     */
    public static function optimizeUserPackages(
        int $userCount,
        string $singleUserPrice,
        string $pack5Price,
    ): array {
        if ($userCount < 0) {
            throw new InvalidArgumentException('userCount no puede ser negativo.');
        }

        $single = self::normalizeMoney($singleUserPrice);
        $pack = self::normalizeMoney($pack5Price);

        $packagesCount = intdiv($userCount, 5);
        $singleUsersCount = $userCount % 5;

        $regularTotal = bcmul((string) $userCount, $single, self::SCALE);
        $packsTotal = bcmul((string) $packagesCount, $pack, self::SCALE);
        $singlesTotal = bcmul((string) $singleUsersCount, $single, self::SCALE);
        $optimizedTotal = bcadd($packsTotal, $singlesTotal, self::SCALE);
        $savings = bcsub($regularTotal, $optimizedTotal, self::SCALE);

        // Ahorro negativo no tiene sentido de negocio; se reporta como 0.00.
        if (bccomp($savings, '0.00', self::SCALE) < 0) {
            $savings = '0.00';
        }

        return [
            'packagesCount' => $packagesCount,
            'singleUsersCount' => $singleUsersCount,
            'regularTotal' => $regularTotal,
            'optimizedTotal' => $optimizedTotal,
            'savings' => $savings,
        ];
    }

    /**
     * Puente de MRR: Inicio + sum(deltas por categoría) = Fin (RNF-1).
     * Ignora `transferencia` (delta contable '0.00').
     *
     * @param list<array{categoria_mrr?: string, mrr_delta_mxn?: string|int|float}> $movements
     * @return array{
     *     mrrEnd: string,
     *     netChange: string,
     *     breakdown: array<string, string>
     * }
     */
    public static function calculateMrrBridge(string $mrrStart, array $movements): array
    {
        $start = self::normalizeMoney($mrrStart);

        $breakdown = [];
        foreach (self::MRR_BRIDGE_CATEGORIES as $category) {
            $breakdown[$category] = '0.00';
        }

        foreach ($movements as $movement) {
            $category = (string) ($movement['categoria_mrr'] ?? '');
            if ($category === 'transferencia' || $category === '') {
                continue;
            }
            if (!in_array($category, self::MRR_BRIDGE_CATEGORIES, true)) {
                continue;
            }

            $delta = self::normalizeMoney((string) ($movement['mrr_delta_mxn'] ?? '0.00'));
            $breakdown[$category] = bcadd($breakdown[$category], $delta, self::SCALE);
        }

        $net = '0.00';
        foreach ($breakdown as $categoryTotal) {
            $net = bcadd($net, $categoryTotal, self::SCALE);
        }

        $mrrEnd = bcadd($start, $net, self::SCALE);

        return [
            'mrrEnd' => $mrrEnd,
            'netChange' => self::formatSigned($net),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Construye el payload INSERT de reversión contable (RNF-3).
     * Nunca muta la fila original: solo valores invertidos para un nuevo movimiento.
     *
     * @param array<string, mixed> $originalMovement
     * @return array<string, mixed>
     */
    public static function buildReversalPayload(
        array $originalMovement,
        int $userId,
        string $reason,
    ): array {
        if ($userId < 1) {
            throw new InvalidArgumentException('usuario_id debe ser positivo.');
        }

        // Blindaje: prohibido señalizar mutación in-place de la fila original.
        if (!empty($originalMovement['_inplace_update']) || !empty($originalMovement['_mutate'])) {
            throw new RuntimeException(
                'RNF-3: la bitácora movimiento es inmutable. No se permite mutación in-place; emita una reversión contable (INSERT).',
            );
        }

        $delta = self::normalizeMoney((string) ($originalMovement['mrr_delta_mxn'] ?? '0.00'));
        $mrrAntes = self::normalizeMoney((string) ($originalMovement['mrr_antes'] ?? '0.00'));
        $mrrDespues = self::normalizeMoney((string) ($originalMovement['mrr_despues'] ?? '0.00'));

        $invertedDelta = bcmul($delta, '-1', self::SCALE);

        $payload = [
            'tipo' => 'reversión',
            'categoria_mrr' => (string) ($originalMovement['categoria_mrr'] ?? ''),
            'mrr_antes' => $mrrDespues,
            'mrr_despues' => $mrrAntes,
            'mrr_delta_mxn' => $invertedDelta,
            'cantidad_antes' => isset($originalMovement['cantidad_despues'])
                ? self::normalizeMoney((string) $originalMovement['cantidad_despues'])
                : null,
            'cantidad_despues' => isset($originalMovement['cantidad_antes'])
                ? self::normalizeMoney((string) $originalMovement['cantidad_antes'])
                : null,
            'usuario_id' => $userId,
            'motivo' => mb_substr(trim($reason) !== '' ? trim($reason) : 'Reversión contable', 0, 255),
            'movimiento_revertido_id' => isset($originalMovement['id'])
                ? (int) $originalMovement['id']
                : null,
        ];

        // Verificación aritmética: mrr_despues - mrr_antes == delta invertido.
        $check = bcsub($payload['mrr_despues'], $payload['mrr_antes'], self::SCALE);
        if (bccomp($check, $invertedDelta, self::SCALE) !== 0) {
            throw new RuntimeException('Inconsistencia aritmética al construir la reversión (RNF-3).');
        }

        // Original + inverso debe cancelarse exactamente.
        $cancel = bcadd($delta, $invertedDelta, self::SCALE);
        if (bccomp($cancel, '0.00', self::SCALE) !== 0) {
            throw new RuntimeException('Los deltas original e inverso no se cancelan a 0.00 (RNF-3).');
        }

        return $payload;
    }

    /** Normaliza un monto string a exactamente 2 decimales vía BCMath. */
    public static function normalizeMoney(string $amount): string
    {
        $trimmed = trim(str_replace(',', '', $amount));
        if ($trimmed === '' || !is_numeric($trimmed)) {
            throw new InvalidArgumentException("Monto inválido: {$amount}");
        }

        return bcadd($trimmed, '0', self::SCALE);
    }

    /** Formato con signo explícito para variaciones netas (+30200.00 / -7400.00 / 0.00). */
    public static function formatSigned(string $amount): string
    {
        $normalized = self::normalizeMoney($amount);
        if (bccomp($normalized, '0.00', self::SCALE) > 0) {
            return '+' . $normalized;
        }

        return $normalized;
    }
}
