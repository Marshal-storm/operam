<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\Result;
use System\Support\BalanceRules;
use System\Support\ContractExigibleResolver;
use System\Support\FinancialCalculator;
use System\Support\IssuerSpeiInstructions;
use System\Support\SegmentProfitabilityCalculator;

/**
 * Portal del Cliente — `/api/portal/*` (RF-7).
 *
 * Row-Level Security (RNF-7): TODA consulta se filtra por el `client_id` del JWT `client_portal`
 * (inyectado por la guardia de `AppService` como `portal_auth`); el cliente jamás puede pedir
 * datos de otro `client_id` por parámetro.
 *
 *  - `GET overview`          → todo el tablero en una sola carga (RNF-5).
 *  - `GET contracts/{id}`    → detalle de un contrato propio (403 si es ajeno).
 *  - `GET invoices`          → facturas paginadas + pagos.
 *  - `requests`              → `PortalRequestsService` (historial + alta RF-8.1).
 *  - `store`                 → `PortalStoreService` (catálogo, cotización y checkout RF-2.8).
 *  - `payments`/`sandbox-pay`→ `PortalPaymentsService` (ligas IntegroPAY + simulador sandbox).
 */
final class PortalService extends PortalScopedService
{
    private const ALLOWED_PER_PAGE = [10, 25, 50];

    public function handle(Context $context): Result
    {
        if (!$this->bootClaims($context)) {
            return Result::fail('Sesión del portal requerida.', 401);
        }

        $parts = explode('/', strtolower(trim($context->getUri(), '/')));
        $section = $parts[1] ?? '';

        // Secciones transaccionales: servicios dedicados (SRP), mismo aislamiento por client_id.
        $delegate = match ($section) {
            'requests' => new PortalRequestsService(),
            'store' => new PortalStoreService(),
            'payments', 'sandbox-pay' => new PortalPaymentsService(),
            default => null,
        };
        if ($delegate !== null) {
            return $delegate->handle($context);
        }

        if ($context->getMethod() !== 'GET') {
            return Result::fail('Método no soportado.', 405);
        }

        $resourceId = isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : null;
        $query = $context->getRequest();

        return match ($section) {
            'overview' => Result::ok($this->overview(), 'Resumen del portal'),
            'contracts' => $resourceId !== null
                ? $this->contractDetail($resourceId)
                : Result::fail('Se requiere el id del contrato.', 400),
            'invoices' => Result::ok($this->invoices($query), 'Facturas y pagos'),
            default => Result::fail('Recurso del portal no encontrado.', 404),
        };
    }

    /**
     * Tablero completo del cliente: perfil, contratos (con exigible, líneas y bolsas), facturas,
     * instrucciones SPEI del emisor y solicitudes recientes.
     *
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $clientId = (int) $this->claims['client_id'];
        $today = gmdate('Y-m-d');

        $client = Capsule::table('clients')->where('id', $clientId)->first();
        $contractRows = Capsule::table('contracts')
            ->where('client_id', $clientId)
            ->where('status', '!=', 'draft')
            ->orderByRaw('COALESCE(updated_at, created_at) DESC')
            ->orderByDesc('id')
            ->get()
            ->all();

        $contracts = $this->buildContracts($contractRows, $today);

        $pending = $this->invoiceQuery($clientId)
            ->whereIn('i.status', self::OPEN_INVOICE_STATUSES)
            ->where('i.balance', '>', 0)
            ->limit(25)
            ->get($this->invoiceColumns());
        $recent = $this->invoiceQuery($clientId)
            ->where('i.status', '!=', 'cancelled')
            ->limit(8)
            ->get($this->invoiceColumns());

        // Contrato principal: el primero activo; si no hay, el más reciente.
        $primary = $contracts[0] ?? null;
        foreach ($contracts as $contract) {
            if ($contract['status'] === 'active') {
                $primary = $contract;
                break;
            }
        }
        $primaryIssuerId = $primary !== null ? (int) $primary['issuer']['id'] : (int) ($client->issuer_id ?? 0);

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'today' => $today,
            'client' => [
                'id' => $clientId,
                'name' => (string) ($client->name ?? ''),
                'legal_name' => (string) ($client->legal_name ?? ''),
            ],
            'contact' => [
                'id' => $this->claims['contact_id'] ?? null,
                'name' => (string) ($this->claims['contact_name'] ?? ''),
                'email' => (string) ($this->claims['contact_email'] ?? ''),
            ],
            'default_contract_id' => $this->claims['contract_id'] ?? ($primary['id'] ?? null),
            'contracts' => $contracts,
            'pending_invoices' => $pending->map(fn (object $row): array => $this->mapInvoice($row, $today))->all(),
            'recent_invoices' => $recent->map(fn (object $row): array => $this->mapInvoice($row, $today))->all(),
            'spei' => IssuerSpeiInstructions::forIssuer($primaryIssuerId),
            'recent_requests' => PortalRequestsService::listForClient($clientId, 5),
        ];
    }

    /** Detalle de un contrato propio; cualquier otro `client_id` (o id inexistente) → 403. */
    private function contractDetail(int $contractId): Result
    {
        $clientId = (int) $this->claims['client_id'];
        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        // Mismo 403 para ajeno e inexistente: no se revela qué ids existen.
        if ($contract === null || (int) $contract->client_id !== $clientId) {
            return Result::fail('Este contrato no pertenece a tu cuenta.', 403);
        }

        $today = gmdate('Y-m-d');
        $built = $this->buildContracts([$contract], $today)[0];

        $invoices = $this->invoiceQuery($clientId)
            ->where('i.contract_id', $contractId)
            ->where('i.status', '!=', 'cancelled')
            ->limit(12)
            ->get($this->invoiceColumns())
            ->map(fn (object $row): array => $this->mapInvoice($row, $today))
            ->all();

        return Result::ok($built + ['invoices' => $invoices], 'Detalle del contrato');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function invoices(array $query): array
    {
        $clientId = (int) $this->claims['client_id'];
        $today = gmdate('Y-m-d');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 10);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 10;
        }

