<?php

declare(strict_types=1);

namespace System\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use System\Services\CollectionsService;
use System\Support\Integrations\OutboxPublisher;

/**
 * Cumplimiento de compras de la Tienda del Portal (RF-2.8 / RF-7).
 *
 * - Tarjeta (IntegroPAY): tras el webhook verificado se emite el documento ya pagado + `payments`.
 * - SPEI: el documento se emite `pending` en el checkout; al registrarse el pago total en Cobranza
 *   `CollectionsService::recordPayment` invoca `fulfill()`.
 *
 * En ambos casos se acreditan las unidades compradas a la bolsa `prepaid` (movimiento `purchase`) y,
 * si hubo promoción, las unidades de regalo en un movimiento SEPARADO `promotion` con su
 * `promotion_id`. Nunca se toca el precio, `contract_lines` ni el MRR (RF-2.8).
 *
 * Debe invocarse dentro de una transacción del llamador; es idempotente por bloqueo de fila.
 */
final class PurchaseFulfillment
{
    /**
     * Liquida con tarjeta una compra pendiente: documento pagado + pago + prefactura + bolsas.
     *
     * @param array<string, mixed> $actor
     * @return array{invoice_id: int, payment_id: int, already_paid: bool}
     */
    public static function settleCardPurchase(int $purchaseId, string $transactionId, array $actor): array
    {
        $purchase = Capsule::table('purchases')->where('id', $purchaseId)->lockForUpdate()->first();
        if ($purchase === null) {
            throw new RuntimeException('Compra no encontrada.');
        }
        if ((string) $purchase->status === 'paid') {
            $invoiceId = (int) (Capsule::table('invoices')->where('purchase_id', $purchaseId)->value('id') ?? 0);
            $paymentId = (int) (Capsule::table('payments')->where('invoice_id', $invoiceId)->value('id') ?? 0);

            return ['invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'already_paid' => true];
        }
        if ((string) $purchase->status !== 'pending') {
            throw new RuntimeException('La compra ya no admite pagos (estado ' . (string) $purchase->status . ').');
        }

        $contract = self::loadContract($purchase);
        $details = self::details($purchase);
        $today = self::todayMx();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $invoiceId = self::insertInvoice($purchase, $contract, $details, 'paid', $today, $nowUtc);
        $total = FinancialCalculator::normalizeMoney((string) $purchase->total);
        $paymentId = (int) Capsule::table('payments')->insertGetId([
            'invoice_id' => $invoiceId,
            'contract_id' => (int) $contract->id,
            'client_id' => (int) $purchase->client_id,
            'amount' => $total,
            'payment_method' => 'card_integropay',
            'reference' => mb_substr($transactionId, 0, 120),
            'payment_date' => $today,
            'notes' => 'Pago con tarjeta desde la Tienda del Portal (IntegroPAY).',
            'recorded_by_user_id' => null,
            'is_reversed' => 0,
            'reversed_at' => null,
            'reversal_reason' => null,
            'reversed_by_user_id' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        OutboxPublisher::invoicePreinvoice($invoiceId);
        self::fulfill($purchaseId, $actor);

        return ['invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'already_paid' => false];
    }

    /**
     * Emite el documento `pending` de una compra SPEI (cobro por transferencia).
     *
     * @return array{id: int, folio: string, total: string, due_date: string}
     */
    public static function issueSpeiInvoice(int $purchaseId): array
    {
        $purchase = Capsule::table('purchases')->where('id', $purchaseId)->first();
        if ($purchase === null) {
            throw new RuntimeException('Compra no encontrada.');
        }
        $contract = self::loadContract($purchase);
        $today = self::todayMx();
        $invoiceId = self::insertInvoice($purchase, $contract, self::details($purchase), 'pending', $today, gmdate('Y-m-d H:i:s'));
        OutboxPublisher::invoicePreinvoice($invoiceId);
        (new CollectionsService())->recalculateContractCollectionStatus((int) $contract->id);

        $invoice = Capsule::table('invoices')->where('id', $invoiceId)->first(['folio', 'total', 'due_date']);

        return [
            'id' => $invoiceId,
            'folio' => (string) $invoice->folio,
            'total' => FinancialCalculator::normalizeMoney((string) $invoice->total),
            'due_date' => (string) $invoice->due_date,
        ];
    }

    /**
     * Marca la compra como pagada y acredita bolsas (`purchase` + `promotion` por separado).
     * Idempotente: una compra ya pagada no vuelve a acreditar.
     *
     * @param array<string, mixed> $actor
     */
    public static function fulfill(int $purchaseId, array $actor): bool
    {
        $purchase = Capsule::table('purchases')->where('id', $purchaseId)->lockForUpdate()->first();
        if ($purchase === null || (string) $purchase->status === 'paid') {
            return false;
        }
        if ((string) $purchase->status !== 'pending') {
            throw new RuntimeException('La compra ya no admite pagos (estado ' . (string) $purchase->status . ').');
        }

        $details = self::details($purchase);
        $nowUtc = gmdate('Y-m-d H:i:s');
        Capsule::table('purchases')->where('id', $purchaseId)->update([
            'status' => 'paid',
            'updated_at' => $nowUtc,
        ]);

        $contractId = $purchase->contract_id !== null ? (int) $purchase->contract_id : 0;
        $serviceId = (int) ($details['service_id'] ?? 0);
        $quantity = (int) ($details['quantity'] ?? 0);
        $giftedUnits = (int) ($purchase->gifted_units ?? 0);
        if ((string) ($details['service_type'] ?? '') === 'consumption' && $contractId > 0 && $serviceId > 0 && $quantity > 0) {
            BalanceEngine::grant([
                'contract_id' => $contractId,
                'service_id' => $serviceId,
                'bucket' => 'prepaid',
                'units' => $quantity,
                'movement_type' => 'purchase',
                'notes' => sprintf('Compra en Tienda del Portal #%d', $purchaseId),
                'created_by' => null,
            ]);
            if ($giftedUnits > 0) {
                BalanceEngine::grant([
                    'contract_id' => $contractId,
                    'service_id' => $serviceId,
                    'bucket' => 'prepaid',
                    'units' => $giftedUnits,
                    'movement_type' => 'promotion',
                    'promotion_id' => $purchase->promotion_id !== null ? (int) $purchase->promotion_id : null,
                    'notes' => sprintf(
                        'Unidades de regalo %s (compra #%d)',
                        (string) ($details['promotion_name'] ?? 'promoción'),
                        $purchaseId,
                    ),
                    'created_by' => null,
                ]);
            }
        }

        AuditLogger::log(
            $actor,
            'portal',
            'purchases',
            (string) $purchaseId,
            'status_change',
            sprintf('Compra #%d pagada: %d unidades + %d de regalo', $purchaseId, $quantity, $giftedUnits),
            ['status' => (string) $purchase->status],
            ['status' => 'paid', 'quantity' => $quantity, 'gifted_units' => $giftedUnits],
        );

        return true;
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function insertInvoice(
        object $purchase,
        object $contract,
        array $details,
        string $status,
        string $today,
        string $nowUtc,
    ): int {
        $subtotal = FinancialCalculator::normalizeMoney((string) $purchase->subtotal);
        $taxAmount = FinancialCalculator::normalizeMoney((string) $purchase->tax_amount);
        $total = FinancialCalculator::normalizeMoney((string) $purchase->total);
        $isPaid = $status === 'paid';
        $quantity = (int) ($details['quantity'] ?? 0);
        $gifted = (int) ($purchase->gifted_units ?? 0);
        $concept = sprintf(
            'Tienda Portal · %s × %s%s',
            (string) ($details['service_name'] ?? 'Servicio'),
            number_format($quantity, 0, '.', ','),
            $gifted > 0 ? sprintf(' (+%s de regalo)', number_format($gifted, 0, '.', ',')) : '',
        );

        return (int) Capsule::table('invoices')->insertGetId([
            'folio' => (new CollectionsService())->nextInvoiceFolio(),
            'contract_id' => (int) $contract->id,
            'client_id' => (int) $purchase->client_id,
            'segment_id' => (int) $contract->segment_id,
            'issuer_id' => (int) $contract->issuer_id,
            'request_id' => null,
            'purchase_id' => (int) $purchase->id,
            'invoice_type' => (string) ($details['service_type'] ?? '') === 'consumption' ? 'consumption' : 'one_time',
            'concept' => mb_substr($concept, 0, 255),
            'currency' => in_array((string) $purchase->currency, ['MXN', 'USD'], true) ? (string) $purchase->currency : 'MXN',
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'paid_amount' => $isPaid ? $total : '0.00',
            'balance' => $isPaid ? '0.00' : $total,
            'issue_date' => $today,
            'due_date' => $isPaid ? $today : CollectionRules::computeDueDate($today, (int) ($contract->credit_days ?? 0)),
            'status' => $status,
            'stamped_uuid' => null,
            'notes' => null,
            'created_by_user_id' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);
    }

    private static function loadContract(object $purchase): object
    {
        $contract = $purchase->contract_id !== null
            ? Capsule::table('contracts')->where('id', (int) $purchase->contract_id)->first()
            : null;
        if ($contract === null) {
            throw new RuntimeException('La compra no tiene un contrato válido para emitir el documento.');
        }

        return $contract;
    }

    /** @return array<string, mixed> */
    private static function details(object $purchase): array
    {
        $decoded = json_decode((string) ($purchase->details_json ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function todayMx(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    }
}
