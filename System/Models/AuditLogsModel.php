<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Bitácora de auditoría append-only (`audit_logs`).
 * UPDATE/DELETE bloqueados a nivel Eloquent (+ triggers MySQL).
 */
final class AuditLogsModel extends Model
{
    protected $table = 'audit_logs';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'aud';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'user_name_snapshot',
        'user_email_snapshot',
        'segment_id',
        'module_key',
        'entity_type',
        'entity_id',
        'action',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('audit_logs es inmutable: UPDATE prohibido.');
        });

        static::deleting(static function (): void {
            throw new RuntimeException(
                'audit_logs DELETE bloqueado fuera de la depuración controlada (@allow_audit_purge).',
            );
        });
    }
}
