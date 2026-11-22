<?php

declare(strict_types=1);

namespace System\Support;

use InvalidArgumentException;

/**
 * Motor puro de cobranza (Módulo 4 Paso 2).
 * Solo BCMath escala 2 — cero floats, sin I/O. Ideal para pruebas unitarias.
 */
final class CollectionRules
{
    private const SCALE = 2;

    /** Niveles de aviso alineados con `collection_statuses.notice_level` (tinyint 0–4). */
    public const NOTICE_NONE = 0;
    public const NOTICE_REMINDER = 1;
    public const NOTICE_WARNING = 2;
    public const NOTICE_URGENT = 3;
    public const NOTICE_SUSPENSION = 4;

    public const NOTICE_CODES = [
        self::NOTICE_NONE => 'none',
        self::NOTICE_REMINDER => 'reminder',
        self::NOTICE_WARNING => 'warning',
        self::NOTICE_URGENT => 'urgent',
        self::NOTICE_SUSPENSION => 'suspension',
    ];

    /**
     * Determina el nivel de aviso a partir de días de atraso.
     *
     * @return array{level: int, code: string}
     */
    public static function noticeLevelFromDays(int $daysOverdue): array
    {
        $days = max(0, $daysOverdue);
        if ($days === 0) {
            return ['level' => self::NOTICE_NONE, 'code' => 'none'];
        }
        if ($days <= 30) {
            return ['level' => self::NOTICE_REMINDER, 'code' => 'reminder'];
        }
        if ($days <= 60) {
            return ['level' => self::NOTICE_WARNING, 'code' => 'warning'];
        }
        if ($days <= 89) {
            return ['level' => self::NOTICE_URGENT, 'code' => 'urgent'];
        }

        return ['level' => self::NOTICE_SUSPENSION, 'code' => 'suspension'];
    }

    /**
     * Etiqueta de tramo de antigüedad (alineada con Dashboard / AgingPortfolioCard).
     */
    public static function agingBucketKey(int $daysOverdue, bool $isSuspended): string
    {
        if ($isSuspended || $daysOverdue >= 90) {
            return 'suspended_90_plus';
        }
        if ($daysOverdue <= 0) {
            return 'upcoming';
        }
        if ($daysOverdue <= 30) {
            return 'days_1_30';
        }
        if ($daysOverdue <= 60) {
            return 'days_31_60';
        }

        return 'days_61_90';
    }

    /**
     * Calcula saldo vencido y días de atraso desde facturas abiertas.
     *
     * @param list<array{balance: string, due_date: string, status: string}> $invoices
     * @return array{
     *   overdue_balance: string,
     *   days_overdue: int,
     *   notice_level: int,
     *   notice_code: string,
     *   mark_overdue_ids: list<int>
     * }
     */
    public static function computeCollectionStatusFromInvoices(array $invoices, string $todayYmd): array
    {
        $overdueBalance = '0.00';
        $oldestDue = null;
        $markOverdueIds = [];

        foreach ($invoices as $invoice) {
            $status = (string) ($invoice['status'] ?? '');
            if ($status === 'cancelled' || $status === 'paid') {
                continue;
            }
            $balance = FinancialCalculator::normalizeMoney((string) ($invoice['balance'] ?? '0'));
            if (bccomp($balance, '0.00', self::SCALE) <= 0) {
                continue;
            }
            $dueDate = (string) ($invoice['due_date'] ?? '');
            if ($dueDate === '' || $dueDate >= $todayYmd) {
                continue;
            }
            $overdueBalance = bcadd($overdueBalance, $balance, self::SCALE);
            if ($oldestDue === null || $dueDate < $oldestDue) {
                $oldestDue = $dueDate;
            }
            $id = isset($invoice['id']) ? (int) $invoice['id'] : 0;
            if ($id > 0 && in_array($status, ['pending', 'partial'], true)) {
                $markOverdueIds[] = $id;
            }
        }

        $daysOverdue = 0;
        if ($oldestDue !== null && bccomp($overdueBalance, '0.00', self::SCALE) > 0) {
            $daysOverdue = self::daysBetween($oldestDue, $todayYmd);
        }

        $notice = self::noticeLevelFromDays($daysOverdue);

        return [
            'overdue_balance' => $overdueBalance,
            'days_overdue' => $daysOverdue,
            'notice_level' => $notice['level'],
            'notice_code' => $notice['code'],
            'mark_overdue_ids' => $markOverdueIds,
        ];
    }

    /**
     * Aplica un pago parcial/total y deriva el nuevo estado del documento.
     *
     * @return array{paid_amount: string, balance: string, status: string, new_balance: string}
     */
    public static function applyPayment(
        string $currentPaid,
        string $currentBalance,
        string $paymentAmount,
        string $dueDate,
        string $todayYmd,
    ): array {
        $paid = FinancialCalculator::normalizeMoney($currentPaid);
        $balance = FinancialCalculator::normalizeMoney($currentBalance);
        $amount = FinancialCalculator::normalizeMoney($paymentAmount);

        if (bccomp($amount, '0.00', self::SCALE) <= 0) {
            throw new InvalidArgumentException('El monto del pago debe ser mayor a 0.00.');
        }
        if (bccomp($amount, $balance, self::SCALE) > 0) {
            throw new InvalidArgumentException('El pago no puede exceder el saldo pendiente.');
        }

        $newPaid = bcadd($paid, $amount, self::SCALE);
        $newBalance = bcsub($balance, $amount, self::SCALE);
        $status = self::resolveInvoiceStatusAfterPayment($newBalance, $dueDate, $todayYmd);

        return [
            'paid_amount' => $newPaid,
            'balance' => $newBalance,
            'new_balance' => $newBalance,
            'status' => $status,
        ];
    }

