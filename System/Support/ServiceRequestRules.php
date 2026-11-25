<?php

declare(strict_types=1);

namespace System\Support;

use InvalidArgumentException;

/**
 * Reglas puras BCMath de solicitudes (Módulo 4 Paso 1).
 * Sin I/O — apto para pruebas unitarias: precios, tipo, impacto MRR y gate de cartera.
 */
final class ServiceRequestRules
{
    private const SCALE = 2;

    public const EXCEPTION_POLICY_OVERDUE = 'approved_with_overdue_balance';

    public const BLOCK_OVERDUE_MESSAGE =
        'El contrato presenta saldo vencido. Para aprobar requiere excepción autorizada.';

    /**
     * Normaliza tipo de servicio de catálogo (`single` → `one_time` en solicitudes).
     */
    public static function normalizeItemServiceType(string $serviceType): string
    {
        $v = strtolower(trim($serviceType));
        if (in_array($v, ['one_time', 'onetime', 'single', 'unico'], true)) {
            return 'one_time';
        }
        if ($v === 'consumption') {
            return 'consumption';
        }

        return 'recurring';
    }

    /**
     * Mapea tipo de ítem de solicitud → tipo de línea de contrato.
     */
    public static function toContractServiceType(string $itemServiceType): string
    {
        return self::normalizeItemServiceType($itemServiceType) === 'one_time'
            ? 'single'
            : self::normalizeItemServiceType($itemServiceType);
    }

    /**
     * Determina request_type según los tipos de servicio incluidos.
     *
     * @param list<string> $itemServiceTypes
     */
    public static function determineRequestType(array $itemServiceTypes): string
    {
        $normalized = array_map([self::class, 'normalizeItemServiceType'], $itemServiceTypes);
        if (in_array('recurring', $normalized, true)) {
            return 'recurring_expansion';
        }
        if (in_array('consumption', $normalized, true)) {
            return 'consumption';
        }

        return 'one_time';
    }

    /**
     * ¿La aprobación toca contrato / movements / MRR?
     * Solo `recurring_expansion` modifica el contrato.
     */
    public static function approvalTouchesContractMrr(string $requestType): bool
    {
        return $requestType === 'recurring_expansion';
    }

    /**
     * Separa ítems de una solicitud mixta: recurrentes (tocan contrato/MRR)
     * vs one_time/consumption (solo facturación, mrr = 0.00).
     *
     * @param list<array{service_type:string,subtotal?:string,mrr_impact_mxn?:string}> $lines
     * @return array{
     *   recurring: list<array<string, mixed>>,
     *   non_recurring: list<array<string, mixed>>,
     *   recurring_mrr_impact_mxn: string,
     *   non_recurring_subtotal: string,
     *   non_recurring_mrr_impact_mxn: string
     * }
     */
    public static function partitionMixedRequestLines(array $lines): array
    {
        $recurring = [];
        $nonRecurring = [];
        $recurringMrr = '0.00';
        $nonSubtotal = '0.00';

        foreach ($lines as $line) {
            $type = self::normalizeItemServiceType((string) ($line['service_type'] ?? 'one_time'));
            if ($type === 'recurring') {
                $recurring[] = $line;
                $recurringMrr = bcadd(
                    $recurringMrr,
                    FinancialCalculator::normalizeMoney((string) ($line['mrr_impact_mxn'] ?? '0')),
                    self::SCALE,
                );
            } else {
                $nonRecurring[] = $line;
                $nonSubtotal = bcadd(
                    $nonSubtotal,
                    FinancialCalculator::normalizeMoney((string) ($line['subtotal'] ?? '0')),
                    self::SCALE,
                );
            }
        }

        return [
            'recurring' => $recurring,
            'non_recurring' => $nonRecurring,
            'recurring_mrr_impact_mxn' => $recurringMrr,
            'non_recurring_subtotal' => $nonSubtotal,
            // one_time / consumption NUNCA aportan al MRR, ni en solicitudes mixtas.
            'non_recurring_mrr_impact_mxn' => '0.00',
        ];
    }

