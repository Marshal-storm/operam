<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use System\Core\Context;
use System\Core\Result;
use System\Support\CollectionRules;
use System\Support\FinancialCalculator;
use System\Support\Integrations\IntegroPayGateway;
use System\Support\Integrations\PaymentGatewayInterface;
use System\Support\IssuerSpeiInstructions;
use System\Support\PriceListResolver;
use System\Support\PromotionCalculator;
use System\Support\PurchaseFulfillment;
use Throwable;

/**
 * Tienda pública del Portal (RF-7 + promociones de unidades extra RF-2.8).
 *
 *  - `GET  portal/store`            → servicios públicos no recurrentes del segmento del cliente con
 *                                     escalones de la lista del contrato y promociones visibles.
 *  - `GET  portal/store/quote`      → cotización en vivo (escalón + IVA 16% + unidades de regalo).
 *  - `POST portal/store/checkout`   → `purchases` + liga IntegroPAY (tarjeta) o documento SPEI.
 *
 * El precio SIEMPRE se recalcula en el servidor; el cliente solo envía servicio y cantidad.
 * La compra jamás toca `contract_lines`, precio pactado ni MRR (RF-2.8).
 */
final class PortalStoreService extends PortalScopedService
{
    public const MAX_QUANTITY = 1_000_000;

    /** Solo consumibles y cargos únicos se venden en la Tienda (las licencias van por Solicitudes). */
    private const STORE_SERVICE_TYPES = ['consumption', 'single'];

    private const PAYMENT_METHODS = ['card_integropay', 'spei'];

    private readonly PaymentGatewayInterface $gateway;

    /** @param array<string, list<\Closure>> $hooks */
    public function __construct(?PaymentGatewayInterface $gateway = null, array $hooks = [])
    {
        parent::__construct($hooks);
        $this->gateway = $gateway ?? new IntegroPayGateway();
    }

    public function handle(Context $context): Result
    {
        if (!$this->bootClaims($context)) {
            return Result::fail('Sesión del portal requerida.', 401);
        }

        $parts = explode('/', strtolower(trim($context->getUri(), '/')));
        $action = $parts[2] ?? '';
        $method = $context->getMethod();
        $payload = $context->getRequest();

        return match (true) {
            $method === 'GET' && $action === '' => $this->catalog($payload),
            $method === 'GET' && $action === 'quote' => $this->quoteEndpoint($payload),
            $method === 'POST' && $action === 'checkout' => $this->checkout($payload),
            default => Result::fail('Recurso del portal no encontrado.', 404),
        };
    }