    /**
     * Estado del documento tras un pago (o al emitir).
     */
    public static function resolveInvoiceStatusAfterPayment(
        string $balance,
        string $dueDate,
        string $todayYmd,
    ): string {
        $bal = FinancialCalculator::normalizeMoney($balance);
        if (bccomp($bal, '0.00', self::SCALE) === 0) {
            return 'paid';
        }
        if ($dueDate < $todayYmd) {
            return 'overdue';
        }
        // Si aún hay saldo y hubo pagos previos, el caller puede forzar partial;
        // aquí: si balance < total implícito no lo sabemos — usar partial si paid > 0 lo decide el servicio.
        return 'partial';
    }

    /**
     * Estado inicial / recalculado de una factura abierta.
     */
    public static function resolveOpenInvoiceStatus(
        string $paidAmount,
        string $balance,
        string $dueDate,
        string $todayYmd,
    ): string {
        $paid = FinancialCalculator::normalizeMoney($paidAmount);
        $bal = FinancialCalculator::normalizeMoney($balance);
        if (bccomp($bal, '0.00', self::SCALE) === 0) {
            return 'paid';
        }
        if ($dueDate < $todayYmd) {
            return 'overdue';
        }
        if (bccomp($paid, '0.00', self::SCALE) > 0) {
            return 'partial';
        }

        return 'pending';
    }

    /**
     * ¿Elegible para suspensión automática a +90 días?
     */
    public static function isEligibleForSuspension(
        string $contractStatus,
        int $daysOverdue,
        string $overdueBalance,
        bool $excludeSuspension,
    ): bool {
        if ($excludeSuspension) {
            return false;
        }
        if ($contractStatus !== 'active') {
            return false;
        }
        if ($daysOverdue < 90) {
            return false;
        }

        return bccomp(FinancialCalculator::normalizeMoney($overdueBalance), '0.00', self::SCALE) > 0;
    }

    /**
     * Revierte un pago (sin DELETE): resta el monto del paid_amount y reabre balance/status.
     *
     * @return array{paid_amount: string, balance: string, status: string}
     */
    public static function reversePayment(
        string $invoiceTotal,
        string $currentPaid,
        string $paymentAmount,
        string $dueDate,
        string $todayYmd,
    ): array {
        $total = FinancialCalculator::normalizeMoney($invoiceTotal);
        $paid = FinancialCalculator::normalizeMoney($currentPaid);
        $amount = FinancialCalculator::normalizeMoney($paymentAmount);

        if (bccomp($amount, '0.00', self::SCALE) <= 0) {
            throw new InvalidArgumentException('El monto del pago a revertir debe ser mayor a 0.00.');
        }
        if (bccomp($amount, $paid, self::SCALE) > 0) {
            throw new InvalidArgumentException('No se puede revertir más de lo pagado.');
        }

        $newPaid = bcsub($paid, $amount, self::SCALE);
        $newBalance = bcsub($total, $newPaid, self::SCALE);

        return [
            'paid_amount' => $newPaid,
            'balance' => $newBalance,
            'status' => self::resolveOpenInvoiceStatus($newPaid, $newBalance, $dueDate, $todayYmd),
        ];
    }

    /**
     * Calcula due_date = issue_date + credit_days (días naturales).
     */
    public static function computeDueDate(string $issueDateYmd, int $creditDays): string
    {
        $days = max(0, $creditDays);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $issueDateYmd);
        if ($dt === false) {
            throw new InvalidArgumentException('Fecha de emisión inválida.');
        }

        return $dt->modify("+{$days} days")->format('Y-m-d');
    }

    /** Tasa de IVA oficial para documentos de cobro (MRR permanece sin impuestos). */
    public const IVA_RATE = '0.16';

    /**
     * Totales de emisión con IVA 16% por defecto (BCMath).
     * `$taxAmount = null` → calcula `bcmul($subtotal, '0.16', 2)`.
     * Pasar un string explícito solo para tests o casos especiales.
     *
     * @return array{subtotal: string, tax_amount: string, total: string, paid_amount: string, balance: string}
     */
    public static function buildInvoiceAmounts(string $subtotal, ?string $taxAmount = null): array
    {
        $sub = FinancialCalculator::normalizeMoney($subtotal);
        if (bccomp($sub, '0.00', self::SCALE) <= 0) {
            throw new InvalidArgumentException('El subtotal del documento debe ser mayor a 0.00.');
        }

        $tax = $taxAmount === null
            ? bcmul($sub, self::IVA_RATE, self::SCALE)
            : FinancialCalculator::normalizeMoney($taxAmount);
        $total = bcadd($sub, $tax, self::SCALE);

        return [
            'subtotal' => $sub,
            'tax_amount' => $tax,
            'total' => $total,
            'paid_amount' => '0.00',
            'balance' => $total,
        ];
    }

    /**
     * Días naturales entre dos fechas YYYY-MM-DD (siempre ≥ 0).
     */
    public static function daysBetween(string $fromYmd, string $toYmd): int
    {
        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromYmd);
        $to = \DateTimeImmutable::createFromFormat('Y-m-d', $toYmd);
        if ($from === false || $to === false) {
            return 0;
        }
        $diff = (int) $from->diff($to)->format('%r%a');

        return max(0, $diff);
    }

    /**
     * Etiqueta legible del nivel de aviso (UI es-MX).
     */
    public static function noticeLabel(int $level): string
    {
        return match ($level) {
            self::NOTICE_REMINDER => 'Recordatorio',
            self::NOTICE_WARNING => 'Advertencia',
            self::NOTICE_URGENT => 'Urgente',
            self::NOTICE_SUSPENSION => 'Suspensión',
            default => 'Al corriente',
        };
    }
}
