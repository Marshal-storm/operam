<?php

declare(strict_types=1);

namespace System\Support;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * RF-11.4 — Validación del permiso independiente `can_authorize_exceptions`.
 * Consulta MySQL (fuente de verdad) para no depender solo del JWT envejecido.
 */
final class ExceptionAuthorization
{
    public const DENIED_MESSAGE =
        'Tu usuario no cuenta con el permiso independiente para autorizar excepciones';

    /**
     * Evaluación pura (sin I/O) del permiso RF-11.4 — apta para PHPUnit.
     *
     * @return array{allowed:bool,message:?string}
     */
    public static function evaluatePermission(
        bool $userCanAuthorize,
        bool $withException,
        string $reasonOrNotes,
        int $minReasonLength = 5,
    ): array {
        if (!$withException) {
            return ['allowed' => true, 'message' => null];
        }

        if (!$userCanAuthorize) {
            return ['allowed' => false, 'message' => self::DENIED_MESSAGE];
        }

        $reason = trim($reasonOrNotes);
        if (mb_strlen($reason) < $minReasonLength) {
            return [
                'allowed' => false,
                'message' => 'El motivo de la excepción es obligatorio (mínimo '
                    . $minReasonLength . ' caracteres).',
            ];
        }

        return ['allowed' => true, 'message' => null];
    }

    /**
     * @param array<string, mixed> $auth Contexto JWT (`user_id`).
     * @return array{allowed:bool,message:?string,overdue_balance:string}
     */
    public static function assertCanAuthorize(
        array $auth,
        bool $withException,
        string $reasonOrNotes,
        int $contractId,
        int $minReasonLength = 5,
    ): array {
        $overdue = self::currentOverdueBalance($contractId);
        $userId = (int) ($auth['user_id'] ?? 0);
        $canAuthorize = $userId > 0 && self::userCanAuthorize($userId);
        $gate = self::evaluatePermission(
            $canAuthorize,
            $withException,
            $reasonOrNotes,
            $minReasonLength,
        );

        return [
            'allowed' => $gate['allowed'],
            'message' => $gate['message'],
            'overdue_balance' => $overdue,
        ];
    }

    public static function userCanAuthorize(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $flag = Capsule::table('users')
            ->where('id', $userId)
            ->value('can_authorize_exceptions');

        return (bool) $flag;
    }

    public static function currentOverdueBalance(int $contractId): string
    {
        if ($contractId < 1) {
            return '0.00';
        }

        $raw = Capsule::table('collection_statuses')
            ->where('contract_id', $contractId)
            ->value('overdue_balance');

        return FinancialCalculator::normalizeMoney((string) ($raw ?? '0'));
    }
}
