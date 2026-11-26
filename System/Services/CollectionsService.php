<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\Result;
use System\Support\AuditLogger;
use System\Support\CollectionRules;
use System\Support\ContractLifecycleEngine;
use System\Support\ContractPricingEngine;
use System\Support\FinancialCalculator;
use System\Support\Integrations\OutboxPublisher;
use System\Support\Jobs\BillingCycleRules;
use System\Support\ServiceRequestRules;
use Throwable;

/**
 * Cobranza — `/api/collections` (Módulo 4 Paso 2).
 * Documentos, pagos, antigüedad, recalculo de `collection_statuses` y suspensión +90d.
 * RBAC `collections` + alcance de segmento + BCMath + audit + movements append-only.
 */
final class CollectionsService extends CustomService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    private const MODULE_KEY = 'collections';

    private const INVOICE_STATUSES = ['pending', 'partial', 'paid', 'overdue', 'cancelled'];

    private const PAYMENT_METHODS = ['transfer', 'card_integropay', 'cash', 'check', 'other'];

    private const AGING_BUCKETS = [
        'upcoming',
        'days_1_30',
        'days_31_60',
        'days_61_90',
        'suspended_90_plus',
    ];

    private const MONTH_NAMES_ES = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /** @var array<string, mixed> */
    private array $authContext = [];

    /**
     * Contexto de auditoría para invocaciones fuera de HTTP (jobs de GCP / CLI).
     *
     * @param array<string, mixed> $authContext
     */
    public function withAuthContext(array $authContext): self
    {
        $this->authContext = $authContext;

        return $this;
    }

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $request = RbacAuthHelper::applySegmentFilterToQuery(
            $this->authContext,
            $context->getRequest(),
        );

        $uri = strtolower(trim($context->getUri(), '/'));
        $parts = $uri === '' ? [] : explode('/', $uri);
        // parts[0] = collections | cobranza
        $section = isset($parts[1]) ? strtolower((string) $parts[1]) : null;
        $sectionId = isset($parts[2]) && ctype_digit((string) $parts[2]) ? (int) $parts[2] : null;
        $action = isset($parts[3]) ? strtolower((string) $parts[3]) : null;

        // POST /api/collections/run-suspension-check
        if ($method === 'POST' && $section === 'run-suspension-check') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para evaluar suspensiones.', 403);
            }

            return $this->runSuspensionCheck($request);
        }

        // GET /api/collections/contracts
        if ($method === 'GET' && $section === 'contracts') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                return Result::fail('Sin permiso para consultar cobranza.', 403);
            }

            return Result::ok($this->listContractsPortfolio($request), 'Cartera por contrato');
        }

        // POST /api/collections/payments/{id}/reverse
        if ($method === 'POST' && $section === 'payments' && $sectionId !== null && $action === 'reverse') {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                return Result::fail('Sin permiso para revertir pagos.', 403);
            }

            return $this->reversePayment($sectionId, $request);
        }

        // Rutas de invoices
        if ($section === 'invoices') {
            // POST /api/collections/invoices/from-request/{requestId}
            $fromRequestToken = isset($parts[2]) ? strtolower((string) $parts[2]) : null;
            $fromRequestId = isset($parts[3]) && ctype_digit((string) $parts[3])
                ? (int) $parts[3]
                : null;
            if ($method === 'POST' && $fromRequestToken === 'from-request' && $fromRequestId !== null) {
                if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                    return Result::fail('Sin permiso para emitir documentos.', 403);
                }

                return $this->issueInvoiceFromRequestEndpoint($fromRequestId);
            }

            if ($method === 'GET' && $sectionId === null) {
                if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                    return Result::fail('Sin permiso para consultar cobranza.', 403);
                }

                return Result::ok($this->listInvoicesPaginated($request), 'Documentos de cobranza');
            }

            if ($method === 'GET' && $sectionId !== null) {
                if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                    return Result::fail('Sin permiso para consultar cobranza.', 403);
                }
                $detail = $this->findInvoiceDetail($sectionId);
                if ($detail === null) {
                    return Result::fail('Documento no encontrado.', 404);
                }
                if (!$this->isWithinSegmentScope((int) $detail['segment_id'])) {
                    return Result::fail('Documento fuera de tu alcance de segmento.', 403);
                }

                return Result::ok($detail, 'Documento de cobranza');
            }

            if ($method === 'POST' && $sectionId === null) {
                if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'create')) {
                    return Result::fail('Sin permiso para emitir documentos.', 403);
                }

                return $this->issueInvoice($request);
            }

            if ($method === 'POST' && $sectionId !== null && $action === 'payments') {
                if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
                    return Result::fail('Sin permiso para registrar pagos.', 403);
                }

                return $this->recordPayment($sectionId, $request);
            }

            if ($method === 'POST' && $sectionId !== null && $action === 'cancel') {
                if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'delete')) {
                    return Result::fail('Sin permiso para cancelar documentos.', 403);
                }

                return $this->cancelInvoice($sectionId, $request);
            }
        }

        // GET /api/collections → redirige semántica a listado de facturas
        if ($method === 'GET' && ($section === null || $section === '')) {
            if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
                return Result::fail('Sin permiso para consultar cobranza.', 403);
            }

            return Result::ok($this->listInvoicesPaginated($request), 'Documentos de cobranza');
        }

        return Result::fail('Ruta de cobranza no soportada.', 404);
    }

    /**
     * Recalcula `collection_statuses` del contrato (BCMath + America/Mexico_City).
     * Público para puente desde solicitudes, jobs y CLI.
     *
     * Si el contrato está suspendido y ya no adeuda licencias/recurrentes con 90+ días,
     * lo reactiva en la misma transacción (RF-6.3). `$paymentId` ancla la llave idempotente
     * del outbox cuando el recálculo proviene de un pago.
     *
     * @return array{
     *   overdue_balance: string,
     *   days_overdue: int,
     *   notice_level: int,
     *   notice_code: string,
     *   warning_level: int,
     *   license_overdue_days: int,
     *   is_suspended: bool,
     *   reactivated: bool
     * }
     */
    public function recalculateContractCollectionStatus(int $contractId, ?int $paymentId = null): array
    {
        $today = $this->todayInMexicoCity();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $invoices = $this->contractInvoicesForRules($contractId);

        // Marca overdue las pending/partial vencidas.
        $computed = CollectionRules::computeCollectionStatusFromInvoices($invoices, $today);
        foreach ($computed['mark_overdue_ids'] as $invoiceId) {
            Capsule::table('invoices')->where('id', $invoiceId)->update([
                'status' => 'overdue',
                'updated_at' => $nowUtc,
            ]);
        }

        // También recalcula status de abiertas no marcadas (p. ej. pago parcial al corriente).
        foreach ($invoices as $inv) {
            if (in_array($inv['status'], ['paid', 'cancelled'], true)) {
                continue;
            }
            if (in_array($inv['id'], $computed['mark_overdue_ids'], true)) {
                continue;
            }
            $newStatus = CollectionRules::resolveOpenInvoiceStatus(
                $inv['paid_amount'],
                $inv['balance'],
                $inv['due_date'],
                $today,
            );
            if ($newStatus !== $inv['status']) {
                Capsule::table('invoices')->where('id', $inv['id'])->update([
                    'status' => $newStatus,
                    'updated_at' => $nowUtc,
                ]);
            }
        }

        $contract = Capsule::table('contracts')->where('id', $contractId)->first(['status']);
        $alreadySuspended = $contract !== null && (string) $contract->status === 'suspended';
        $existing = Capsule::table('collection_statuses')->where('contract_id', $contractId)->first();
        $isSuspended = $alreadySuspended || ($existing !== null && (bool) $existing->is_suspended);

        $payload = [
            'overdue_balance' => $computed['overdue_balance'],
            'overdue_days' => $computed['days_overdue'],
            'notice_level' => $computed['notice_level'],
            'is_suspended' => $isSuspended ? 1 : 0,
            'updated_at' => $nowUtc,
        ];

        if ($existing === null) {
            Capsule::table('collection_statuses')->insert(array_merge(
                ['contract_id' => $contractId],
                $payload,
            ));
        } else {
            Capsule::table('collection_statuses')->where('contract_id', $contractId)->update($payload);
        }

        $licenseOverdueDays = CollectionRules::licenseOverdueDays($invoices, $today);
        $reactivated = false;
        if ($alreadySuspended && CollectionRules::canAutoReactivate($invoices, $today)) {
            $reactivated = $this->autoReactivateContract($contractId, $paymentId, $today);
        }

        return [
            'overdue_balance' => $computed['overdue_balance'],
            'days_overdue' => $computed['days_overdue'],
            'notice_level' => $computed['notice_level'],
            'notice_code' => $computed['notice_code'],
            'warning_level' => CollectionRules::warningLevelFromDays($computed['days_overdue']),
            'license_overdue_days' => $licenseOverdueDays,
            'is_suspended' => $isSuspended && !$reactivated,
            'reactivated' => $reactivated,
        ];
    }

    /**
     * Job `daily_collections` (RF-6.1 a RF-6.3): recalcula cartera de contratos activos y
     * suspendidos (avisos 30/60/90 + reactivaciones) y después suspende los elegibles a +90 días.
     *
     * @return array<string, mixed>
     */
    public function runDailyCollectionsCycle(): array
    {
        $contractIds = Capsule::table('contracts')
            ->whereIn('status', ['active', 'suspended'])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $warningLevels = ['0' => 0, '30' => 0, '60' => 0, '90' => 0];
        $reactivatedFolios = [];
        foreach ($contractIds as $contractId) {
            $status = $this->recalculateContractCollectionStatus($contractId);
            $warningLevels[(string) $status['warning_level']]++;
            if ($status['reactivated']) {
                $reactivatedFolios[] = (string) Capsule::table('contracts')->where('id', $contractId)->value('folio');
            }
        }

        $suspension = $this->suspendEligibleContracts(null);

        return [
            'evaluated_contracts' => count($contractIds),
            'warning_levels' => $warningLevels,
            'reactivated_count' => count($reactivatedFolios),
            'reactivated_folios' => $reactivatedFolios,
            'suspension_candidates' => $suspension['evaluated_count'],
            'suspended_count' => $suspension['suspended_count'],
            'excluded_count' => $suspension['excluded_count'],
            'suspended_folios' => $suspension['suspended_folios'],
            'as_of' => $suspension['as_of'],
        ];
    }

    /**
     * Facturas no canceladas del contrato en el formato que consumen CollectionRules.
     *
     * @return list<array{id: int, invoice_type: string, balance: string, due_date: string, status: string, paid_amount: string}>
     */
    private function contractInvoicesForRules(int $contractId): array
    {
        return Capsule::table('invoices')
            ->where('contract_id', $contractId)
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'invoice_type', 'balance', 'due_date', 'status', 'paid_amount'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'invoice_type' => (string) $row->invoice_type,
                'balance' => (string) $row->balance,
                'due_date' => (string) $row->due_date,
                'status' => (string) $row->status,
                'paid_amount' => (string) $row->paid_amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Reactivación automática RF-6.3 (Págs. 6 y 39): restaura las líneas que apagó la suspensión,
     * inserta el movimiento inmutable `reactivation`, sincroniza `monthly_mrr`, encola
     * `reactivate_instance` hacia ERP Master y audita. Todo en una transacción (anidable).
     */
    private function autoReactivateContract(int $contractId, ?int $paymentId, string $today): bool
    {
        $reason = 'Reactivación automática por liquidación de saldo vencido >90 días';

        return Capsule::connection()->transaction(function () use ($contractId, $paymentId, $today, $reason): bool {
            $nowUtc = gmdate('Y-m-d H:i:s');
            $contract = Capsule::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
            if ($contract === null || (string) $contract->status !== 'suspended') {
                return false;
            }

            // Solo se encienden las líneas recurrentes que apagó la suspensión (misma marca UTC que
            // el movimiento churn); las bajas previas por contracción permanecen inactivas.
            $suspensionMovement = Capsule::table('movements')
                ->where('contract_id', $contractId)
                ->where('mrr_category', 'churn')
                ->orderByDesc('id')
                ->first(['id', 'created_at']);
            $linesQuery = Capsule::table('contract_lines')
                ->where('contract_id', $contractId)
                ->where('service_type', 'recurring')
                ->where('active', 0)
                ->whereRaw('subtotal > 0');
            if ($suspensionMovement !== null) {
                $linesQuery->where('updated_at', '>=', (string) $suspensionMovement->created_at);
            }

            $exchangeRate = (string) $contract->exchange_rate;
            $contractMrr = '0.00';
            foreach ($linesQuery->lockForUpdate()->get() as $line) {
                $lineMrr = ContractPricingEngine::lineMrrMxn(
                    'recurring',
                    (string) $line->billing_frequency,
                    (string) $line->subtotal,
                    $exchangeRate,
                );
                Capsule::table('contract_lines')->where('id', (int) $line->id)->update([
                    'active' => 1,
                    'mrr_mxn' => $lineMrr,
                    'updated_at' => $nowUtc,
                ]);
                $contractMrr = bcadd($contractMrr, $lineMrr, 2);
            }

            // Los periodos en suspensión no se facturan: el siguiente corte salta al próximo aniversario.
            $nextCutoff = $contract->next_cutoff_date !== null ? (string) $contract->next_cutoff_date : null;
            $frequency = (string) $contract->frequency;
            if ($nextCutoff !== null && $nextCutoff < $today && BillingCycleRules::isRecurringFrequency($frequency)) {
                $nextCutoff = BillingCycleRules::rollForwardTo(
                    $nextCutoff,
                    $today,
                    $frequency,
                    BillingCycleRules::anniversaryDay((string) $contract->anniversary_date),
                );
            }

            Capsule::table('contracts')->where('id', $contractId)->update([
                'status' => 'active',
                'termination_reason' => null,
                'mrr_mxn' => $contractMrr,
                'next_cutoff_date' => $nextCutoff,
                'updated_at' => $nowUtc,
            ]);
            Capsule::table('collection_statuses')->where('contract_id', $contractId)->update([
                'is_suspended' => 0,
                'updated_at' => $nowUtc,
            ]);

            $impact = ContractLifecycleEngine::reactivationImpact($contractMrr);
            $userId = (int) ($this->authContext['user_id'] ?? 0);
            (new MovementsService())->recordWithinTransaction([
                'contract_id' => $contractId,
                'contract_line_id' => null,
                'service_id' => null,
                'movement_type' => 'reactivation',
                'mrr_category' => 'reactivation',
                'effective_date' => $today,
                'quantity_before' => '0.00',
                'quantity_after' => '1.00',
                'mrr_before' => '0.00',
                'mrr_after' => $impact['mrr_after'],
                'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
                'exchange_rate' => $this->normalizeExchangeRate($exchangeRate),
                'user_id' => $userId > 0 ? $userId : null,
                'request_id' => null,
                'reason' => $reason,
                'has_exception' => 0,
                'excepted_policy' => null,
                'reversed_movement_id' => null,
                'created_at' => $nowUtc,
            ]);

            OutboxPublisher::instanceStatusChange(
                $contractId,
                'reactivate_instance',
                $reason,
                $paymentId !== null
                    ? sprintf('reactivate:%d:%d', $contractId, $paymentId)
                    : sprintf('reactivate:%d:movement:%d', $contractId, OutboxPublisher::latestMovementId($contractId)),
            );

            AuditLogger::log(
                $this->authContext,
                self::MODULE_KEY,
                'contracts',
                (string) $contractId,
                'status_change',
                $reason . ' — ' . (string) $contract->folio,
                ['status' => 'suspended', 'mrr_mxn' => FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn)],
                [
                    'status' => 'active',
                    'mrr_mxn' => $contractMrr,
                    'payment_id' => $paymentId,
                    'next_cutoff_date' => $nextCutoff,
                ],
            );

            return true;
        });
    }

    /**
     * Emite factura desde solicitud aprobada (one_time / consumption / tramo no recurrente).
     * Debe invocarse dentro de la misma transacción de aprobación.
     *
     * @return array<string, mixed>|null Factura creada o null si no hay cargos facturables.
     */
    public function issueInvoiceFromApprovedRequest(object $request, int $userId, string $nowUtc): ?array
    {
        $items = Capsule::table('service_request_items')
            ->where('service_request_id', (int) $request->id)
            ->orderBy('id')
            ->get();

        $partition = ServiceRequestRules::partitionMixedRequestLines(
            $items->map(static fn (object $item): array => [
                'service_type' => (string) $item->service_type,
                'subtotal' => (string) $item->subtotal,
                'mrr_impact_mxn' => (string) $item->mrr_impact_mxn,
            ])->all(),
        );

        $billable = FinancialCalculator::normalizeMoney($partition['non_recurring_subtotal']);
        if (bccomp($billable, '0.00', 2) <= 0) {
            // Solicitud pura one_time/consumption: usar subtotal de la solicitud.
            $requestType = (string) $request->request_type;
            if (in_array($requestType, ['one_time', 'consumption'], true)) {
                $billable = FinancialCalculator::normalizeMoney((string) $request->subtotal);
            }
        }

        if (bccomp($billable, '0.00', 2) <= 0) {
            return null;
        }

        $contract = Capsule::table('contracts')->where('id', (int) $request->contract_id)->first();
        if ($contract === null) {
            throw new \RuntimeException('Contrato no encontrado para facturar la solicitud.');
        }

        $today = $this->todayInMexicoCity();
        $creditDays = (int) ($contract->credit_days ?? 0);
        $dueDate = CollectionRules::computeDueDate($today, $creditDays);
        // IVA 16% automático (MRR permanece sobre subtotal sin impuestos).
        $amounts = CollectionRules::buildInvoiceAmounts($billable);

        $invoiceType = match ((string) $request->request_type) {
            'consumption' => 'consumption',
            'one_time' => 'one_time',
            default => $partition['non_recurring'] !== [] && $partition['recurring'] !== []
                ? 'mixed'
                : 'one_time',
        };

        $concept = sprintf(
            'Solicitud %s · %s',
            (string) $request->folio,
            mb_substr(trim((string) ($request->notes ?? 'Cargo aprobado')), 0, 180),
        );

        $folio = $this->nextInvoiceFolio();
        $id = (int) Capsule::table('invoices')->insertGetId([
            'folio' => $folio,
            'contract_id' => (int) $request->contract_id,
            'client_id' => (int) $request->client_id,
            'segment_id' => (int) $request->segment_id,
            'issuer_id' => (int) $request->issuer_id,
            'request_id' => (int) $request->id,
            'invoice_type' => $invoiceType,
            'concept' => mb_substr($concept, 0, 255),
            'currency' => in_array((string) $request->currency, ['MXN', 'USD'], true)
                ? (string) $request->currency
                : 'MXN',
            'subtotal' => $amounts['subtotal'],
            'tax_amount' => $amounts['tax_amount'],
            'total' => $amounts['total'],
            'paid_amount' => $amounts['paid_amount'],
            'balance' => $amounts['balance'],
            'issue_date' => $today,
            'due_date' => $dueDate,
            'status' => 'pending',
            'stamped_uuid' => null,
            'notes' => $request->resolution_notes ?? null,
            'created_by_user_id' => $userId > 0 ? $userId : null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        // Prefactura al ERP del Emisor (outbox en la misma transacción de aprobación).
        OutboxPublisher::invoicePreinvoice($id);

        $this->recalculateContractCollectionStatus((int) $request->contract_id);

        return [
            'id' => $id,
            'folio' => $folio,
            'total' => $amounts['total'],
            'balance' => $amounts['balance'],
            'due_date' => $dueDate,
            'request_id' => (int) $request->id,
        ];
    }

    /**
     * POST /api/collections/invoices/from-request/{requestId}
     * Genera FAC-XXXX para solicitud aprobada sin factura activa.
     */
    private function issueInvoiceFromRequestEndpoint(int $requestId): Result
    {
        $requestRow = Capsule::table('service_requests')->where('id', $requestId)->first();
        if ($requestRow === null) {
            return Result::fail('Solicitud no encontrada.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $requestRow->segment_id)) {
            return Result::fail('Solicitud fuera de tu alcance de segmento.', 403);
        }
        if ((string) $requestRow->status !== 'approved') {
            return Result::fail('Solo se pueden facturar solicitudes aprobadas.', 422);
        }
        if (!in_array((string) $requestRow->request_type, ['one_time', 'consumption'], true)) {
            // Mixtas: aún se permite si hay tramo no recurrente (issueInvoiceFromApprovedRequest filtra).
            if ((string) $requestRow->request_type !== 'recurring_expansion') {
                return Result::fail('Tipo de solicitud no facturable en cobranza.', 422);
            }
        }

        $existing = Capsule::table('invoices')
            ->where('request_id', $requestId)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->first(['id', 'folio']);
        if ($existing !== null) {
            return Result::fail(
                'La solicitud ya tiene el documento ' . (string) $existing->folio . ' en cobranza.',
                422,
            );
        }

        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $nowUtc = gmdate('Y-m-d H:i:s');

        try {
            $created = Capsule::connection()->transaction(function () use ($requestRow, $userId, $nowUtc): ?array {
                return $this->issueInvoiceFromApprovedRequest($requestRow, $userId, $nowUtc);
            });
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        if ($created === null) {
            return Result::fail('La solicitud no tiene cargos facturables (one_time/consumption).', 422);
        }

        $detail = $this->findInvoiceDetail((int) $created['id']) ?? $created;
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'invoices',
            (string) $created['id'],
            'create',
            'Documento ' . (string) $created['folio'] . ' generado desde solicitud ' . (string) $requestRow->folio,
            null,
            [
                'folio' => $created['folio'],
                'request_id' => $requestId,
                'request_folio' => (string) $requestRow->folio,
                'total' => $created['total'] ?? null,
            ],
        );

        return Result::ok($detail, 'Documento emitido desde solicitud', 201);
    }

    /**
     * POST /api/collections/payments/{id}/reverse — marca is_reversed (sin DELETE).
     *
     * @param array<string, mixed> $payload
     */
    private function reversePayment(int $paymentId, array $payload): Result
    {
        $reason = trim((string) ($payload['reason'] ?? $payload['reversal_reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            return Result::fail('El motivo de reversión es obligatorio (mínimo 5 caracteres).', 422);
        }

        $payment = Capsule::table('payments')->where('id', $paymentId)->first();
        if ($payment === null) {
            return Result::fail('Pago no encontrado.', 404);
        }
        if ((bool) ($payment->is_reversed ?? false)) {
            return Result::fail('Este pago ya fue revertido.', 422);
        }

        $invoice = Capsule::table('invoices')->where('id', (int) $payment->invoice_id)->first();
        if ($invoice === null) {
            return Result::fail('Documento asociado no encontrado.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $invoice->segment_id)) {
            return Result::fail('Documento fuera de tu alcance de segmento.', 403);
        }
        if ((string) $invoice->status === 'cancelled') {
            return Result::fail('No se pueden revertir pagos de un documento cancelado.', 422);
        }

        $today = $this->todayInMexicoCity();
        $nowUtc = gmdate('Y-m-d H:i:s');
        $userId = (int) ($this->authContext['user_id'] ?? 0);

        try {
            $applied = CollectionRules::reversePayment(
                (string) $invoice->total,
                (string) $invoice->paid_amount,
                (string) $payment->amount,
                (string) $invoice->due_date,
                $today,
            );
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        try {
            Capsule::connection()->transaction(function () use (
                $payment,
                $paymentId,
                $invoice,
                $applied,
                $reason,
                $userId,
                $nowUtc,
            ): void {
                Capsule::table('payments')->where('id', $paymentId)->update([
                    'is_reversed' => 1,
                    'reversed_at' => $nowUtc,
                    'reversal_reason' => mb_substr($reason, 0, 255),
                    'reversed_by_user_id' => $userId > 0 ? $userId : null,
                    'updated_at' => $nowUtc,
                ]);

                Capsule::table('invoices')->where('id', (int) $invoice->id)->update([
                    'paid_amount' => $applied['paid_amount'],
                    'balance' => $applied['balance'],
                    'status' => $applied['status'],
                    'updated_at' => $nowUtc,
                ]);

                $this->recalculateContractCollectionStatus((int) $invoice->contract_id);
            });
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        $detail = $this->findInvoiceDetail((int) $invoice->id);
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'payments',
            (string) $paymentId,
            'status_change',
            'Pago revertido en ' . (string) $invoice->folio . ' por $'
                . FinancialCalculator::normalizeMoney((string) $payment->amount),
            [
                'is_reversed' => false,
                'paid_amount' => FinancialCalculator::normalizeMoney((string) $invoice->paid_amount),
                'balance' => FinancialCalculator::normalizeMoney((string) $invoice->balance),
                'status' => (string) $invoice->status,
            ],
            [
                'is_reversed' => true,
                'reversal_reason' => $reason,
                'paid_amount' => $applied['paid_amount'],
                'balance' => $applied['balance'],
                'status' => $applied['status'],
            ],
        );

        return Result::ok($detail, 'Pago revertido');
    }

    /**
     * Regla oficial de suspensión automática a +90 días.
     * Invocable desde HTTP o CLI nocturno.
     *
     * @param array<string, mixed> $query
     * @return Result
     */
    public function runSuspensionCheck(array $query = []): Result
    {
        $segmentId = $this->resolveSegmentFilter($query);

        // Primero recalcula estados de contratos activos con facturas.
        $contractIdsQuery = Capsule::table('contracts as c')
            ->where('c.status', 'active');
        if ($segmentId !== null) {
            $contractIdsQuery->where('c.segment_id', $segmentId);
        }
        $activeIds = $contractIdsQuery->pluck('c.id')->all();
        foreach ($activeIds as $cid) {
            $this->recalculateContractCollectionStatus((int) $cid);
        }

        return Result::ok($this->suspendEligibleContracts($segmentId), 'Evaluación de suspensiones completada');
    }

    /**
     * Suspende contratos activos con licencias/recurrentes a 90+ días (cartera ya recalculada).
     * Las compras sueltas vencidas no suspenden (mismo criterio que la reactivación RF-6.3).
     *
     * @return array{evaluated_count: int, suspended_count: int, excluded_count: int, suspended_folios: list<string>, as_of: string}
     */
    private function suspendEligibleContracts(?int $segmentId): array
    {
        $today = $this->todayInMexicoCity();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $builder = Capsule::table('collection_statuses as cs')
            ->join('contracts as c', 'c.id', '=', 'cs.contract_id')
            ->join('clients as cl', 'cl.id', '=', 'c.client_id')
            ->where('c.status', 'active')
            ->where('cs.overdue_days', '>=', 90)
            ->whereRaw('cs.overdue_balance > 0');
        if ($segmentId !== null) {
            $builder->where('c.segment_id', $segmentId);
        }

        $candidates = $builder->get([
            'c.id as contract_id',
            'c.folio as contract_folio',
            'c.mrr_mxn',
            'c.exchange_rate',
            'c.segment_id',
            'cs.overdue_balance',
            'cs.overdue_days',
            'cl.exclude_suspension',
        ]);

        $evaluated = $candidates->count();
        $suspended = 0;
        $excluded = 0;
        $suspendedFolios = [];

        foreach ($candidates as $row) {
            $exclude = (bool) $row->exclude_suspension;
            $eligible = CollectionRules::isEligibleForSuspension(
                'active',
                (int) $row->overdue_days,
                (string) $row->overdue_balance,
                $exclude,
            );
            $licenseDays = CollectionRules::licenseOverdueDays(
                $this->contractInvoicesForRules((int) $row->contract_id),
                $today,
            );
            if (!$eligible || $licenseDays < CollectionRules::SUSPENSION_DAYS) {
                $excluded++;
                continue;
            }

            try {
                Capsule::connection()->transaction(function () use ($row, $today, $nowUtc, &$suspended, &$suspendedFolios): void {
                    $contractId = (int) $row->contract_id;
                    $locked = Capsule::table('contracts')->where('id', $contractId)->lockForUpdate()->first();
                    if ($locked === null || (string) $locked->status !== 'active') {
                        return;
                    }

                    $impact = ContractLifecycleEngine::churnImpact((string) $locked->mrr_mxn);

                    Capsule::table('contract_lines')
                        ->where('contract_id', $contractId)
                        ->where('active', 1)
                        ->update([
                            'active' => 0,
                            'mrr_mxn' => '0.00',
                            'updated_at' => $nowUtc,
                        ]);

                    Capsule::table('contracts')->where('id', $contractId)->update([
                        'status' => 'suspended',
                        'mrr_mxn' => '0.00',
                        'termination_reason' => 'Suspensión automática por morosidad (+90 días vencidos)',
                        'updated_at' => $nowUtc,
                    ]);

                    Capsule::table('collection_statuses')->updateOrInsert(
                        ['contract_id' => $contractId],
                        [
                            'overdue_balance' => FinancialCalculator::normalizeMoney((string) $row->overdue_balance),
                            'overdue_days' => (int) $row->overdue_days,
                            'notice_level' => CollectionRules::NOTICE_SUSPENSION,
                            'is_suspended' => 1,
                            'updated_at' => $nowUtc,
                        ],
                    );

                    $userId = (int) ($this->authContext['user_id'] ?? 0);
                    (new MovementsService())->recordWithinTransaction([
                        'contract_id' => $contractId,
                        'contract_line_id' => null,
                        'service_id' => null,
                        'movement_type' => 'churn',
                        'mrr_category' => 'churn',
                        'effective_date' => $today,
                        'quantity_before' => '1.00',
                        'quantity_after' => '0.00',
                        'mrr_before' => $impact['mrr_before'],
                        'mrr_after' => '0.00',
                        'mrr_delta_mxn' => $impact['mrr_delta_mxn'],
                        'exchange_rate' => $this->normalizeExchangeRate((string) $locked->exchange_rate),
                        'user_id' => $userId > 0 ? $userId : null,
                        'request_id' => null,
                        'reason' => 'Suspensión automática por morosidad (+90 días vencidos)',
                        'has_exception' => 0,
                        'excepted_policy' => null,
                        'reversed_movement_id' => null,
                        'created_at' => $nowUtc,
                    ]);

                    // ERP Master suspende la instancia del cliente (outbox transaccional).
                    OutboxPublisher::instanceStatusChange(
                        $contractId,
                        'suspend_instance',
                        'Suspensión automática por morosidad (+90 días vencidos)',
                        sprintf('suspend_instance:contract:%d:movement:%d', $contractId, OutboxPublisher::latestMovementId($contractId)),
                    );

                    AuditLogger::log(
                        $this->authContext,
                        self::MODULE_KEY,
                        'contracts',
                        (string) $contractId,
                        'status_change',
                        'Suspensión automática por morosidad (+90 días) — ' . (string) $row->contract_folio,
                        [
                            'status' => 'active',
                            'mrr_mxn' => $impact['mrr_before'],
                        ],
                        [
                            'status' => 'suspended',
                            'mrr_mxn' => '0.00',
                            'overdue_days' => (int) $row->overdue_days,
                            'overdue_balance' => FinancialCalculator::normalizeMoney((string) $row->overdue_balance),
                        ],
                    );

                    $suspended++;
                    $suspendedFolios[] = (string) $row->contract_folio;
                });
            } catch (Throwable $e) {
                // Continúa con el siguiente contrato; no aborta el lote completo.
                continue;
            }
        }

        return [
            'evaluated_count' => $evaluated,
            'suspended_count' => $suspended,
            'excluded_count' => $excluded,
            'suspended_folios' => $suspendedFolios,
            'as_of' => $today,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function listInvoicesPaginated(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $today = $this->todayInMexicoCity();
        $builder = $this->baseInvoiceQuery();
        $this->applyInvoiceFilters($builder, $query, $today);

        $total = (int) (clone $builder)->count('i.id');
        $rows = $builder
            ->orderByRaw('COALESCE(i.updated_at, i.created_at) DESC, i.id DESC')
            ->forPage($page, $perPage)
            ->get($this->invoiceSelectColumns());

        $items = $rows->map(fn (object $row): array => $this->mapInvoiceListRow($row, $today))->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil(max(1, $total) / $perPage)),
            'kpis' => $this->buildKpis($query, $today),
            'aging' => $this->buildAgingBuckets($query, $today),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function listContractsPortfolio(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }
        $today = $this->todayInMexicoCity();

        $builder = Capsule::table('contracts as c')
            ->join('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('collection_statuses as cs', 'cs.contract_id', '=', 'c.id')
            ->leftJoin('segments as seg', 'seg.id', '=', 'c.segment_id')
            ->whereIn('c.status', ['active', 'suspended']);

        $segmentId = $this->resolveSegmentFilter($query);
        if ($segmentId !== null) {
            $builder->where('c.segment_id', $segmentId);
        }

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('c.folio', 'like', $like)
                    ->orWhere('cl.name', 'like', $like)
                    ->orWhere('cl.legal_name', 'like', $like)
                    ->orWhere('cl.rfc', 'like', $like);
            });
        }

        $total = (int) (clone $builder)->count('c.id');
        $rows = $builder
            ->orderByRaw('COALESCE(c.updated_at, c.created_at) DESC, c.id DESC')
            ->forPage($page, $perPage)
            ->get([
                'c.id',
                'c.folio',
                'c.status',
                'c.mrr_mxn',
                'c.segment_id',
                'c.client_id',
                'cl.name as client_name',
                'cl.exclude_suspension',
                'seg.name as segment_name',
                'cs.overdue_balance',
                'cs.overdue_days',
                'cs.notice_level',
                'cs.is_suspended',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $contractId = (int) $row->id;
            $upcoming = $this->sumUpcomingBalanceForContract($contractId, $today);
            $overdue = FinancialCalculator::normalizeMoney((string) ($row->overdue_balance ?? '0'));
            $days = (int) ($row->overdue_days ?? 0);
            $noticeLevel = (int) ($row->notice_level ?? 0);
            $items[] = [
                'contract_id' => $contractId,
                'contract_folio' => (string) $row->folio,
                'contract_status' => (string) $row->status,
                'client_id' => (int) $row->client_id,
                'client_name' => (string) $row->client_name,
                'segment_id' => (int) $row->segment_id,
                'segment_name' => (string) ($row->segment_name ?? ''),
                'mrr_mxn' => FinancialCalculator::normalizeMoney((string) $row->mrr_mxn),
                'upcoming_balance' => $upcoming,
                'overdue_balance' => $overdue,
                'days_overdue' => $days,
                'notice_level' => $noticeLevel,
                'notice_code' => CollectionRules::NOTICE_CODES[$noticeLevel] ?? 'none',
                'notice_label' => CollectionRules::noticeLabel($noticeLevel),
                'exclude_suspension' => (bool) $row->exclude_suspension,
                'is_suspended' => (bool) ($row->is_suspended ?? false) || (string) $row->status === 'suspended',
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil(max(1, $total) / $perPage)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findInvoiceDetail(int $id): ?array
    {
        $today = $this->todayInMexicoCity();
        $row = $this->baseInvoiceQuery()
            ->where('i.id', $id)
            ->first($this->invoiceSelectColumns());
        if ($row === null) {
            return null;
        }

        $mapped = $this->mapInvoiceListRow($row, $today);
        $payments = Capsule::table('payments')
            ->where('invoice_id', $id)
            ->orderByRaw('COALESCE(updated_at, created_at) DESC, id DESC')
            ->get()
            ->map(static function (object $p): array {
                $isReversed = property_exists($p, 'is_reversed')
                    ? (bool) $p->is_reversed
                    : false;

                return [
                    'id' => (int) $p->id,
                    'amount' => FinancialCalculator::normalizeMoney((string) $p->amount),
                    'payment_method' => (string) $p->payment_method,
                    'reference' => $p->reference !== null ? (string) $p->reference : null,
                    'payment_date' => (string) $p->payment_date,
                    'notes' => $p->notes !== null ? (string) $p->notes : null,
                    'recorded_by_user_id' => $p->recorded_by_user_id !== null
                        ? (int) $p->recorded_by_user_id
                        : null,
                    'is_reversed' => $isReversed,
                    'reversed_at' => isset($p->reversed_at) && $p->reversed_at !== null
                        ? (string) $p->reversed_at
                        : null,
                    'reversal_reason' => isset($p->reversal_reason) && $p->reversal_reason !== null
                        ? (string) $p->reversal_reason
                        : null,
                    'created_at' => (string) $p->created_at,
                ];
            })
            ->all();

        $mapped['payments'] = $payments;
        $mapped['request_folio'] = null;
        if ($mapped['request_id'] !== null) {
            $mapped['request_folio'] = Capsule::table('service_requests')
                ->where('id', $mapped['request_id'])
                ->value('folio');
            $mapped['request_folio'] = $mapped['request_folio'] !== null
                ? (string) $mapped['request_folio']
                : null;
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function issueInvoice(array $payload): Result
    {
        $contractId = (int) ($payload['contract_id'] ?? 0);
        if ($contractId <= 0) {
            return Result::fail('Se requiere contract_id.', 422);
        }

        $contract = Capsule::table('contracts as c')
            ->join('clients as cl', 'cl.id', '=', 'c.client_id')
            ->where('c.id', $contractId)
            ->first([
                'c.*',
                'cl.name as client_name',
            ]);
        if ($contract === null) {
            return Result::fail('Contrato no encontrado.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $contract->segment_id)) {
            return Result::fail('Contrato fuera de tu alcance de segmento.', 403);
        }
        if ((string) $contract->status !== 'active') {
            return Result::fail('Solo se pueden emitir documentos para contratos activos.', 422);
        }

        $mode = (string) ($payload['mode'] ?? 'recurring_cutoff');
        $today = $this->todayInMexicoCity();
        $issueDate = trim((string) ($payload['issue_date'] ?? $today));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
            return Result::fail('issue_date inválida.', 422);
        }

        $creditDays = (int) ($contract->credit_days ?? 0);
        $dueDate = trim((string) ($payload['due_date'] ?? ''));
        if ($dueDate === '') {
            $dueDate = CollectionRules::computeDueDate($issueDate, $creditDays);
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            return Result::fail('due_date inválida.', 422);
        }

        $currency = (string) ($contract->currency ?? 'MXN');
        if (!in_array($currency, ['MXN', 'USD'], true)) {
            $currency = 'MXN';
        }

        try {
            if ($mode === 'custom') {
                $subtotal = FinancialCalculator::normalizeMoney((string) ($payload['amount'] ?? '0'));
                $concept = trim((string) ($payload['concept'] ?? ''));
                $invoiceType = (string) ($payload['invoice_type'] ?? 'one_time');
                if (!in_array($invoiceType, ['recurring', 'one_time', 'consumption', 'mixed'], true)) {
                    $invoiceType = 'one_time';
                }
                if ($concept === '') {
                    return Result::fail('El concepto es obligatorio en cargo personalizado.', 422);
                }
            } else {
                $subtotal = FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn);
                if (bccomp($subtotal, '0.00', 2) <= 0) {
                    return Result::fail('El contrato no tiene MRR para emitir el corte.', 422);
                }
                $invoiceType = 'recurring';
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $issueDate);
                $monthLabel = $dt
                    ? (self::MONTH_NAMES_ES[(int) $dt->format('n')] ?? $dt->format('m')) . ' ' . $dt->format('Y')
                    : $issueDate;
                $concept = trim((string) ($payload['concept'] ?? '')) !== ''
                    ? trim((string) $payload['concept'])
                    : sprintf('Mensualidad %s %s', $monthLabel, (string) $contract->folio);
            }

            // IVA 16% automático; se ignora tax_amount del payload para homogenizar emisión.
            $amounts = CollectionRules::buildInvoiceAmounts($subtotal);
            $userId = (int) ($this->authContext['user_id'] ?? 0);
            $nowUtc = gmdate('Y-m-d H:i:s');
            $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : null;

            $created = Capsule::connection()->transaction(function () use (
                $contract,
                $amounts,
                $concept,
                $invoiceType,
                $currency,
                $issueDate,
                $dueDate,
                $userId,
                $nowUtc,
                $notes,
                $today,
            ): array {
                $folio = $this->nextInvoiceFolio();
                $status = CollectionRules::resolveOpenInvoiceStatus(
                    '0.00',
                    $amounts['balance'],
                    $dueDate,
                    $today,
                );
                $id = (int) Capsule::table('invoices')->insertGetId([
                    'folio' => $folio,
                    'contract_id' => (int) $contract->id,
                    'client_id' => (int) $contract->client_id,
                    'segment_id' => (int) $contract->segment_id,
                    'issuer_id' => (int) $contract->issuer_id,
                    'request_id' => null,
                    'invoice_type' => $invoiceType,
                    'concept' => mb_substr($concept, 0, 255),
                    'currency' => $currency,
                    'subtotal' => $amounts['subtotal'],
                    'tax_amount' => $amounts['tax_amount'],
                    'total' => $amounts['total'],
                    'paid_amount' => $amounts['paid_amount'],
                    'balance' => $amounts['balance'],
                    'issue_date' => $issueDate,
                    'due_date' => $dueDate,
                    'status' => $status === 'overdue' ? 'overdue' : 'pending',
                    'stamped_uuid' => null,
                    'notes' => $notes !== null && $notes !== '' ? mb_substr($notes, 0, 2000) : null,
                    'created_by_user_id' => $userId > 0 ? $userId : null,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);

                // Prefactura al ERP del Emisor; timbra al emitir si el contrato tiene stamp_on_issue.
                OutboxPublisher::invoicePreinvoice($id);

                $this->recalculateContractCollectionStatus((int) $contract->id);

                return ['id' => $id, 'folio' => $folio];
            });
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        $detail = $this->findInvoiceDetail((int) $created['id']) ?? $created;
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'invoices',
            (string) $created['id'],
            'create',
            'Se emitió el documento ' . (string) $created['folio'],
            null,
            [
                'folio' => $created['folio'],
                'contract_id' => $contractId,
                'total' => $detail['total'] ?? null,
                'due_date' => $detail['due_date'] ?? $dueDate,
                'mode' => $mode,
            ],
        );

        return Result::ok($detail, 'Documento emitido', 201);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordPayment(int $invoiceId, array $payload): Result
    {
        $invoice = Capsule::table('invoices')->where('id', $invoiceId)->first();
        if ($invoice === null) {
            return Result::fail('Documento no encontrado.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $invoice->segment_id)) {
            return Result::fail('Documento fuera de tu alcance de segmento.', 403);
        }
        if ((string) $invoice->status === 'cancelled') {
            return Result::fail('No se pueden registrar pagos en un documento cancelado.', 422);
        }
        if ((string) $invoice->status === 'paid' || bccomp((string) $invoice->balance, '0.00', 2) === 0) {
            return Result::fail('El documento ya está liquidado.', 422);
        }

        try {
            $amount = FinancialCalculator::normalizeMoney((string) ($payload['amount'] ?? '0'));
            $applied = CollectionRules::applyPayment(
                (string) $invoice->paid_amount,
                (string) $invoice->balance,
                $amount,
                (string) $invoice->due_date,
                $this->todayInMexicoCity(),
            );
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        // Si quedó saldo y hubo pago previo o este, y no está vencido → partial (applyPayment ya lo hace).
        if ($applied['status'] === 'partial' && bccomp($applied['paid_amount'], '0.00', 2) === 0) {
            $applied['status'] = 'pending';
        }
        if (
            $applied['status'] !== 'paid'
            && $applied['status'] !== 'overdue'
            && bccomp($applied['paid_amount'], '0.00', 2) > 0
        ) {
            $applied['status'] = 'partial';
        }

        $method = (string) ($payload['payment_method'] ?? 'transfer');
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            return Result::fail('Método de pago inválido.', 422);
        }
        $paymentDate = trim((string) ($payload['payment_date'] ?? $this->todayInMexicoCity()));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            return Result::fail('payment_date inválida.', 422);
        }

        $userId = (int) ($this->authContext['user_id'] ?? 0);
        $nowUtc = gmdate('Y-m-d H:i:s');
        $reference = isset($payload['reference']) ? trim((string) $payload['reference']) : null;
        $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : null;

        try {
            $collectionStatus = Capsule::connection()->transaction(function () use (
                $invoice,
                $invoiceId,
                $applied,
                $amount,
                $method,
                $paymentDate,
                $reference,
                $notes,
                $userId,
                $nowUtc,
            ): array {
                $paymentId = (int) Capsule::table('payments')->insertGetId([
                    'invoice_id' => $invoiceId,
                    'contract_id' => (int) $invoice->contract_id,
                    'client_id' => (int) $invoice->client_id,
                    'amount' => $amount,
                    'payment_method' => $method,
                    'reference' => $reference !== null && $reference !== ''
                        ? mb_substr($reference, 0, 120)
                        : null,
                    'payment_date' => $paymentDate,
                    'notes' => $notes !== null && $notes !== '' ? mb_substr($notes, 0, 2000) : null,
                    'recorded_by_user_id' => $userId > 0 ? $userId : null,
                    'is_reversed' => 0,
                    'reversed_at' => null,
                    'reversal_reason' => null,
                    'reversed_by_user_id' => null,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);

                Capsule::table('invoices')->where('id', $invoiceId)->update([
                    'paid_amount' => $applied['paid_amount'],
                    'balance' => $applied['balance'],
                    'status' => $applied['status'],
                    'updated_at' => $nowUtc,
                ]);

                // RF-6.3: si el pago liquida el adeudo >90 días, reactiva en esta misma transacción.
                return $this->recalculateContractCollectionStatus((int) $invoice->contract_id, $paymentId);
            });
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        $detail = $this->findInvoiceDetail($invoiceId);
        if (is_array($detail)) {
            $detail['contract_reactivated'] = $collectionStatus['reactivated'];
        }
        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'payments',
            (string) $invoiceId,
            'create',
            'Pago de $' . $amount . ' registrado en ' . (string) $invoice->folio,
            [
                'paid_amount' => FinancialCalculator::normalizeMoney((string) $invoice->paid_amount),
                'balance' => FinancialCalculator::normalizeMoney((string) $invoice->balance),
                'status' => (string) $invoice->status,
            ],
            [
                'paid_amount' => $applied['paid_amount'],
                'balance' => $applied['balance'],
                'status' => $applied['status'],
                'payment_amount' => $amount,
                'payment_method' => $method,
            ],
        );

        return Result::ok(
            $detail,
            $collectionStatus['reactivated']
                ? 'Pago registrado · contrato reactivado automáticamente'
                : 'Pago registrado',
            201,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cancelInvoice(int $invoiceId, array $payload): Result
    {
        $invoice = Capsule::table('invoices')->where('id', $invoiceId)->first();
        if ($invoice === null) {
            return Result::fail('Documento no encontrado.', 404);
        }
        if (!$this->isWithinSegmentScope((int) $invoice->segment_id)) {
            return Result::fail('Documento fuera de tu alcance de segmento.', 403);
        }
        if ((string) $invoice->status === 'cancelled') {
            return Result::fail('El documento ya está cancelado.', 422);
        }
        if (bccomp(FinancialCalculator::normalizeMoney((string) $invoice->paid_amount), '0.00', 2) !== 0) {
            return Result::fail('Solo se pueden cancelar documentos sin pagos aplicados.', 422);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : null;

        Capsule::connection()->transaction(function () use ($invoice, $invoiceId, $nowUtc, $notes): void {
            Capsule::table('invoices')->where('id', $invoiceId)->update([
                'status' => 'cancelled',
                'notes' => $notes !== null && $notes !== ''
                    ? mb_substr($notes, 0, 2000)
                    : ($invoice->notes ?? null),
                'updated_at' => $nowUtc,
            ]);
            $this->recalculateContractCollectionStatus((int) $invoice->contract_id);
        });

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'invoices',
            (string) $invoiceId,
            'status_change',
            'Se canceló el documento ' . (string) $invoice->folio,
            ['status' => (string) $invoice->status],
            ['status' => 'cancelled'],
        );

        $detail = $this->findInvoiceDetail($invoiceId);

        return Result::ok($detail, 'Documento cancelado');
    }

    private function baseInvoiceQuery(): Builder
    {
        return Capsule::table('invoices as i')
            ->join('clients as cl', 'cl.id', '=', 'i.client_id')
            ->join('contracts as c', 'c.id', '=', 'i.contract_id')
            ->leftJoin('segments as seg', 'seg.id', '=', 'i.segment_id')
            ->leftJoin('issuers as iss', 'iss.id', '=', 'i.issuer_id')
            ->leftJoin('service_requests as sr', 'sr.id', '=', 'i.request_id');
    }

    /**
     * @return list<string>
     */
    private function invoiceSelectColumns(): array
    {
        return [
            'i.id',
            'i.folio',
            'i.contract_id',
            'i.client_id',
            'i.segment_id',
            'i.issuer_id',
            'i.request_id',
            'i.invoice_type',
            'i.concept',
            'i.currency',
            'i.subtotal',
            'i.tax_amount',
            'i.total',
            'i.paid_amount',
            'i.balance',
            'i.issue_date',
            'i.due_date',
            'i.status',
            'i.stamped_uuid',
            'i.notes',
            'i.created_at',
            'i.updated_at',
            'cl.name as client_name',
            'c.folio as contract_folio',
            'c.credit_days',
            'seg.name as segment_name',
            'iss.name as issuer_name',
            'sr.folio as request_folio',
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function applyInvoiceFilters(Builder $builder, array $query, string $today): void
    {
        $segmentId = $this->resolveSegmentFilter($query);
        if ($segmentId !== null) {
            $builder->where('i.segment_id', $segmentId);
        }

        $issuerId = $this->positiveIntOrNull($query['issuer_id'] ?? null);
        if ($issuerId !== null) {
            $builder->where('i.issuer_id', $issuerId);
        }

        $status = trim((string) ($query['status'] ?? ''));
        if ($status !== '' && in_array($status, self::INVOICE_STATUSES, true)) {
            $builder->where('i.status', $status);
        }

        $aging = trim((string) ($query['aging_bucket'] ?? ''));
        if ($aging !== '' && in_array($aging, self::AGING_BUCKETS, true)) {
            $this->applyAgingBucketFilter($builder, $aging, $today);
        }

        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('i.folio', 'like', $like)
                    ->orWhere('i.concept', 'like', $like)
                    ->orWhere('cl.name', 'like', $like)
                    ->orWhere('cl.legal_name', 'like', $like)
                    ->orWhere('c.folio', 'like', $like)
                    ->orWhere('sr.folio', 'like', $like);
            });
        }
    }

    private function applyAgingBucketFilter(Builder $builder, string $bucket, string $today): void
    {
        $open = static function (Builder $q): void {
            $q->whereIn('i.status', ['pending', 'partial', 'overdue'])
                ->whereRaw('i.balance > 0');
        };

        match ($bucket) {
            'upcoming' => $builder->where($open)->where('i.due_date', '>=', $today),
            'days_1_30' => $builder->where($open)
                ->where('i.due_date', '<', $today)
                ->whereRaw('DATEDIFF(?, i.due_date) BETWEEN 1 AND 30', [$today]),
            'days_31_60' => $builder->where($open)
                ->where('i.due_date', '<', $today)
                ->whereRaw('DATEDIFF(?, i.due_date) BETWEEN 31 AND 60', [$today]),
            'days_61_90' => $builder->where($open)
                ->where('i.due_date', '<', $today)
                ->whereRaw('DATEDIFF(?, i.due_date) BETWEEN 61 AND 89', [$today]),
            'suspended_90_plus' => $builder->where(static function (Builder $q) use ($today, $open): void {
                $q->where(static function (Builder $inner) use ($today, $open): void {
                    $inner->where($open)
                        ->where('i.due_date', '<', $today)
                        ->whereRaw('DATEDIFF(?, i.due_date) >= 90', [$today]);
                })->orWhereExists(static function (Builder $sub): void {
                    $sub->selectRaw('1')
                        ->from('collection_statuses as cs')
                        ->whereColumn('cs.contract_id', 'i.contract_id')
                        ->where('cs.is_suspended', 1);
                });
            }),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   upcoming_total_mxn: string,
     *   overdue_total_mxn: string,
     *   contracts_over_60_days: int,
     *   suspended_contracts_count: int
     * }
     */
    private function buildKpis(array $query, string $today): array
    {
        $segmentId = $this->resolveSegmentFilter($query);

        $upcomingQ = Capsule::table('invoices as i')
            ->whereIn('i.status', ['pending', 'partial', 'overdue'])
            ->whereRaw('i.balance > 0')
            ->where('i.due_date', '>=', $today);
        if ($segmentId !== null) {
            $upcomingQ->where('i.segment_id', $segmentId);
        }
        $upcoming = FinancialCalculator::normalizeMoney(
            (string) ($upcomingQ->sum('i.balance') ?? '0'),
        );

        $overdueQ = Capsule::table('collection_statuses as cs')
            ->join('contracts as c', 'c.id', '=', 'cs.contract_id');
        if ($segmentId !== null) {
            $overdueQ->where('c.segment_id', $segmentId);
        }
        $overdueTotal = '0.00';
        $over60 = 0;
        $suspendedCount = 0;
        foreach ($overdueQ->get(['cs.overdue_balance', 'cs.overdue_days', 'cs.is_suspended', 'c.status']) as $row) {
            $bal = FinancialCalculator::normalizeMoney((string) $row->overdue_balance);
            $overdueTotal = bcadd($overdueTotal, $bal, 2);
            $days = (int) $row->overdue_days;
            $susp = (bool) $row->is_suspended || (string) $row->status === 'suspended';
            if ($days > 60 || $susp) {
                $over60++;
            }
            if ($susp) {
                $suspendedCount++;
            }
        }

        return [
            'upcoming_total_mxn' => $upcoming,
            'overdue_total_mxn' => $overdueTotal,
            'contracts_over_60_days' => $over60,
            'suspended_contracts_count' => $suspendedCount,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array{key: string, label: string, amount_mxn: string, color: string}>
     */
    private function buildAgingBuckets(array $query, string $today): array
    {
        $segmentId = $this->resolveSegmentFilter($query);
        $buckets = [
            'upcoming' => ['label' => 'Por vencer', 'amount' => '0.00', 'color' => '#10B981'],
            'days_1_30' => ['label' => '1-30 días', 'amount' => '0.00', 'color' => '#F59E0B'],
            'days_31_60' => ['label' => '31-60 días', 'amount' => '0.00', 'color' => '#F97316'],
            'days_61_90' => ['label' => '61-90 días', 'amount' => '0.00', 'color' => '#EF4444'],
            'suspended_90_plus' => ['label' => 'Suspendidos (+90)', 'amount' => '0.00', 'color' => '#7F1D1D'],
        ];

        $invQ = Capsule::table('invoices as i')
            ->leftJoin('collection_statuses as cs', 'cs.contract_id', '=', 'i.contract_id')
            ->whereIn('i.status', ['pending', 'partial', 'overdue'])
            ->whereRaw('i.balance > 0');
        if ($segmentId !== null) {
            $invQ->where('i.segment_id', $segmentId);
        }

        foreach ($invQ->get(['i.balance', 'i.due_date', 'cs.is_suspended', 'cs.overdue_days']) as $row) {
            $balance = FinancialCalculator::normalizeMoney((string) $row->balance);
            $due = (string) $row->due_date;
            $suspended = (bool) ($row->is_suspended ?? false);
            if ($due >= $today && !$suspended) {
                $key = 'upcoming';
            } else {
                $days = $due < $today ? CollectionRules::daysBetween($due, $today) : (int) ($row->overdue_days ?? 0);
                $key = CollectionRules::agingBucketKey($days, $suspended);
            }
            $buckets[$key]['amount'] = bcadd($buckets[$key]['amount'], $balance, 2);
        }

        $out = [];
        foreach ($buckets as $key => $meta) {
            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'amount_mxn' => $meta['amount'],
                'color' => $meta['color'],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapInvoiceListRow(object $row, string $today): array
    {
        $due = (string) $row->due_date;
        $balance = FinancialCalculator::normalizeMoney((string) $row->balance);
        $daysOverdue = 0;
        if (
            bccomp($balance, '0.00', 2) > 0
            && $due < $today
            && (string) $row->status !== 'cancelled'
            && (string) $row->status !== 'paid'
        ) {
            $daysOverdue = CollectionRules::daysBetween($due, $today);
        }

        return [
            'id' => (int) $row->id,
            'folio' => (string) $row->folio,
            'contract_id' => (int) $row->contract_id,
            'contract_folio' => (string) $row->contract_folio,
            'client_id' => (int) $row->client_id,
            'client_name' => (string) $row->client_name,
            'segment_id' => (int) $row->segment_id,
            'segment_name' => (string) ($row->segment_name ?? ''),
            'issuer_id' => (int) $row->issuer_id,
            'issuer_name' => (string) ($row->issuer_name ?? ''),
            'request_id' => $row->request_id !== null ? (int) $row->request_id : null,
            'request_folio' => isset($row->request_folio) && $row->request_folio !== null
                ? (string) $row->request_folio
                : null,
            'invoice_type' => (string) $row->invoice_type,
            'concept' => (string) $row->concept,
            'currency' => (string) $row->currency,
            'subtotal' => FinancialCalculator::normalizeMoney((string) $row->subtotal),
            'tax_amount' => FinancialCalculator::normalizeMoney((string) $row->tax_amount),
            'total' => FinancialCalculator::normalizeMoney((string) $row->total),
            'paid_amount' => FinancialCalculator::normalizeMoney((string) $row->paid_amount),
            'balance' => $balance,
            'issue_date' => (string) $row->issue_date,
            'due_date' => $due,
            'days_overdue' => $daysOverdue,
            'status' => (string) $row->status,
            'credit_days' => (int) ($row->credit_days ?? 0),
            'notes' => $row->notes !== null ? (string) $row->notes : null,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    private function sumUpcomingBalanceForContract(int $contractId, string $today): string
    {
        $sum = Capsule::table('invoices')
            ->where('contract_id', $contractId)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereRaw('balance > 0')
            ->where('due_date', '>=', $today)
            ->sum('balance');

        return FinancialCalculator::normalizeMoney((string) ($sum ?? '0'));
    }

    /** Siguiente folio FAC-XXXX (invocar dentro de una transacción; bloquea la lectura del máximo). */
    public function nextInvoiceFolio(): string
    {
        $max = Capsule::table('invoices')
            ->where('folio', 'like', 'FAC-%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('folio');

        $next = 1;
        if (is_string($max) && preg_match('/FAC-(\d+)/', $max, $m) === 1) {
            $next = (int) $m[1] + 1;
        }

        return sprintf('FAC-%04d', $next);
    }

    private function todayInMexicoCity(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    }

    private function normalizeExchangeRate(string $rate): string
    {
        $trimmed = trim(str_replace(',', '', $rate));
        if ($trimmed === '' || !is_numeric($trimmed) || bccomp($trimmed, '0', 6) <= 0) {
            return '1.000000';
        }

        // Escala 6 para exchange_rate de movements (sin float).
        return bcadd($trimmed, '0', 6);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function resolveSegmentFilter(array $query): ?int
    {
        $locked = $this->authContext['segment_id'] ?? null;
        if ($locked !== null && (string) ($this->authContext['segment_name'] ?? '') !== 'Todos') {
            return (int) $locked;
        }

        return $this->positiveIntOrNull($query['segment_id'] ?? null);
    }

    private function isWithinSegmentScope(int $segmentId): bool
    {
        $locked = $this->authContext['segment_id'] ?? null;
        if ($locked === null || (string) ($this->authContext['segment_name'] ?? '') === 'Todos') {
            return true;
        }

        return (int) $locked === $segmentId;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }
}