    /**
     * ¿Se puede cancelar? Solo pending/quoted.
     *
     * @return array{allowed:bool,message:?string}
     */
    public static function assertCanCancel(string $status): array
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['pending', 'quoted'], true)) {
            return ['allowed' => true, 'message' => null];
        }

        return [
            'allowed' => false,
            'message' => 'Solo se pueden cancelar solicitudes pendientes o cotizadas.',
        ];
    }

    /**
     * Calcula subtotal e impacto MRR MXN de una línea (con RF-2 opcional).
     *
     * @return array{
     *   quantity:int,
     *   unit_price:string,
     *   subtotal:string,
     *   mrr_impact_mxn:string,
     *   rf2_applied:bool,
     *   rf2_savings:string,
     *   expanded_lines:list<array{
     *     service_id:int,
     *     description:string,
     *     service_type:string,
     *     billing_frequency:string,
     *     quantity:int,
     *     unit_price:string,
     *     subtotal:string,
     *     mrr_mxn:string,
     *     is_package_converted:bool
     *   }>
     * }
     */
    public static function priceLine(
        int $serviceId,
        string $serviceName,
        string $serviceType,
        string $billingFrequency,
        int $quantity,
        string $unitPrice,
        string $exchangeRate,
        bool $allowPackageConversion = false,
        ?int $packageServiceId = null,
        string $packageServiceName = '',
        int $packageFactor = 0,
        string $packageUnitPrice = '0.00',
    ): array {
        $type = self::normalizeItemServiceType($serviceType);
        $qty = max(1, $quantity);
        $unit = FinancialCalculator::normalizeMoney($unitPrice);
        $contractType = self::toContractServiceType($type);
        $freq = $type === 'recurring'
            ? ($billingFrequency === 'annual' ? 'annual' : 'monthly')
            : 'single';

        if ($type !== 'recurring') {
            $subtotal = ContractPricingEngine::lineSubtotal($qty, $unit, '0.00');

            return [
                'quantity' => $qty,
                'unit_price' => $unit,
                'subtotal' => $subtotal,
                'mrr_impact_mxn' => '0.00',
                'rf2_applied' => false,
                'rf2_savings' => '0.00',
                'expanded_lines' => [[
                    'service_id' => $serviceId,
                    'description' => $serviceName,
                    'service_type' => $contractType,
                    'billing_frequency' => $freq,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'subtotal' => $subtotal,
                    'mrr_mxn' => '0.00',
                    'is_package_converted' => false,
                ]],
            ];
        }

        $expanded = ContractPricingEngine::expandLineWithRf2(
            $serviceId,
            $serviceName,
            $contractType,
            $freq,
            $qty,
            $unit,
            $allowPackageConversion,
            $packageServiceId,
            $packageServiceName,
            $packageFactor,
            $packageUnitPrice,
        );

        $totalSub = '0.00';
        $totalMrr = '0.00';
        $savings = '0.00';
        $rf2 = false;
        $mapped = [];
        foreach ($expanded as $exp) {
            $lineSub = ContractPricingEngine::lineSubtotal(
                (int) $exp['quantity'],
                (string) $exp['unit_price'],
                '0.00',
            );
            $lineMrr = ContractPricingEngine::lineMrrMxn(
                (string) $exp['service_type'],
                (string) $exp['billing_frequency'],
                $lineSub,
                $exchangeRate,
            );
            $totalSub = bcadd($totalSub, $lineSub, self::SCALE);
            $totalMrr = bcadd($totalMrr, $lineMrr, self::SCALE);
            $savings = bcadd($savings, FinancialCalculator::normalizeMoney((string) ($exp['rf2_savings'] ?? '0')), self::SCALE);
            if (!empty($exp['is_package_converted'])) {
                $rf2 = true;
            }
            $mapped[] = [
                'service_id' => (int) $exp['service_id'],
                'description' => (string) $exp['description'],
                'service_type' => (string) $exp['service_type'],
                'billing_frequency' => (string) $exp['billing_frequency'],
                'quantity' => (int) $exp['quantity'],
                'unit_price' => FinancialCalculator::normalizeMoney((string) $exp['unit_price']),
                'subtotal' => $lineSub,
                'mrr_mxn' => $lineMrr,
                'is_package_converted' => (bool) $exp['is_package_converted'],
            ];
        }

        return [
            'quantity' => $qty,
            'unit_price' => $unit,
            'subtotal' => $totalSub,
            'mrr_impact_mxn' => $totalMrr,
            'rf2_applied' => $rf2,
            'rf2_savings' => $savings,
            'expanded_lines' => $mapped,
        ];
    }

    /**
     * Agrega totales de solicitud a partir de líneas ya calculadas.
     *
     * @param list<array{subtotal:string,mrr_impact_mxn:string,service_type:string}> $lines
     * @return array{subtotal:string,mrr_impact_mxn:string,request_type:string}
     */
    public static function summarizeRequest(array $lines): array
    {
        $subtotal = '0.00';
        $mrr = '0.00';
        $types = [];
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, FinancialCalculator::normalizeMoney((string) $line['subtotal']), self::SCALE);
            $mrr = bcadd($mrr, FinancialCalculator::normalizeMoney((string) ($line['mrr_impact_mxn'] ?? '0')), self::SCALE);
            $types[] = (string) ($line['service_type'] ?? 'one_time');
        }

        $requestType = self::determineRequestType($types);
        if (!self::approvalTouchesContractMrr($requestType)) {
            $mrr = '0.00';
        }

        return [
            'subtotal' => $subtotal,
            'mrr_impact_mxn' => $mrr,
            'request_type' => $requestType,
        ];
    }

    /**
     * Gate de cartera: bloquea aprobación con adeudo salvo excepción + motivo (≥5).
     *
     * @return array{allowed:bool,requires_exception:bool,message:?string,exception_policy:?string}
     */
    public static function evaluateCollectionGate(
        string $overdueBalance,
        int $daysOverdue,
        bool $withException,
        string $resolutionNotes,
    ): array {
        $balance = FinancialCalculator::normalizeMoney($overdueBalance);
        $hasDebt = bccomp($balance, '0.00', self::SCALE) > 0 || $daysOverdue > 0;

        if (!$hasDebt) {
            return [
                'allowed' => true,
                'requires_exception' => false,
                'message' => null,
                'exception_policy' => null,
            ];
        }

        $notes = trim($resolutionNotes);
        if (!$withException || mb_strlen($notes) < 5) {
            return [
                'allowed' => false,
                'requires_exception' => true,
                'message' => self::BLOCK_OVERDUE_MESSAGE,
                'exception_policy' => null,
            ];
        }

        return [
            'allowed' => true,
            'requires_exception' => true,
            'message' => null,
            'exception_policy' => self::EXCEPTION_POLICY_OVERDUE,
        ];
    }

    /**
     * Impacto de expansión sobre MRR del contrato (wrapper BCMath).
     *
     * @return array{mrr_before:string,mrr_after:string,mrr_delta_mxn:string}
     */
    public static function expansionContractImpact(string $contractMrrBefore, string $addedMrrMxn): array
    {
        $added = FinancialCalculator::normalizeMoney($addedMrrMxn);
        if (bccomp($added, '0.00', self::SCALE) <= 0) {
            throw new InvalidArgumentException('La expansión recurrente debe generar incremento de MRR.');
        }

        return ContractLifecycleEngine::expansionImpact($contractMrrBefore, $added);
    }

    /**
     * Payload de movimiento inmutable para expansión desde solicitud.
     *
     * @param array{mrr_before:string,mrr_after:string,mrr_delta_mxn:string} $impact
     * @return array<string, mixed>
     */
    public static function buildExpansionMovementPayload(
        int $contractId,
        int $requestId,
        array $impact,
        string $effectiveDate,
        string $exchangeRate,
        ?int $userId,
        string $reason,
        bool $withException,
        ?string $exceptionPolicy,
        ?int $contractLineId = null,
        ?int $serviceId = null,
        string $quantityBefore = '0.00',
        string $quantityAfter = '0.00',
        string $createdAtUtc = '',
        ?string $overdueBalanceAtException = null,
    ): array {
        $now = $createdAtUtc !== '' ? $createdAtUtc : gmdate('Y-m-d H:i:s');

        return [
            'contract_id' => $contractId,
            'contract_line_id' => $contractLineId,
            'service_id' => $serviceId,
            'movement_type' => 'expansion',
            'mrr_category' => 'expansion',
            'effective_date' => $effectiveDate,
            'quantity_before' => FinancialCalculator::normalizeMoney($quantityBefore),
            'quantity_after' => FinancialCalculator::normalizeMoney($quantityAfter),
            'mrr_before' => $impact['mrr_before'],
            'mrr_after' => $impact['mrr_after'],
            'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
            'exchange_rate' => bcadd($exchangeRate, '0', 6),
            'user_id' => $userId !== null && $userId > 0 ? $userId : null,
            'request_id' => $requestId,
            'reason' => mb_substr($reason, 0, 255),
            'has_exception' => $withException ? 1 : 0,
            'excepted_policy' => $withException ? ($exceptionPolicy ?? self::EXCEPTION_POLICY_OVERDUE) : null,
            'overdue_balance_at_exception' => $withException && $overdueBalanceAtException !== null
                ? FinancialCalculator::normalizeMoney($overdueBalanceAtException)
                : null,
            'reversed_movement_id' => null,
            'created_at' => $now,
        ];
    }
}