    /** @param array<string, mixed> $query */
    private function catalog(array $query): Result
    {
        $contracts = Capsule::table('contracts')
            ->where('client_id', $this->clientId())
            ->where('status', 'active')
            ->orderByRaw('COALESCE(updated_at, created_at) DESC')
            ->orderByDesc('id')
            ->get(['id', 'folio', 'price_list_id', 'segment_id', 'frequency', 'issuer_id']);

        $requested = self::positiveInt($query['contract_id'] ?? null) ?? self::positiveInt($this->claims['contract_id'] ?? null);
        $contract = $contracts->firstWhere('id', $requested) ?? $contracts->first();
        $priceListId = $contract !== null ? PriceListResolver::priceListIdForContract($contract) : null;
        $currency = $priceListId !== null
            ? (string) (Capsule::table('price_lists')->where('id', $priceListId)->value('currency') ?? 'MXN')
            : 'MXN';

        $promotions = PromotionCalculator::activeExtraUnitsCandidates(
            $this->clientId(),
            $this->clientSegmentId(),
            null,
            null,
            true,
        );
        $promosByService = [];
        foreach ($promotions as $promo) {
            $promosByService[(int) $promo['service_id']][] = [
                'id' => $promo['id'],
                'name' => $promo['name'],
                'extra_units_pct' => bcadd($promo['extra_units_pct'], '0', 2),
                'min_quantity' => $promo['min_quantity'],
            ];
        }

        $items = [];
        if ($priceListId !== null) {
            $services = $this->storeServicesQuery()->get([
                'id',
                'code',
                'name',
                'description',
                'service_type',
                'unit_of_measure',
            ]);
            foreach ($services as $service) {
                $tiers = PriceListResolver::tiersFor(
                    $priceListId,
                    (int) $service->id,
                    (string) ($contract->frequency ?? 'monthly'),
                    (string) $service->service_type,
                );
                if ($tiers === []) {
                    continue;
                }
                $fromPrice = $tiers[0]['unit_price'];
                foreach ($tiers as $tier) {
                    if (bccomp($tier['unit_price'], $fromPrice, 2) < 0) {
                        $fromPrice = $tier['unit_price'];
                    }
                }
                $items[] = [
                    'id' => (int) $service->id,
                    'code' => (string) $service->code,
                    'name' => (string) $service->name,
                    'description' => $service->description !== null ? (string) $service->description : null,
                    'service_type' => (string) $service->service_type,
                    'unit_of_measure' => (string) $service->unit_of_measure,
                    'currency' => $currency,
                    'price_tiers' => $tiers,
                    'from_price' => $fromPrice,
                    'promotions' => (string) $service->service_type === 'consumption'
                        ? ($promosByService[(int) $service->id] ?? [])
                        : [],
                ];
            }
        }

        $serviceNames = array_column($items, 'name', 'id');
        $activePromotions = [];
        foreach ($promotions as $promo) {
            $serviceId = (int) $promo['service_id'];
            if (!isset($serviceNames[$serviceId])) {
                continue;
            }
            $activePromotions[] = [
                'id' => $promo['id'],
                'name' => $promo['name'],
                'service_id' => $serviceId,
                'service_name' => $serviceNames[$serviceId],
                'extra_units_pct' => bcadd($promo['extra_units_pct'], '0', 2),
                'min_quantity' => $promo['min_quantity'],
            ];
        }

        return Result::ok([
            'contract_id' => $contract !== null ? (int) $contract->id : null,
            'contracts' => $contracts->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'folio' => (string) $row->folio,
            ])->values()->all(),
            'currency' => $currency,
            'items' => $items,
            'active_promotions' => $activePromotions,
        ], 'Tienda');
    }

    /** @param array<string, mixed> $query */
    private function quoteEndpoint(array $query): Result
    {
        $contract = $this->activeOwnedContract($query['contract_id'] ?? null);
        if ($contract === null) {
            return Result::fail('Selecciona un contrato activo de tu cuenta.', 403);
        }
        try {
            return Result::ok($this->quote($contract, $query['service_id'] ?? null, $query['quantity'] ?? null), 'Cotización');
        } catch (InvalidArgumentException $e) {
            return Result::fail($e->getMessage(), 422);
        }
    }

    /** @param array<string, mixed> $payload */
    private function checkout(array $payload): Result
    {
        $contract = $this->activeOwnedContract($payload['contract_id'] ?? null);
        if ($contract === null) {
            return Result::fail('Selecciona un contrato activo de tu cuenta.', 403);
        }
        $paymentMethod = (string) ($payload['payment_method'] ?? '');
        if (!in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            return Result::fail('Selecciona un método de pago válido (tarjeta o SPEI).', 422);
        }
        try {
            $quote = $this->quote($contract, $payload['service_id'] ?? null, $payload['quantity'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Result::fail($e->getMessage(), 422);
        }

        $spei = IssuerSpeiInstructions::forIssuer((int) $contract->issuer_id);
        if ($paymentMethod === 'spei' && !$spei['is_configured']) {
            return Result::fail('Tu emisor aún no tiene datos SPEI configurados. Elige pago con tarjeta.', 422);
        }

        try {
            $outcome = Capsule::connection()->transaction(function () use ($contract, $quote, $paymentMethod): array {
                $nowUtc = gmdate('Y-m-d H:i:s');
                $purchaseId = (int) Capsule::table('purchases')->insertGetId([
                    'client_id' => $this->clientId(),
                    'contract_id' => (int) $contract->id,
                    'status' => 'pending',
                    'subtotal' => $quote['subtotal'],
                    'tax_amount' => $quote['tax_amount'],
                    'total' => $quote['total'],
                    'currency' => $quote['currency'],
                    'origin' => 'portal',
                    'payment_method' => $paymentMethod,
                    'promotion_id' => $quote['promotion_id'],
                    'gifted_units' => $quote['extra_units'],
                    'details_json' => json_encode([
                        'service_id' => $quote['service_id'],
                        'service_code' => $quote['service_code'],
                        'service_name' => $quote['service_name'],
                        'service_type' => $quote['service_type'],
                        'unit_of_measure' => $quote['unit_of_measure'],
                        'quantity' => $quote['quantity'],
                        'unit_price' => $quote['unit_price'],
                        'promotion_name' => $quote['promotion_name'],
                        'extra_units_pct' => $quote['extra_units_pct'],
                        'total_units_to_receive' => $quote['total_units_to_receive'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);

                if ($paymentMethod === 'card_integropay') {
                    $link = $this->gateway->createPaymentLink([
                        'issuer_id' => (int) $contract->issuer_id,
                        'client_id' => $this->clientId(),
                        'contract_id' => (int) $contract->id,
                        'purchase_id' => $purchaseId,
                        'amount' => $quote['total'],
                        'currency' => $quote['currency'],
                        'concept' => sprintf(
                            'Tienda · %s × %s',
                            $quote['service_name'],
                            number_format((int) $quote['quantity'], 0, '.', ','),
                        ),
                    ]);

                    return ['purchase_id' => $purchaseId, 'payment_link' => $link, 'invoice' => null];
                }

                return [
                    'purchase_id' => $purchaseId,
                    'payment_link' => null,
                    'invoice' => PurchaseFulfillment::issueSpeiInvoice($purchaseId),
                ];
            });
        } catch (Throwable $e) {
            return Result::fail($e->getMessage(), 422);
        }

        return Result::ok([
            'purchase_id' => $outcome['purchase_id'],
            'payment_method' => $paymentMethod,
            'quote' => $quote,
            'payment_link' => $outcome['payment_link'],
            'invoice' => $outcome['invoice'],
            'spei' => $paymentMethod === 'spei' ? $spei : null,
        ], $paymentMethod === 'spei' ? 'Compra registrada: realiza tu transferencia SPEI' : 'Liga de pago generada', 201);
    }

    /**
     * Cotización autoritativa: escalón de la lista + IVA 16% (BCMath) + mejor promoción RF-2.8.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    private function quote(object $contract, mixed $rawServiceId, mixed $rawQuantity): array
    {
        $serviceId = self::positiveInt($rawServiceId);
        $quantity = self::positiveInt($rawQuantity);
        if ($serviceId === null) {
            throw new InvalidArgumentException('Selecciona un servicio de la tienda.');
        }
        if ($quantity === null || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf(
                'La cantidad debe ser un número entero entre 1 y %s.',
                number_format(self::MAX_QUANTITY, 0, '.', ','),
            ));
        }

        $service = $this->storeServicesQuery()->where('id', $serviceId)->first([
            'id',
            'code',
            'name',
            'service_type',
            'unit_of_measure',
        ]);
        if ($service === null) {
            throw new InvalidArgumentException('Este servicio no está disponible en tu tienda.');
        }
        $priceListId = PriceListResolver::priceListIdForContract($contract);
        if ($priceListId === null) {
            throw new InvalidArgumentException('Tu contrato no tiene una lista de precios vigente.');
        }

        $unitPrice = PriceListResolver::resolveUnitPrice(
            $priceListId,
            $serviceId,
            (string) $contract->frequency,
            $quantity,
            (string) $service->service_type,
        );
        $subtotal = bcmul($unitPrice, (string) $quantity, 2);
        $amounts = CollectionRules::buildInvoiceAmounts($subtotal);
        $currency = (string) (Capsule::table('price_lists')->where('id', $priceListId)->value('currency') ?? 'MXN');

        $promo = (string) $service->service_type === 'consumption'
            ? PromotionCalculator::evaluateExtraUnitsPromo(
                $this->clientId(),
                $this->clientSegmentId(),
                $serviceId,
                $quantity,
                null,
                true,
            )
            : PromotionCalculator::pickBestExtraUnitsPromo($quantity, []);

        return [
            'contract_id' => (int) $contract->id,
            'service_id' => $serviceId,
            'service_code' => (string) $service->code,
            'service_name' => (string) $service->name,
            'service_type' => (string) $service->service_type,
            'unit_of_measure' => (string) $service->unit_of_measure,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $amounts['subtotal'],
            'tax_amount' => $amounts['tax_amount'],
            'total' => $amounts['total'],
            'currency' => in_array($currency, ['MXN', 'USD'], true) ? $currency : 'MXN',
            'extra_units' => $promo['extra_units'],
            'total_units_to_receive' => $promo['total_units'],
            'promotion_id' => $promo['promotion_id'],
            'promotion_name' => $promo['promotion_name'],
            'extra_units_pct' => $promo['extra_units_pct'],
        ];
    }

    private function storeServicesQuery(): \Illuminate\Database\Query\Builder
    {
        return Capsule::table('services')
            ->where('is_public', 1)
            ->where('is_active', 1)
            ->where('segment_id', $this->clientSegmentId())
            ->whereIn('service_type', self::STORE_SERVICE_TYPES)
            ->orderByRaw('COALESCE(updated_at, created_at) DESC')
            ->orderByDesc('id');
    }

    private function activeOwnedContract(mixed $rawContractId): ?object
    {
        $contract = $this->ownedContract(self::positiveInt($rawContractId) ?? 0);

        return $contract !== null && (string) $contract->status === 'active' ? $contract : null;
    }
}
