<?php

declare(strict_types=1);

namespace System\Support\Integrations;

/**
 * Puerto de la pasarela de cobro con tarjeta (Pág. 36 del PDF).
 *
 * Los servicios de negocio dependen de esta interfaz (no del adaptador concreto) para poder
 * sustituir IntegroPAY por un doble de pruebas o por otra pasarela sin tocar la lógica.
 */
interface PaymentGatewayInterface
{
    /**
     * Crea la liga de pago y persiste `payment_links` (+ `payment_link_documents` si aplica).
     *
     * @param array{
     *   issuer_id: int,
     *   client_id: int,
     *   contract_id: int|null,
     *   purchase_id?: int|null,
     *   amount: string,
     *   currency?: string,
     *   concept: string,
     *   documents?: list<array{invoice_id: int, amount: string}>
     * } $params
     * @return array{
     *   payment_link_id: int,
     *   external_link_id: string,
     *   url: string,
     *   amount: string,
     *   expires_at: string,
     *   is_sandbox: bool
     * }
     */
    public function createPaymentLink(array $params): array;

    /**
     * Consulta la transacción por ID directamente contra la pasarela (o contra `payment_links`
     * en sandbox). Jamás se confía en el payload del webhook para estado ni monto.
     *
     * @return array{
     *   transaction_id: string,
     *   external_link_id: string|null,
     *   status: string,
     *   approved: bool,
     *   amount: string,
     *   is_sandbox: bool
     * }
     */
    public function verifyTransactionById(int $issuerId, string $transactionId): array;

    /** ¿El emisor cobra en sandbox (sin credenciales productivas o servicio global en sandbox)? */
    public function isSandboxForIssuer(int $issuerId): bool;
}
