<?php

declare(strict_types=1);

namespace System\Support\Integrations;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use Throwable;

/**
 * "Probar conexión" del panel de Servicios externos.
 *
 * - Sandbox (o servicio deshabilitado): valida que la configuración esté completa, sin tráfico real.
 * - Producción: ejecuta una llamada mínima real al proveedor con el secreto resuelto del entorno.
 * Los mensajes devueltos nunca incluyen valores de secretos.
 */
final class ExternalServiceTester
{
    private const DEFAULT_TIMEOUT = 15;

    public function __construct(private readonly IntegrationTransport $transport = new DefaultIntegrationTransport())
    {
    }

    /**
     * @return array{status: 'ok'|'error', message: string, sandbox: bool, details: array<string, mixed>}
     */
    public function test(string $serviceKey): array
    {
        ExternalServiceRegistry::clearCache();
        $service = ExternalServiceRegistry::find($serviceKey);
        if ($service === null) {
            return $this->result('error', 'El servicio no está sembrado en external_service_configs.', true);
        }

        $missingRefs = $this->missingSecretRefs($serviceKey, $service['secret_refs']);

        if (!ExternalServiceRegistry::isProduction($serviceKey)) {
            $note = $missingRefs === []
                ? 'Referencias de secreto disponibles en el entorno.'
                : 'Pendiente en .env: ' . implode(', ', $missingRefs) . '.';

            return $this->result('ok', 'Simulación correcta (sandbox). ' . $note, true, [
                'missing_secret_refs' => $missingRefs,
            ]);
        }

        try {
            return match ($serviceKey) {
                'smtp' => $this->testSmtp(),
                'erp_master' => $this->testErpMaster(),
                'slack' => $this->testSlack(),
                'banxico' => $this->testBanxico(),
                'jobs_outbox' => $this->testJobsOutbox($missingRefs),
                'integropay' => $this->testIntegroPay(),
                default => $this->result('error', 'Servicio sin probador.', false),
            };
        } catch (Throwable $e) {
            return $this->result('error', mb_substr($e->getMessage(), 0, 240), false);
        }
    }

    /**
     * Extrae el dato oportuno de la respuesta Banxico SIE (`bmx.series[0].datos[0]`).
     *
     * @return array{rate_date: string, rate: string}
     */
    public static function parseBanxicoRate(mixed $body): array
    {
        $datos = is_array($body) ? ($body['bmx']['series'][0]['datos'] ?? null) : null;
        $last = is_array($datos) && $datos !== [] ? end($datos) : null;
        if (!is_array($last)) {
            throw new RuntimeException('Respuesta de Banxico sin datos de la serie.');
        }

        $rawRate = str_replace(',', '', trim((string) ($last['dato'] ?? '')));
        if (preg_match('/^\d+(\.\d+)?$/', $rawRate) !== 1) {
            throw new RuntimeException('Banxico devolvió un tipo de cambio no numérico: ' . mb_substr($rawRate, 0, 20));
        }
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', trim((string) ($last['fecha'] ?? '')));
        if ($date === false) {
            throw new RuntimeException('Banxico devolvió una fecha inválida.');
        }

        return [
            'rate_date' => $date->format('Y-m-d'),
            'rate' => bcadd($rawRate, '0', 4),
        ];
    }

    private function testSmtp(): array
    {
        $smtp = OutboxDispatcher::smtpSettings();
        if ($smtp['host'] === '' || $smtp['from_email'] === '') {
            throw new RuntimeException('Configura host y remitente (from_email) antes de probar.');
        }
        if ($smtp['username'] !== '' && $smtp['password'] === null) {
            throw new RuntimeException('La referencia de contraseña SMTP no está definida en el entorno.');
        }
        $this->transport->probeSmtp($smtp);

        return $this->result('ok', sprintf('Sesión SMTP establecida con %s:%d.', $smtp['host'], $smtp['port']), false);
    }

    private function testErpMaster(): array
    {
        $baseUrl = (string) ExternalServiceRegistry::configValue('erp_master', 'base_url', '');
        if ($baseUrl === '') {
            throw new RuntimeException('Configura la URL base del ERP Master antes de probar.');
        }
        $apiKey = ExternalServiceRegistry::resolveSecret('erp_master', 'api_key_ref');
        if ($apiKey === null) {
            throw new RuntimeException('La referencia de API key del ERP Master no está definida en el entorno.');
        }
        $timeout = (int) ExternalServiceRegistry::configValue('erp_master', 'timeout_seconds', self::DEFAULT_TIMEOUT);

        try {
            $response = $this->transport->getJson($baseUrl, ['Authorization' => 'Bearer ' . $apiKey], $timeout);

            return $this->result('ok', sprintf('ERP Master respondió HTTP %d.', $response['status']), false);
        } catch (RuntimeException $e) {
            // 4xx distinto de 401/403 indica servidor alcanzable aunque la ruta raíz no exista.
            if (preg_match('/^HTTP (4\d\d)/', $e->getMessage(), $m) === 1 && !in_array($m[1], ['401', '403'], true)) {
                return $this->result('ok', sprintf('ERP Master alcanzable (HTTP %s en la ruta base).', $m[1]), false);
            }
            throw $e;
        }
    }