        $builder = $this->invoiceQuery($clientId)->where('i.status', '!=', 'cancelled');
        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if ($status === 'open') {
            $builder->whereIn('i.status', self::OPEN_INVOICE_STATUSES)->where('i.balance', '>', 0);
        } elseif ($status === 'paid') {
            $builder->where('i.status', 'paid');
        }
        $contractId = (int) ($query['contract_id'] ?? 0);
        if ($contractId > 0) {
            $builder->where('i.contract_id', $contractId);
        }

        $total = (clone $builder)->count('i.id');
        $items = $builder
            ->forPage($page, $perPage)
            ->get($this->invoiceColumns())
            ->map(fn (object $row): array => $this->mapInvoice($row, $today))
            ->all();

        $payments = Capsule::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('p.client_id', $clientId)
            ->where('p.is_reversed', 0)
            ->orderByRaw('COALESCE(p.updated_at, p.created_at) DESC')
            ->orderByDesc('p.id')
            ->limit(25)
            ->get([
                'p.id',
                'p.invoice_id',
                'i.folio as invoice_folio',
                'p.amount',
                'p.payment_method',
                'p.reference',
                'p.payment_date',
            ])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'invoice_id' => (int) $row->invoice_id,
                'invoice_folio' => (string) $row->invoice_folio,
                'amount' => FinancialCalculator::normalizeMoney((string) $row->amount),
                'payment_method' => (string) $row->payment_method,
                'reference' => $row->reference !== null ? (string) $row->reference : null,
                'payment_date' => (string) $row->payment_date,
            ])
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'payments' => $payments,
        ];
    }

    // ── Construcción de contratos ────────────────────────────────────────────────────────

    /**
     * Arma los contratos del cliente con consultas por lote (`whereIn`) — sin N+1 (RNF-5).
     *
     * @param list<object> $contractRows
     * @return list<array<string, mixed>>
     */
    private function buildContracts(array $contractRows, string $today): array
    {
        if ($contractRows === []) {
            return [];
        }
        $contractIds = array_map(static fn (object $row): int => (int) $row->id, $contractRows);
        $issuerIds = array_values(array_unique(
            array_map(static fn (object $row): int => (int) $row->issuer_id, $contractRows),
        ));

        $issuers = Capsule::table('issuers')->whereIn('id', $issuerIds)->get()->keyBy('id');

        $lineRows = Capsule::table('contract_lines as cl')
            ->leftJoin('services as s', 's.id', '=', 'cl.service_id')
            ->whereIn('cl.contract_id', $contractIds)
            ->where('cl.active', 1)
            ->orderBy('cl.id')
            ->get([
                'cl.id',
                'cl.contract_id',
                'cl.service_id',
                's.code as service_code',
                'cl.description',
                'cl.service_type',
                'cl.billing_frequency',
                'cl.quantity',
                'cl.unit_price',
                'cl.discount_amount',
                'cl.subtotal',
                'cl.is_package_converted',
                's.unit_of_measure',
                's.package_factor',
            ]);
        $linesByContract = [];
        foreach ($lineRows as $line) {
            $linesByContract[(int) $line->contract_id][] = $line;
        }

        $balanceRows = Capsule::table('balances as b')
            ->leftJoin('services as s', 's.id', '=', 'b.service_id')
            ->whereIn('b.contract_id', $contractIds)
            ->orderBy('s.name')
            ->orderBy('b.bucket')
            ->orderByDesc('b.id')
            ->get([
                'b.id',
                'b.contract_id',
                'b.service_id',
                's.code as service_code',
                's.name as service_name',
                'b.bucket',
                'b.period_start',
                'b.period_end',
                'b.granted_units',
                'b.consumed_units',
            ]);
        $balancesByContract = [];
        foreach ($balanceRows as $balance) {
            $balancesByContract[(int) $balance->contract_id][] = $balance;
        }

        // Facturas de licencias (recurring/mixed): únicas que alimentan el motor de exigible.
        $licenseInvoicesByContract = ContractExigibleResolver::loadLicenseInvoices($contractIds);

        // Saldo abierto total (cualquier documento pendiente): tope del pago con tarjeta.
        $openBalanceByContract = [];
        $openRows = Capsule::table('invoices')
            ->whereIn('contract_id', $contractIds)
            ->whereIn('status', self::OPEN_INVOICE_STATUSES)
            ->where('balance', '>', 0)
            ->get(['contract_id', 'balance']);
        foreach ($openRows as $row) {
            $key = (int) $row->contract_id;
            $openBalanceByContract[$key] = bcadd(
                $openBalanceByContract[$key] ?? '0.00',
                FinancialCalculator::normalizeMoney((string) $row->balance),
                2,
            );
        }

        $result = [];
        foreach ($contractRows as $contract) {
            $contractId = (int) $contract->id;
            $lines = $linesByContract[$contractId] ?? [];
            $exigible = ContractExigibleResolver::forContract($contract, $licenseInvoicesByContract[$contractId] ?? [], $today);

            $result[] = [
                'id' => $contractId,
                'folio' => (string) $contract->folio,
                'status' => (string) $contract->status,
                'frequency' => (string) $contract->frequency,
                'currency' => (string) $contract->currency,
                'anniversary_date' => (string) $contract->anniversary_date,
                'next_cutoff_date' => $exigible['next_cutoff_date'],
                'mrr_mxn' => FinancialCalculator::normalizeMoney((string) $contract->mrr_mxn),
                'is_current' => $exigible['result']['is_current'],
                'exigible_amount_mxn' => $exigible['result']['exigible_amount'],
                'exigible_detail' => $exigible['result']['detail'],
                'exigible' => $exigible['result'],
                'open_balance_mxn' => $openBalanceByContract[$contractId] ?? '0.00',
                'concurrent_users' => $this->concurrentUsers($lines),
                'lines' => array_map(fn (object $line): array => $this->mapLine($line), $lines),
                'balances' => array_map(
                    fn (object $row): array => $this->mapBalance($row),
                    $balancesByContract[$contractId] ?? [],
                ),
                'issuer' => $this->mapIssuerSummary($issuers->get((int) $contract->issuer_id)),
                // Instrucciones SPEI del emisor de ESTE contrato (puede variar entre contratos).
                'spei' => IssuerSpeiInstructions::fromRow($issuers->get((int) $contract->issuer_id)),
            ];
        }

        return $result;
    }

    /**
     * Usuarios concurrentes de las líneas vigentes (paquetes × factor).
     *
     * @param list<object> $lines
     */
    private function concurrentUsers(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $unit = (string) ($line->unit_of_measure ?? '');
            $isPackage = (bool) $line->is_package_converted || $unit === 'pack_5_users';
            if ($unit !== 'user' && !$isPackage) {
                continue;
            }
            $factor = (int) ($line->package_factor ?? 0);
            if ($isPackage && $factor < 2 && $line->service_id !== null) {
                // El factor vive en el servicio individual que apunta al paquete.
                $factor = (int) (Capsule::table('services')
                    ->where('package_service_id', (int) $line->service_id)
                    ->max('package_factor') ?? 0);
            }
            $total += SegmentProfitabilityCalculator::concurrentUsersFromLine((int) $line->quantity, $isPackage, $factor);
        }

        return $total;
    }

    // ── Mapeos ────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function mapLine(object $line): array
    {
        return [
            'id' => (int) $line->id,
            'service_id' => $line->service_id !== null ? (int) $line->service_id : null,
            'service_code' => $line->service_code !== null ? (string) $line->service_code : null,
            'description' => (string) $line->description,
            'service_type' => (string) $line->service_type,
            'billing_frequency' => (string) $line->billing_frequency,
            'unit_of_measure' => $line->unit_of_measure !== null ? (string) $line->unit_of_measure : null,
            'quantity' => (int) $line->quantity,
            'unit_price' => FinancialCalculator::normalizeMoney((string) $line->unit_price),
            'discount_amount' => FinancialCalculator::normalizeMoney((string) $line->discount_amount),
            'subtotal' => FinancialCalculator::normalizeMoney((string) $line->subtotal),
            'is_package_converted' => (bool) $line->is_package_converted,
        ];
    }

    /** @return array<string, mixed> */
    private function mapBalance(object $row): array
    {
        $granted = (int) $row->granted_units;
        $consumed = (int) $row->consumed_units;

        return [
            'id' => (int) $row->id,
            'service_id' => (int) $row->service_id,
            'service_code' => (string) ($row->service_code ?? ''),
            'service_name' => (string) ($row->service_name ?? ''),
            'bucket' => (string) $row->bucket,
            'period_start' => $row->period_start !== null ? (string) $row->period_start : null,
            'period_end' => $row->period_end !== null ? (string) $row->period_end : null,
            'granted_units' => $granted,
            'consumed_units' => $consumed,
            'available_units' => BalanceRules::availableUnits($granted, $consumed),
        ];
    }

    /** @return array{id: int, name: string} */
    private function mapIssuerSummary(?object $issuer): array
    {
        return $issuer === null
            ? ['id' => 0, 'name' => '']
            : ['id' => (int) $issuer->id, 'name' => (string) $issuer->name];
    }

    // ── Facturas ──────────────────────────────────────────────────────────────────────────

    private function invoiceQuery(int $clientId): Builder
    {
        return Capsule::table('invoices as i')
            ->leftJoin('contracts as c', 'c.id', '=', 'i.contract_id')
            ->where('i.client_id', $clientId)
            ->orderByRaw('COALESCE(i.updated_at, i.created_at) DESC')
            ->orderByDesc('i.id');
    }

    /** @return list<string> */
    private function invoiceColumns(): array
    {
        return [
            'i.id',
            'i.folio',
            'i.contract_id',
            'c.folio as contract_folio',
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
        ];
    }

    /** @return array<string, mixed> */
    private function mapInvoice(object $row, string $today): array
    {
        $balance = FinancialCalculator::normalizeMoney((string) $row->balance);
        $status = (string) $row->status;
        // Se refleja el vencimiento aunque el job nocturno aún no haya marcado `overdue`.
        if (
            in_array($status, ['pending', 'partial'], true)
            && (string) $row->due_date < $today
            && bccomp($balance, '0.00', 2) > 0
        ) {
            $status = 'overdue';
        }

        return [
            'id' => (int) $row->id,
            'folio' => (string) $row->folio,
            'contract_id' => (int) $row->contract_id,
            'contract_folio' => $row->contract_folio !== null ? (string) $row->contract_folio : null,
            'invoice_type' => (string) $row->invoice_type,
            'concept' => (string) $row->concept,
            'currency' => (string) $row->currency,
            'subtotal' => FinancialCalculator::normalizeMoney((string) $row->subtotal),
            'tax_amount' => FinancialCalculator::normalizeMoney((string) $row->tax_amount),
            'total' => FinancialCalculator::normalizeMoney((string) $row->total),
            'paid_amount' => FinancialCalculator::normalizeMoney((string) $row->paid_amount),
            'balance' => $balance,
            'issue_date' => (string) $row->issue_date,
            'due_date' => (string) $row->due_date,
            'status' => $status,
        ];
    }
}
