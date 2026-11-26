<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use System\Core\Context;
use System\Core\Result;
use System\Support\ContractExigibleResolver;
use System\Support\FinancialCalculator;
use System\Support\Integrations\OutboxDispatcher;
use System\Support\ServiceRequestRules;
use Throwable;

/**
 * Solicitudes desde el Portal del Cliente (RF-8.1).
 *
 *  - `GET  portal/requests` → historial con estado amigable (Recibida/Aprobada/Ejecutada/Rechazada).
 *  - `POST portal/requests` → alta `origin = 'portal'`, `status = 'pending'`; aparece de inmediato en
 *    la Bandeja interna `/solicitudes` (y en el badge del Sidebar) para su aprobación manual.
 *
 * Candado de cartera: los cambios de plan (alta/baja de usuarios, frecuencia, cancelación) solo se
 * aceptan con el contrato al corriente (`ExigibleCalculator.is_current === true`). Los servicios
 * extra (consumibles / cargos únicos) siempre se permiten.
 */
final class PortalRequestsService extends PortalScopedService
{
    public const PORTAL_REQUEST_TYPES = ['add_users', 'remove_users', 'change_frequency', 'cancel_service', 'extra_service'];

    /** Tipos que modifican el plan contratado y exigen estar al corriente. */
    public const PLAN_CHANGE_TYPES = ['add_users', 'remove_users', 'change_frequency', 'cancel_service'];

    public const NOT_CURRENT_MESSAGE = 'Tu contrato tiene un saldo exigible pendiente. Ponte al corriente para solicitar cambios en tu plan.';

    private const MAX_QUANTITY = 10_000;

    private const TYPE_LABELS = [
        'add_users' => 'Alta de usuarios',
        'remove_users' => 'Baja de usuarios',
        'change_frequency' => 'Cambio de frecuencia de pago',
        'cancel_service' => 'Cancelación de servicio',
        'extra_service' => 'Servicio extra',
    ];

    public function handle(Context $context): Result
    {
        if (!$this->bootClaims($context)) {
            return Result::fail('Sesión del portal requerida.', 401);
        }

        $parts = explode('/', strtolower(trim($context->getUri(), '/')));
        if (count($parts) !== 2) {
            return Result::fail('Recurso del portal no encontrado.', 404);
        }

        return match ($context->getMethod()) {
            'GET' => Result::ok(self::listForClient($this->clientId()), 'Solicitudes'),
            'POST' => $this->create($context->getRequest()),
            default => Result::fail('Método no soportado.', 405),
        };
    }

