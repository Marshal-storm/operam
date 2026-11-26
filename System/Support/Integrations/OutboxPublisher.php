<?php

declare(strict_types=1);

namespace System\Support\Integrations;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Support\FinancialCalculator;
use System\Support\SegmentProfitabilityCalculator;

/**
 * Traduce eventos de negocio (contratos, solicitudes, cobranza) a mensajes del outbox.
 *
 * Debe invocarse DENTRO de la transacción de negocio (patrón Transactional Outbox): si la
 * operación hace rollback, el mensaje tampoco existe; si se confirma, el worker lo entregará.
 */
final class OutboxPublisher
{
    /**
     * Ajuste de usuarios concurrentes en ERP Master (alta, expansión, contracción, RF-8.4).
     * Devuelve null si el contrato no tiene instancia asignada (nada que ajustar).
     *
     * @return array<string, mixed>|null
     */
    public static function contractUsersAdjustment(
        int $contractId,
        string $trigger,
        string $idempotencyKey,
        ?int $requestId = null,
    ): ?array {
        $contract = self::loadContract($contractId);
        if ($contract === null || $contract->instance_id === null) {
            return null;
        }

        $lines = self::activeRecurringLines($contractId);
        $concurrentUsers = 0;
        foreach ($lines as $line) {
            $concurrentUsers += $line['concurrent_users'];
        }

        return (new OutboxDispatcher())->enqueue(
            'erp_master',
            'adjust_users',
            $idempotencyKey,
            [
                'contract' => self::contractSnapshot($contract),
                'instance' => self::instanceSnapshot((int) $contract->instance_id),
                'client' => self::clientSnapshot((int) $contract->client_id),
                'concurrent_users' => $concurrentUsers,
                'lines' => $lines,
                'trigger' => $trigger,
                'request_id' => $requestId,
                'requested_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            null,
            'contract',
            (string) $contractId,
            (int) $contract->segment_id,
        );
    }

    /**
     * Suspensión / reactivación de la instancia en ERP Master.
     *
     * @return array<string, mixed>|null
     */
    public static function instanceStatusChange(
        int $contractId,
        string $operation,
        string $reason,
        string $idempotencyKey,
    ): ?array {
        $contract = self::loadContract($contractId);
        if ($contract === null || $contract->instance_id === null) {
            return null;
        }

        return (new OutboxDispatcher())->enqueue(
            'erp_master',
            $operation,
            $idempotencyKey,
            [
                'contract' => self::contractSnapshot($contract),
                'instance' => self::instanceSnapshot((int) $contract->instance_id),
                'client' => self::clientSnapshot((int) $contract->client_id),
                'reason' => mb_substr($reason, 0, 255),
                'requested_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            null,
            'contract',
            (string) $contractId,
            (int) $contract->segment_id,
        );
    }

    /**
     * Prefactura hacia el ERP del Emisor; `stamp_on_issue` indica timbrado CFDI inmediato.
     * El job `daily_billing` ancla la llave al periodo (`preinvoice:{contract_id}:{period_start}`).
     *
     * @return array<string, mixed>|null
     */
    public static function invoicePreinvoice(int $invoiceId, ?string $idempotencyKey = null): ?array
    {
        $invoice = Capsule::table('invoices')->where('id', $invoiceId)->first();
        if ($invoice === null) {
            return null;
        }
        $contract = self::loadContract((int) $invoice->contract_id);
        $issuer = Capsule::table('issuers')->where('id', (int) $invoice->issuer_id)->first(['id', 'name', 'rfc']);

        return (new OutboxDispatcher())->enqueue(
            'erp_issuer',
            'create_preinvoice',
            $idempotencyKey ?? 'create_preinvoice:invoice:' . $invoiceId,
            [
                'invoice' => [
                    'id' => (int) $invoice->id,
                    'folio' => (string) $invoice->folio,
                    'invoice_type' => (string) $invoice->invoice_type,
                    'concept' => (string) $invoice->concept,
                    'currency' => (string) $invoice->currency,
                    'subtotal' => FinancialCalculator::normalizeMoney((string) $invoice->subtotal),
                    'tax_amount' => FinancialCalculator::normalizeMoney((string) $invoice->tax_amount),
                    'total' => FinancialCalculator::normalizeMoney((string) $invoice->total),
                    'issue_date' => (string) $invoice->issue_date,
                    'due_date' => (string) $invoice->due_date,
                    'period_start' => isset($invoice->period_start) && $invoice->period_start !== null ? (string) $invoice->period_start : null,
                    'period_end' => isset($invoice->period_end) && $invoice->period_end !== null ? (string) $invoice->period_end : null,
                    'request_id' => $invoice->request_id !== null ? (int) $invoice->request_id : null,
                ],
                'stamp_on_issue' => $contract !== null && (bool) $contract->stamp_on_issue,
                'contract' => $contract !== null ? self::contractSnapshot($contract) : null,
                'client' => self::clientSnapshot((int) $invoice->client_id),
                'issuer' => $issuer !== null
                    ? ['id' => (int) $issuer->id, 'name' => (string) $issuer->name, 'rfc' => (string) $issuer->rfc]
                    : null,
                'requested_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            (int) $invoice->issuer_id,
            'invoice',
            (string) $invoiceId,
            (int) $invoice->segment_id,
        );
    }

    /** Último movimiento del contrato: ancla la idempotencia de ajustes por operación. */
    public static function latestMovementId(int $contractId): int
    {
        return (int) (Capsule::table('movements')->where('contract_id', $contractId)->max('id') ?? 0);
    }

    private static function loadContract(int $contractId): ?object
    {
        return Capsule::table('contracts')->where('id', $contractId)->first([
            'id',
            'folio',
            'client_id',
            'segment_id',
            'issuer_id',
            'instance_id',
            'status',
            'stamp_on_issue',
            'mrr_mxn',
        ]);
    }

    /** @return array<string, mixed> */
    private static function contractSnapshot(object $contract): array
    {
        return [
            'id' => (int) $contract->id,
            'folio' => (string) $contract->folio,
            'status' => (string) $contract->status,
            'mrr_mxn' => FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function instanceSnapshot(int $instanceId): ?array
    {
        $instance = Capsule::table('instances')->where('id', $instanceId)->first(['id', 'code', 'name', 'access_url']);

        return $instance !== null
            ? [
                'id' => (int) $instance->id,
                'code' => (string) $instance->code,
                'name' => (string) $instance->name,
                'access_url' => $instance->access_url !== null ? (string) $instance->access_url : null,
            ]
            : null;
    }

    /** @return array<string, mixed>|null */
    private static function clientSnapshot(int $clientId): ?array
    {
        $client = Capsule::table('clients')->where('id', $clientId)->first(['id', 'name', 'rfc', 'erp_client_id']);

        return $client !== null
            ? [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'rfc' => $client->rfc !== null ? (string) $client->rfc : null,
                'erp_client_id' => $client->erp_client_id !== null ? (string) $client->erp_client_id : null,
            ]
            : null;
    }

    /**
     * Líneas recurrentes activas con usuarios concurrentes equivalentes (paquetes × factor).
     *
     * @return list<array<string, mixed>>
     */
    private static function activeRecurringLines(int $contractId): array
    {
        $rows = Capsule::table('contract_lines as cl')
            ->leftJoin('services as s', 's.id', '=', 'cl.service_id')
            ->where('cl.contract_id', $contractId)
            ->where('cl.active', 1)
            ->where('cl.service_type', 'recurring')
            ->orderBy('cl.id')
            ->get([
                'cl.service_id',
                'cl.quantity',
                'cl.is_package_converted',
                's.code',
                's.name',
                's.unit_of_measure',
                's.package_factor',
            ]);

        $lines = [];
        foreach ($rows as $row) {
            $unit = (string) ($row->unit_of_measure ?? '');
            $isPackage = (bool) $row->is_package_converted || $unit === 'pack_5_users';
            $factor = (int) ($row->package_factor ?? 0);
            if ($isPackage && $factor < 2 && $row->service_id !== null) {
                // El factor vive en el servicio individual que apunta al paquete.
                $factor = (int) (Capsule::table('services')
                    ->where('package_service_id', (int) $row->service_id)
                    ->max('package_factor') ?? 0);
            }
            $isUserUnit = $unit === 'user' || $isPackage;

            $lines[] = [
                'service_id' => $row->service_id !== null ? (int) $row->service_id : null,
                'service_code' => $row->code !== null ? (string) $row->code : null,
                'service_name' => $row->name !== null ? (string) $row->name : null,
                'unit_of_measure' => $unit !== '' ? $unit : null,
                'quantity' => (int) $row->quantity,
                'is_package' => $isPackage,
                'concurrent_users' => $isUserUnit
                    ? SegmentProfitabilityCalculator::concurrentUsersFromLine((int) $row->quantity, $isPackage, $factor)
                    : 0,
            ];
        }

        return $lines;
    }
}
