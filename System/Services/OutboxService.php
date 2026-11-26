<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\Result;
use System\Support\AuditLogger;
use System\Support\Integrations\OutboxDispatcher;

/**
 * Panel de observabilidad del Outbox (Pág. 37 / RNF-12).
 *
 * GET  /api/outbox                — paginado servidor + filtros + KPIs (can_view)
 * GET  /api/outbox/{id}           — detalle con payload y respuesta (can_view)
 * POST /api/outbox/{id}/retry     — reinicia intentos y reprocesa (can_edit)
 * POST /api/outbox/process-now    — ejecuta el worker manualmente (can_edit, alcance Todos)
 */
final class OutboxService extends CustomService
{
    private const MODULE_KEY = 'config_external_services';

    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    private const DEFAULT_BATCH = 25;

    /** @var array<string, mixed> */
    private array $authContext = [];

    public function handle(Context $context): Result
    {
        $this->authContext = $context->getData('auth', []);
        $method = $context->getMethod();
        $parts = explode('/', strtolower(trim($context->getUri(), '/')));
        $segment1 = $parts[1] ?? null;
        $segment2 = $parts[2] ?? null;
        $id = $segment1 !== null && ctype_digit($segment1) ? (int) $segment1 : null;

        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'view')) {
            return Result::fail('No tienes permiso para ver el monitor de outbox.', 403);
        }

        if ($method === 'GET') {
            if ($id !== null) {
                $row = $this->scopedQuery()->where('o.id', $id)->first();

                return $row !== null
                    ? Result::ok(OutboxDispatcher::mapRow($row), 'Mensaje de outbox')
                    : Result::fail('Mensaje de outbox no encontrado.', 404);
            }

            return Result::ok($this->listPaginated($context->getRequest()), 'Monitor de outbox');
        }

        if ($method !== 'POST') {
            return Result::fail('Método no soportado.', 405);
        }
        if (!RbacAuthHelper::can($this->authContext, self::MODULE_KEY, 'edit')) {
            return Result::fail('No tienes permiso para operar la cola de outbox.', 403);
        }

        if ($segment1 === 'process-now') {
            return $this->processNow($context->getRequest());
        }
        if ($id !== null && $segment2 === 'retry') {
            return $this->retry($id);
        }

        return Result::fail('Ruta de outbox no reconocida.', 404);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function listPaginated(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $builder = $this->scopedQuery();
        $status = (string) ($query['status'] ?? '');
        if (in_array($status, OutboxDispatcher::STATUSES, true)) {
            $builder->where('o.status', $status);
        }
        $destination = (string) ($query['destination'] ?? '');
        if (in_array($destination, OutboxDispatcher::DESTINATIONS, true)) {
            $builder->where('o.destination', $destination);
        }
        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $builder->where(static function (Builder $q) use ($like, $search): void {
                $q->where('o.idempotency_key', 'like', $like)
                    ->orWhere('o.operation', 'like', $like)
                    ->orWhere('o.entity_id', 'like', $like)
                    ->orWhere('o.last_error', 'like', $like);
                if (ctype_digit($search)) {
                    $q->orWhere('o.id', (int) $search);
                }
            });
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderByRaw('COALESCE(o.updated_at, o.created_at) DESC')
            ->orderByDesc('o.id')
            ->forPage($page, $perPage)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = OutboxDispatcher::mapRow($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'kpis' => $this->kpis(),
        ];
    }

    /** @return array{pending: int, processing: int, sent: int, failed: int, exhausted: int} */
    private function kpis(): array
    {
        $counts = [];
        $grouped = $this->scopedQuery()
            ->selectRaw('o.status as status, COUNT(*) as total')
            ->groupBy('o.status')
            ->get();
        foreach ($grouped as $group) {
            $counts[(string) $group->status] = (int) $group->total;
        }
        $exhausted = $this->scopedQuery()
            ->where('o.status', 'failed')
            ->whereColumn('o.attempts', '>=', 'o.max_attempts')
            ->count();

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'processing' => (int) ($counts['processing'] ?? 0),
            'sent' => (int) ($counts['sent'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'exhausted' => $exhausted,
        ];
    }

    private function retry(int $id): Result
    {
        $row = $this->scopedQuery()->where('o.id', $id)->first();
        if ($row === null) {
            return Result::fail('Mensaje de outbox no encontrado o fuera de tu alcance.', 404);
        }
        if ((string) $row->status === 'sent') {
            return Result::fail('El mensaje ya fue enviado; no requiere reintento.', 422);
        }

        $before = OutboxDispatcher::mapRow($row);
        $after = (new OutboxDispatcher())->retry($id);

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'outbox',
            (string) $id,
            'status_change',
            sprintf('Reintento manual del mensaje #%d (%s → %s)', $id, $before['operation'], $before['destination']),
            ['status' => $before['status'], 'attempts' => $before['attempts'], 'last_error' => $before['last_error']],
            ['status' => $after['status'], 'attempts' => $after['attempts'], 'last_error' => $after['last_error'] ?? null],
        );

        return Result::ok(
            $after,
            $after['status'] === 'sent' ? 'Mensaje reenviado correctamente' : 'El reintento volvió a fallar',
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function processNow(array $request): Result
    {
        if (($this->authContext['segment_id'] ?? null) !== null) {
            return Result::fail('El worker de outbox es global; requiere alcance de segmento "Todos".', 403);
        }

        $limit = (int) ($request['limit'] ?? self::DEFAULT_BATCH);
        $summary = (new OutboxDispatcher())->processPending($limit > 0 ? $limit : self::DEFAULT_BATCH);

        AuditLogger::log(
            $this->authContext,
            self::MODULE_KEY,
            'outbox',
            null,
            'status_change',
            sprintf(
                'Procesamiento manual de outbox: %d procesado(s), %d enviado(s), %d fallido(s)',
                $summary['processed'],
                $summary['sent'],
                $summary['failed'],
            ),
            null,
            [
                'processed' => $summary['processed'],
                'sent' => $summary['sent'],
                'failed' => $summary['failed'],
                'slack_alerts' => $summary['slack_alerts'],
            ],
        );

        return Result::ok([
            'processed' => $summary['processed'],
            'sent' => $summary['sent'],
            'failed' => $summary['failed'],
            'slack_alerts' => $summary['slack_alerts'],
            'kpis' => $this->kpis(),
        ], 'Cola de outbox procesada');
    }

    /** Usuarios con segmento fijo solo ven mensajes de su segmento. */
    private function scopedQuery(): Builder
    {
        $builder = Capsule::table('outbox as o');
        $lockedSegment = $this->authContext['segment_id'] ?? null;
        if ($lockedSegment !== null && $lockedSegment !== '') {
            $builder->where('o.segment_id', (int) $lockedSegment);
        }

        return $builder;
    }
}