    /**
     * Historial del cliente (más reciente primero, orden SQL antes del LIMIT).
     *
     * @return list<array<string, mixed>>
     */
    public static function listForClient(int $clientId, int $limit = 50): array
    {
        $rows = Capsule::table('service_requests as r')
            ->leftJoin('contracts as c', 'c.id', '=', 'r.contract_id')
            ->where('r.client_id', $clientId)
            ->orderByRaw('COALESCE(r.updated_at, r.created_at) DESC')
            ->orderByDesc('r.id')
            ->limit($limit)
            ->get([
                'r.id',
                'r.folio',
                'r.contract_id',
                'c.folio as contract_folio',
                'r.origin',
                'r.request_type',
                'r.portal_request_type',
                'r.status',
                'r.currency',
                'r.subtotal',
                'r.notes',
                'r.resolution_notes',
                'r.created_at',
                'r.resolved_at',
            ]);
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $items = Capsule::table('service_request_items as i')
            ->leftJoin('services as s', 's.id', '=', 'i.service_id')
            ->whereIn('i.service_request_id', $ids)
            ->orderBy('i.id')
            ->get(['i.service_request_id', 's.name as service_name', 'i.quantity']);
        $itemsByRequest = [];
        foreach ($items as $item) {
            $itemsByRequest[(int) $item->service_request_id][] = [
                'service_name' => (string) ($item->service_name ?? ''),
                'quantity' => (int) $item->quantity,
            ];
        }
        // Evidencia de ejecución: documento emitido o movimiento MRR asociado a la solicitud.
        $invoiced = Capsule::table('invoices')->whereIn('request_id', $ids)->pluck('request_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $moved = Capsule::table('movements')->whereIn('request_id', $ids)->pluck('request_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $executed = array_flip(array_merge($invoiced, $moved));

        return $rows->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'folio' => (string) $row->folio,
            'contract_id' => (int) $row->contract_id,
            'contract_folio' => $row->contract_folio !== null ? (string) $row->contract_folio : null,
            'origin' => (string) $row->origin,
            'request_type' => (string) $row->request_type,
            'portal_request_type' => $row->portal_request_type !== null ? (string) $row->portal_request_type : null,
            'status' => (string) $row->status,
            'portal_status' => self::portalStatus((string) $row->status, isset($executed[(int) $row->id])),
            'currency' => (string) $row->currency,
            'subtotal' => FinancialCalculator::normalizeMoney((string) $row->subtotal),
            'notes' => $row->notes !== null ? (string) $row->notes : null,
            'resolution_notes' => $row->resolution_notes !== null ? (string) $row->resolution_notes : null,
            'items' => $itemsByRequest[(int) $row->id] ?? [],
            'created_at' => (string) $row->created_at,
            'resolved_at' => $row->resolved_at !== null ? (string) $row->resolved_at : null,
        ])->values()->all();
    }

    /** Estado amigable del portal (Recibida / Aprobada / Ejecutada / Rechazada / Cancelada). */
    public static function portalStatus(string $status, bool $hasExecutionEvidence): string
    {
        return match ($status) {
            'pending', 'quoted' => 'received',
            'approved' => $hasExecutionEvidence ? 'executed' : 'approved',
            'rejected' => 'rejected',
            default => 'cancelled',
        };
    }

    /** @param array<string, mixed> $payload */
    private function create(array $payload): Result
    {
        $contract = $this->ownedContract(self::positiveInt($payload['contract_id'] ?? null) ?? 0);
        if ($contract === null) {
            return Result::fail('Este contrato no pertenece a tu cuenta.', 403);
        }
        if ((string) $contract->status !== 'active') {
            return Result::fail('Solo puedes enviar solicitudes sobre contratos activos.', 422);
        }
        $portalType = (string) ($payload['request_type'] ?? '');
        if (!in_array($portalType, self::PORTAL_REQUEST_TYPES, true)) {
            return Result::fail('Selecciona un tipo de solicitud válido.', 422);
        }
        $notes = trim((string) ($payload['notes'] ?? ''));
        if (mb_strlen($notes) > 2000) {
            return Result::fail('Las notas admiten máximo 2,000 caracteres.', 422);
        }

        // RF-8.1: candado de cartera para cambios de plan (servicios extra siempre permitidos).
        if (in_array($portalType, self::PLAN_CHANGE_TYPES, true)) {
            $exigible = ContractExigibleResolver::forContractId((int) $contract->id);
            if (($exigible['result']['is_current'] ?? false) !== true) {
                return Result::fail(self::NOT_CURRENT_MESSAGE, 422);
            }
        }

        try {
            $prepared = $this->prepareRequest($portalType, $contract, $payload, $notes);
        } catch (InvalidArgumentException $e) {
            return Result::fail($e->getMessage(), 422);
        }

        try {
            $requestId = (int) Capsule::connection()->transaction(function () use ($contract, $portalType, $prepared): int {
                $nowUtc = gmdate('Y-m-d H:i:s');
                $id = (int) Capsule::table('service_requests')->insertGetId([
                    'folio' => ServiceRequestsService::nextFolio(),
                    'contract_id' => (int) $contract->id,
                    'client_id' => $this->clientId(),
                    'segment_id' => (int) $contract->segment_id,
                    'issuer_id' => (int) $contract->issuer_id,
                    'origin' => 'portal',
                    'request_type' => $prepared['request_type'],
                    'portal_request_type' => $portalType,
                    'status' => 'pending',
                    'currency' => in_array((string) $contract->currency, ['MXN', 'USD'], true) ? (string) $contract->currency : 'MXN',
                    'subtotal' => $prepared['subtotal'],
                    'mrr_impact_mxn' => $prepared['mrr_impact_mxn'],
                    'notes' => $prepared['notes'],
                    'resolution_notes' => null,
                    'with_exception' => 0,
                    'exception_policy' => null,
                    'requested_by_user_id' => null,
                    'requested_by_name' => mb_substr((string) ($this->claims['contact_name'] ?? 'Portal del Cliente'), 0, 120),
                    'resolved_by_user_id' => null,
                    'resolved_at' => null,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
                foreach ($prepared['lines'] as $line) {
                    Capsule::table('service_request_items')->insert([
                        'service_request_id' => $id,
                        'service_id' => (int) $line['service_id'],
                        'service_type' => (string) $line['service_type'],
                        'quantity' => (int) $line['quantity'],
                        'unit_price' => (string) $line['unit_price'],
                        'subtotal' => (string) $line['subtotal'],
                        'mrr_impact_mxn' => (string) $line['mrr_impact_mxn'],
                        'created_at' => $nowUtc,
                        'updated_at' => $nowUtc,
                    ]);
                }
                $this->enqueueReceiptEmail($id, $contract, $portalType);

                return $id;
            });
        } catch (Throwable $e) {
            return Result::fail('No se pudo registrar tu solicitud: ' . $e->getMessage(), 422);
        }

        $created = null;
        foreach (self::listForClient($this->clientId()) as $row) {
            if ($row['id'] === $requestId) {
                $created = $row;
                break;
            }
        }

        return Result::ok($created ?? ['id' => $requestId], 'Solicitud enviada', 201);
    }

    /**
     * Traduce la intención del portal al modelo interno de la Bandeja:
     *  - `add_users`     → `recurring_expansion` con ítems cotizados (la aprobación interna expande MRR).
     *  - `extra_service` → `consumption` / `one_time` con ítems cotizados (la aprobación factura).
     *  - resto           → `plan_change` sin ítems ni impacto financiero (ejecución manual).
     *
     * @param array<string, mixed> $payload
     * @return array{request_type: string, lines: list<array<string, mixed>>, subtotal: string, mrr_impact_mxn: string, notes: string|null}
     * @throws InvalidArgumentException
     */
    private function prepareRequest(string $portalType, object $contract, array $payload, string $notes): array
    {
        $quantity = self::positiveInt($payload['quantity'] ?? null);
        if ($quantity !== null && $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException('La cantidad máxima por solicitud es 10,000.');
        }

        if (in_array($portalType, ['add_users', 'extra_service'], true)) {
            $serviceId = self::positiveInt($payload['service_id'] ?? null);
            if ($serviceId === null || $quantity === null) {
                throw new InvalidArgumentException('Selecciona el servicio y la cantidad que necesitas.');
            }
            $prepared = (new ServiceRequestsService())->prepareItemsForContract((int) $contract->id, [
                ['service_id' => $serviceId, 'quantity' => $quantity],
            ]);
            $lineType = (string) ($prepared['lines'][0]['service_type'] ?? '');
            if ($portalType === 'add_users' && $lineType !== 'recurring') {
                throw new InvalidArgumentException('Para agregar usuarios selecciona un servicio de licencias.');
            }
            if ($portalType === 'extra_service' && $lineType === 'recurring') {
                throw new InvalidArgumentException('Los servicios de licencias se solicitan con "Agregar usuarios".');
            }
            $summary = ServiceRequestRules::summarizeRequest($prepared['lines']);

            return [
                'request_type' => $summary['request_type'],
                'lines' => $prepared['lines'],
                'subtotal' => $summary['subtotal'],
                'mrr_impact_mxn' => $summary['mrr_impact_mxn'],
                'notes' => $notes !== '' ? $notes : null,
            ];
        }

        if (mb_strlen($notes) < 5) {
            throw new InvalidArgumentException('Describe brevemente tu solicitud (mínimo 5 caracteres).');
        }
        $headline = self::TYPE_LABELS[$portalType];
        if ($portalType === 'remove_users' && $quantity !== null) {
            $headline .= sprintf(': %d usuario(s)', $quantity);
        }

        return [
            'request_type' => 'plan_change',
            'lines' => [],
            'subtotal' => '0.00',
            'mrr_impact_mxn' => '0.00',
            'notes' => mb_substr($headline . ' — ' . $notes, 0, 2000),
        ];
    }

    /** Acuse de recibo al contacto del portal vía outbox (SMTP; sandbox si no hay producción). */
    private function enqueueReceiptEmail(int $requestId, object $contract, string $portalType): void
    {
        $email = trim((string) ($this->claims['contact_email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $folio = (string) Capsule::table('service_requests')->where('id', $requestId)->value('folio');
        $name = trim((string) ($this->claims['contact_name'] ?? ''));
        $label = self::TYPE_LABELS[$portalType];
        $text = sprintf(
            "Hola %s,\n\nRecibimos tu solicitud %s (%s) para el contrato %s. Nuestro equipo la revisará y te avisaremos cuando cambie de estado.\n\nOperam",
            $name !== '' ? $name : 'cliente',
            $folio,
            $label,
            (string) $contract->folio,
        );

        (new OutboxDispatcher())->enqueue(
            'smtp',
            'send_email',
            'portal_request_received:' . $requestId,
            [
                'to' => [$email],
                'subject' => sprintf('Recibimos tu solicitud %s', $folio),
                'text' => $text,
                'html' => nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'request_id' => $requestId,
            ],
            (int) $contract->issuer_id,
            'service_request',
            (string) $requestId,
            (int) $contract->segment_id,
        );
    }
}
