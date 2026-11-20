<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;
use System\Core\Result;
use System\Support\AuditLogger;

/**
 * Solo lectura paginada de `audit_logs`.
 * GET /api/audit-logs
 */
final class AuditLogsService extends CustomService
{
    private const MODULE = 'config_audit_logs';

    public function handle(Context $context): Result
    {
        if ($context->getMethod() !== 'GET') {
            return Result::fail('Método no soportado. La bitácora es de solo lectura.', 405);
        }

        $auth = $context->getData('auth', []);
        if (!RbacAuthHelper::can($auth, self::MODULE, 'view')) {
            return Result::fail('No tienes permiso para ver la bitácora de auditoría.', 403);
        }

        $request = $context->getRequest();
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = (int) ($request['per_page'] ?? 25);
        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 25;
        }

        $builder = Capsule::table('audit_logs as a');

        // Alcance de segmento forzado por JWT.
        $lockedSegment = $auth['segment_id'] ?? null;
        if ($lockedSegment !== null && $lockedSegment !== '') {
            $builder->where(function ($q) use ($lockedSegment): void {
                $q->where('a.segment_id', (int) $lockedSegment)
                    ->orWhereNull('a.segment_id');
            });
        }

        $search = trim((string) ($request['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $builder->where(function ($q) use ($like): void {
                $q->where('a.description', 'like', $like)
                    ->orWhere('a.ip_address', 'like', $like)
                    ->orWhere('a.user_name_snapshot', 'like', $like)
                    ->orWhere('a.user_email_snapshot', 'like', $like)
                    ->orWhere('a.entity_id', 'like', $like);
            });
        }

        if (!empty($request['user_id'])) {
            $builder->where('a.user_id', (int) $request['user_id']);
        }
        if (!empty($request['module_key'])) {
            $builder->where('a.module_key', (string) $request['module_key']);
        }
        if (!empty($request['action'])) {
            $builder->where('a.action', (string) $request['action']);
        }
        if (!empty($request['date_from'])) {
            $builder->where('a.created_at', '>=', (string) $request['date_from'] . ' 00:00:00');
        }
        if (!empty($request['date_to'])) {
            $builder->where('a.created_at', '<=', (string) $request['date_to'] . ' 23:59:59');
        }

        $total = (clone $builder)->count();
        $rows = $builder
            ->orderByDesc('a.created_at')
            ->orderByDesc('a.id')
            ->forPage($page, $perPage)
            ->get([
                'a.id',
                'a.user_id',
                'a.user_name_snapshot',
                'a.user_email_snapshot',
                'a.segment_id',
                'a.module_key',
                'a.entity_type',
                'a.entity_id',
                'a.action',
                'a.description',
                'a.old_values',
                'a.new_values',
                'a.ip_address',
                'a.user_agent',
                'a.created_at',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_name' => $row->user_name_snapshot !== null ? (string) $row->user_name_snapshot : null,
                'user_email' => $row->user_email_snapshot !== null ? (string) $row->user_email_snapshot : null,
                'segment_id' => $row->segment_id !== null ? (int) $row->segment_id : null,
                'module_key' => (string) $row->module_key,
                'entity_type' => (string) $row->entity_type,
                'entity_id' => $row->entity_id !== null ? (string) $row->entity_id : null,
                'action' => (string) $row->action,
                'description' => (string) $row->description,
                'old_values' => self::decodeJson($row->old_values),
                'new_values' => self::decodeJson($row->new_values),
                'ip_address' => (string) $row->ip_address,
                'user_agent' => $row->user_agent !== null ? (string) $row->user_agent : null,
                'created_at' => (string) $row->created_at,
            ];
        }

        return Result::ok([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'audit_logs_enabled' => AuditLogger::isEnabled(),
        ], 'Bitácora de auditoría');
    }

    private static function decodeJson(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
