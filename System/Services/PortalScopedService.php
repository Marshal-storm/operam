<?php

declare(strict_types=1);

namespace System\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use System\Core\Context;

/**
 * Base de los servicios del Portal del Cliente (`/api/portal/*`).
 *
 * Row-Level Security (RNF-7): el `client_id` proviene EXCLUSIVAMENTE del JWT `client_portal`
 * inyectado por la guardia de `AppService` (`portal_auth`); nunca de parámetros del cliente.
 */
abstract class PortalScopedService extends CustomService
{
    /** Estados de documento con saldo por cobrar (pagables desde el portal). */
    public const OPEN_INVOICE_STATUSES = ['pending', 'partial', 'overdue'];

    /** @var array<string, mixed> */
    protected array $claims = [];

    private ?object $clientRow = null;

    /** Carga los claims del portal; false si la sesión no es válida. */
    protected function bootClaims(Context $context): bool
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $context->getData('portal_auth');
        if (!is_array($claims) || (int) ($claims['client_id'] ?? 0) < 1) {
            return false;
        }
        $this->claims = $claims;
        $this->clientRow = null;

        return true;
    }

    protected function clientId(): int
    {
        return (int) $this->claims['client_id'];
    }

    protected function client(): ?object
    {
        return $this->clientRow ??= Capsule::table('clients')->where('id', $this->clientId())->first();
    }

    protected function clientSegmentId(): int
    {
        return (int) ($this->client()?->segment_id ?? 0);
    }

    /**
     * Contrato propio del cliente (mismo null para ajeno o inexistente: no revela ids).
     */
    protected function ownedContract(int $contractId): ?object
    {
        if ($contractId < 1) {
            return null;
        }
        $contract = Capsule::table('contracts')->where('id', $contractId)->first();
        if ($contract === null || (int) $contract->client_id !== $this->clientId()) {
            return null;
        }

        return $contract;
    }

    protected static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            $n = (int) trim($value);

            return $n > 0 ? $n : null;
        }

        return null;
    }
}
