<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Siembra los 5 servicios externos globales en modo sandbox (RNF-8).
 * Idempotente: inserta solo los faltantes y refresca `display_name`; nunca pisa
 * la configuración que un administrador ya haya ajustado desde el panel.
 * Ningún valor aquí es un secreto: `secret_refs_json` solo nombra variables de entorno.
 */
final class ExternalServicesSeeder extends AbstractSeed
{
    public function run(): void
    {
        // Columnas TIMESTAMP: sin sesión UTC, Phinx usa la zona SYSTEM y las marcas quedan desfasadas (RNF-11).
        $this->execute("SET time_zone = '+00:00'");
        // Recorta marcas "futuras" sembradas antes con la sesión SYSTEM (idempotente).
        $this->execute(
            'UPDATE external_service_configs
             SET created_at = LEAST(created_at, UTC_TIMESTAMP()),
                 updated_at = LEAST(updated_at, UTC_TIMESTAMP())
             WHERE created_at > UTC_TIMESTAMP() OR updated_at > UTC_TIMESTAMP()',
        );
        $nowUtc = gmdate('Y-m-d H:i:s');

        $services = [
            [
                'service_key' => 'smtp',
                'display_name' => 'Correo electrónico (SMTP)',
                'config' => [
                    'provider' => 'smtp',
                    'host' => 'smtp.example.com',
                    'port' => 587,
                    'encryption' => 'tls',
                    'username' => '',
                    'from_email' => 'cobranza@operam.mx',
                    'from_name' => 'Operam Suscripciones',
                ],
                'secret_refs' => ['password_ref' => 'SMTP_PASSWORD_REF'],
            ],
            [
                'service_key' => 'erp_master',
                'display_name' => 'ERP Master (Control de instancias)',
                'config' => [
                    'base_url' => '',
                    'timeout_seconds' => 15,
                ],
                'secret_refs' => ['api_key_ref' => 'ERP_MASTER_API_KEY_REF'],
            ],
            [
                'service_key' => 'slack',
                'display_name' => 'Alertas de monitoreo (Slack)',
                'config' => [
                    'channel_label' => '#alertas-suscripciones',
                    'failure_threshold' => 3,
                ],
                'secret_refs' => ['webhook_url_ref' => 'SLACK_OUTBOX_WEBHOOK_REF'],
            ],
            [
                'service_key' => 'banxico',
                'display_name' => 'Tipo de cambio (Banxico SIE)',
                'config' => [
                    'api_url' => 'https://www.banxico.org.mx/SieAPIRest/service/v1/series/SF43718/datos/oportuno',
                    'series_id' => 'SF43718',
                ],
                'secret_refs' => ['token_ref' => 'BANXICO_SIE_TOKEN_REF'],
            ],
            [
                'service_key' => 'jobs_outbox',
                'display_name' => 'Jobs y Worker de Outbox (GCP)',
                'config' => [
                    'timezone' => 'America/Mexico_City',
                    'max_attempts' => 3,
                    'retry_backoff_minutes' => 5,
                    'daily_billing_hour' => '06:00',
                    'daily_collections_hour' => '07:00',
                ],
                'secret_refs' => ['job_secret_ref' => 'GCP_JOB_SECRET_REF'],
            ],
        ];

        $table = $this->table('external_service_configs');
        foreach ($services as $service) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id, display_name FROM external_service_configs WHERE service_key = '%s'",
                $this->quote($service['service_key']),
            ));

            if ($existing) {
                if ((string) $existing['display_name'] === $service['display_name']) {
                    continue;
                }
                $this->execute(sprintf(
                    "UPDATE external_service_configs SET display_name = '%s', updated_at = '%s' WHERE id = %d",
                    $this->quote($service['display_name']),
                    $nowUtc,
                    (int) $existing['id'],
                ));
                continue;
            }

            $table->insert([
                'service_key' => $service['service_key'],
                'display_name' => $service['display_name'],
                'enabled' => 0,
                'mode' => 'sandbox',
                'config_json' => json_encode($service['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'secret_refs_json' => json_encode($service['secret_refs'], JSON_UNESCAPED_UNICODE),
                'last_tested_at' => null,
                'last_test_status' => 'never',
                'last_test_message' => null,
                'updated_by_user_id' => null,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
        }
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
