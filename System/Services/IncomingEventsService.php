<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use System\Core\Context;
use System\Core\Result;
use System\Support\Integrations\IncomingEventRecorder;

/**
 * Auditoría de solo lectura de webhooks / eventos entrantes (RNF-2).
 * GET /api/incoming-events — paginado servidor (can_view, alcance Todos).
 */
final class IncomingEventsService extends CustomService
{
    private const MODULE_KEY = 'config_external_services';

    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    public function handle(Context $context): Result
    {
        if ($context->getMethod() !== 'GET') {
            return Result::fail('Método no soportado. Los eventos entrantes son de solo lectura.', 405);
        }

        $auth = $context->getData('auth', []);
        if (!RbacAuthHelper::can($auth, self::MODULE_KEY, 'view')) {
            return Result::fail('No tienes permiso para ver los eventos entrantes.', 403);
        }
        // Los webhooks no pertenecen a un segmento: solo visibles con alcance "Todos".
        if (($auth['segment_id'] ?? null) !== null) {
            return Result::fail('Los eventos entrantes requieren alcance de segmento "Todos".', 403);
        }

        $request = $context->getRequest();
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = (int) ($request['per_page'] ?? 25);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 25;
        }

        $builder = Capsule::table('incoming_events as ie');
        $origin = (string) ($request['origin'] ?? '');
        if (in_array($origin, IncomingEventRecorder::ORIGINS, true)) {
            $builder->where('ie.origin', $origin);
        }
        $processed = (string) ($request['processed'] ?? '');
        if ($processed === 'yes' || $processed === 'no') {
            $builder->where('ie.processed', $processed === 'yes' ? 1 : 0);
        }
        $search = trim((string) ($request['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $builder->where(static function (Builder $q) use ($like): void {
                $q->where('ie.external_id', 'like', $like)
                    ->orWhere('ie.event_type', 'like', $like)
                    ->orWhere('ie.error', 'like', $like);
            });
        }

        $total = (clone $builder)->count();
        // Tabla append-only sin updated_at: más reciente primero por created_at.
        $rows = $builder
            ->orderByDesc('ie.created_at')
            ->orderByDesc('ie.id')
            ->forPage($page, $perPage)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = IncomingEventRecorder::mapRow($row);
        }

        return Result::ok([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ], 'Eventos entrantes');
    }
}