    private function testSlack(): array
    {
        $webhook = ExternalServiceRegistry::resolveSecret('slack', 'webhook_url_ref');
        if ($webhook === null) {
            throw new RuntimeException('La referencia del webhook de Slack no está definida en el entorno.');
        }
        $channel = (string) ExternalServiceRegistry::configValue('slack', 'channel_label', '');
        $this->transport->postJson($webhook, [], [
            'text' => ':white_check_mark: Prueba de conexión del monitor de Outbox de Operam.',
        ], self::DEFAULT_TIMEOUT);

        return $this->result('ok', 'Mensaje de prueba enviado' . ($channel !== '' ? ' a ' . $channel : '') . '.', false);
    }

    private function testBanxico(): array
    {
        $apiUrl = (string) ExternalServiceRegistry::configValue('banxico', 'api_url', '');
        if ($apiUrl === '') {
            throw new RuntimeException('Configura la URL de la serie Banxico antes de probar.');
        }
        $token = ExternalServiceRegistry::resolveSecret('banxico', 'token_ref');
        if ($token === null) {
            throw new RuntimeException('La referencia del token Banxico SIE no está definida en el entorno.');
        }

        $response = $this->transport->getJson($apiUrl, ['Bmx-Token' => $token], self::DEFAULT_TIMEOUT);
        $parsed = self::parseBanxicoRate($response['body']);

        Capsule::table('exchange_rates')->insertOrIgnore([
            'rate_date' => $parsed['rate_date'],
            'currency' => 'USD',
            'rate' => $parsed['rate'],
            'source' => 'banxico_sie',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $this->result(
            'ok',
            sprintf('Tipo de cambio FIX %s: %s MXN/USD (guardado en exchange_rates).', $parsed['rate_date'], $parsed['rate']),
            false,
            $parsed,
        );
    }

    /**
     * IntegroPAY: la URL base es global; las credenciales se validan por emisor al cobrar.
     * Un 4xx distinto de 401/403 en la raíz confirma que la pasarela es alcanzable.
     */
    private function testIntegroPay(): array
    {
        $apiUrl = (string) ExternalServiceRegistry::configValue('integropay', 'api_url', '');
        if ($apiUrl === '') {
            throw new RuntimeException('Configura la URL base de la API de IntegroPAY antes de probar.');
        }
        $timeout = (int) ExternalServiceRegistry::configValue('integropay', 'timeout_seconds', self::DEFAULT_TIMEOUT);
        $configured = Capsule::table('issuers')
            ->where('is_active', 1)
            ->whereNotNull('integropay_merchant_token_ref')
            ->get(['integropay_merchant_token_ref', 'integropay_device_token_ref'])
            ->filter(static fn (object $row): bool => SecretResolver::isConfigured((string) $row->integropay_merchant_token_ref)
                && SecretResolver::isConfigured((string) ($row->integropay_device_token_ref ?? '')))
            ->count();

        try {
            $response = $this->transport->getJson($apiUrl, [], $timeout);
            $status = $response['status'];
        } catch (RuntimeException $e) {
            if (preg_match('/^HTTP (4\d\d)/', $e->getMessage(), $m) !== 1 || in_array($m[1], ['401', '403'], true)) {
                throw $e;
            }
            $status = (int) $m[1];
        }

        return $this->result(
            'ok',
            sprintf('IntegroPAY alcanzable (HTTP %d). Emisores con credenciales productivas: %d.', $status, $configured),
            false,
            ['issuers_with_credentials' => $configured],
        );
    }

    /** @param list<string> $missingRefs */
    private function testJobsOutbox(array $missingRefs): array
    {
        if ($missingRefs !== []) {
            throw new RuntimeException('Pendiente en .env: ' . implode(', ', $missingRefs) . '.');
        }
        $pending = (int) Capsule::table('outbox')->whereIn('status', ['pending', 'failed'])->count();

        return $this->result('ok', sprintf('Worker listo. %d mensaje(s) en cola por procesar.', $pending), false, [
            'queued' => $pending,
        ]);
    }

    /**
     * @param array<string, string> $refs
     * @return list<string>
     */
    private function missingSecretRefs(string $serviceKey, array $refs): array
    {
        $missing = [];
        foreach (ExternalServiceRegistry::secretFields($serviceKey) as $field) {
            $ref = (string) ($refs[$field] ?? '');
            if ($ref === '' || !SecretResolver::isConfigured($ref)) {
                $missing[] = $ref !== '' ? $ref : $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $details
     * @return array{status: 'ok'|'error', message: string, sandbox: bool, details: array<string, mixed>}
     */
    private function result(string $status, string $message, bool $sandbox, array $details = []): array
    {
        return [
            'status' => $status === 'ok' ? 'ok' : 'error',
            'message' => mb_substr($message, 0, 255),
            'sandbox' => $sandbox,
            'details' => $details,
        ];
    }
}
