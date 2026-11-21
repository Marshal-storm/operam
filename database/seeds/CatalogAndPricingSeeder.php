<?php
/**
 * Seeder idempotente — Catálogos, Instancias, Listas de Precios y Promociones (Módulo 2).
 * Depende de segmentos y emisores del seeder de Dashboard.
 * Esquema 100% inglés. Nombres de producto, listas y promociones permanecen en español.
 * OP-USR queda ligado a OP-PKG5 (package_service_id, factor 5).
 */

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class CatalogAndPricingSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return [
            September2026DashboardSeeder::class,
        ];
    }

    public function run(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        $operamId = $this->segmentIdByName('Operam');
        $construId = $this->segmentIdByName('Construsync');

        $pilotoId = $this->upsertIssuer([
            'name' => 'Piloto Automatico SA de CV',
            'rfc' => 'PIL010101PA1',
            'api_url' => 'https://api.piloto.example',
            'is_active' => 1,
        ], $nowUtc);

        $cibusId = $this->upsertIssuer([
            'name' => 'Cibus Cloud SA de CV',
            'rfc' => 'CIB010101CB1',
            'api_url' => 'https://api.cibus.example',
            'is_active' => 1,
        ], $nowUtc);

        $serviceIds = $this->upsertServices([
            [
                'code' => 'OP-BASE',
                'name' => 'Licencia Base Operam ERP',
                'description' => 'Licencia recurrente base del ERP Operam',
                'segment_id' => $operamId,
                'default_issuer_id' => $pilotoId,
                'service_type' => 'recurring',
                'unit_of_measure' => 'license',
                'allow_package_conversion' => 0,
            ],
            [
                'code' => 'OP-USR',
                'name' => 'Usuario Individual Operam',
                'description' => 'Usuario suelto; RF-2 convierte cada 5 en paquete',
                'segment_id' => $operamId,
                'default_issuer_id' => $pilotoId,
                'service_type' => 'recurring',
                'unit_of_measure' => 'user',
                'allow_package_conversion' => 1,
            ],
            [
                'code' => 'OP-PKG5',
                'name' => 'Paquete 5 Usuarios Operam',
                'description' => 'Paquete con ahorro vs 5 usuarios sueltos',
                'segment_id' => $operamId,
                'default_issuer_id' => $pilotoId,
                'service_type' => 'recurring',
                'unit_of_measure' => 'pack_5_users',
                'allow_package_conversion' => 0,
            ],
            [
                'code' => 'CS-OBRA',
                'name' => 'Licencia Obra Construsync',
                'description' => 'Licencia por obra / proyecto Construsync',
                'segment_id' => $construId,
                'default_issuer_id' => $cibusId,
                'service_type' => 'recurring',
                'unit_of_measure' => 'license',
                'allow_package_conversion' => 0,
            ],
            [
                'code' => 'SRV-HRS',
                'name' => 'Horas de Desarrollo / Implementación',
                'description' => 'Bolsa de horas de implementación',
                'segment_id' => $operamId,
                'default_issuer_id' => $pilotoId,
                'service_type' => 'single',
                'unit_of_measure' => 'hour',
                'allow_package_conversion' => 0,
            ],
            [
                'code' => 'SRV-TIM',
                'name' => 'Paquete de Timbres CFDI',
                'description' => 'Consumo de timbres fiscales',
                'segment_id' => $operamId,
                'default_issuer_id' => $cibusId,
                'service_type' => 'consumption',
                'unit_of_measure' => 'stamp',
                'allow_package_conversion' => 0,
            ],
        ], $nowUtc);

        // RF-2: OP-USR apunta al paquete OP-PKG5 (factor 5). Se reafirma en cada corrida.
        if (isset($serviceIds['OP-USR'], $serviceIds['OP-PKG5'])) {
            $this->execute(sprintf(
                "UPDATE services SET package_service_id = %d, package_factor = 5, allow_package_conversion = 1, updated_at = '%s' WHERE id = %d",
                (int) $serviceIds['OP-PKG5'],
                $this->quote($nowUtc),
                (int) $serviceIds['OP-USR'],
            ));
            // El paquete destino NUNCA convierte a sí mismo (evita confusión RF-2).
            $this->execute(sprintf(
                "UPDATE services SET allow_package_conversion = 0, package_service_id = NULL, updated_at = '%s' WHERE id = %d",
                $this->quote($nowUtc),
                (int) $serviceIds['OP-PKG5'],
            ));
        }

        $this->upsertInstances([
            [
                'code' => 'ins-operam-norte',
                'name' => 'Operam Norte',
                'issuer_id' => $pilotoId,
                'segment_id' => $operamId,
                'access_url' => 'https://norte.operam.example',
                'server_ip' => '189.10.20.31',
                'status' => 'operational',
            ],
            [
                'code' => 'ins-operam-centro',
                'name' => 'Operam Centro',
                'issuer_id' => $pilotoId,
                'segment_id' => $operamId,
                'access_url' => 'https://centro.operam.example',
                'server_ip' => '189.10.20.32',
                'status' => 'operational',
            ],
            [
                'code' => 'ins-cibus-cloud',
                'name' => 'Cibus Cloud Shared',
                'issuer_id' => $cibusId,
                'segment_id' => $operamId,
                'access_url' => 'https://cloud.cibus.example',
                'server_ip' => '52.10.8.90',
                'status' => 'maintenance',
            ],
            [
                'code' => 'ins-construsync-01',
                'name' => 'Construsync Obra 01',
                'issuer_id' => $cibusId,
                'segment_id' => $construId,
                'access_url' => 'https://obra01.construsync.example',
                'server_ip' => '201.150.40.11',
                'status' => 'operational',
            ],
        ], $nowUtc);

        $listIds = $this->upsertPriceLists([
            [
                'key' => 'operam_mxn',
                'segment_id' => $operamId,
                'name' => 'Lista Oficial Operam 2026 (MXN)',
                'currency' => 'MXN',
                'is_default' => 1,
                'valid_from' => '2026-01-01',
                'valid_until' => null,
            ],
            [
                'key' => 'constru_mxn',
                'segment_id' => $construId,
                'name' => 'Lista Construsync Enterprise 2026 (MXN)',
                'currency' => 'MXN',
                'is_default' => 1,
                'valid_from' => '2026-01-01',
                'valid_until' => null,
            ],
            [
                'key' => 'corp_usd',
                'segment_id' => $operamId,
                'name' => 'Lista Corporativa USD',
                'currency' => 'USD',
                'is_default' => 0,
                'valid_from' => '2026-01-01',
                'valid_until' => null,
            ],
        ], $nowUtc);

        // RF-2: OP-USR y SRV-TIM con escalones por volumen; resto precio único 1+.
        // 5 usuarios sueltos ($180) = $900 vs paquete 5 ($750) → ahorro $150.
        $this->upsertPriceItems([
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['OP-BASE'],
                'frequency' => 'monthly',
                'min_quantity' => 1,
                'max_quantity' => null,
                'unit_price' => '2499.00',
            ],
            // OP-USR mensual: 1–5 / 6–20 / 21+
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['OP-USR'],
                'frequency' => 'monthly',
                'min_quantity' => 1,
                'max_quantity' => 5,
                'unit_price' => '180.00',
            ],
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['OP-USR'],
                'frequency' => 'monthly',
                'min_quantity' => 6,
                'max_quantity' => 20,
                'unit_price' => '160.00',
            ],
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['OP-USR'],
                'frequency' => 'monthly',
                'min_quantity' => 21,
                'max_quantity' => null,
                'unit_price' => '140.00',
            ],
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['OP-PKG5'],
                'frequency' => 'monthly',
                'min_quantity' => 1,
                'max_quantity' => null,
                'unit_price' => '750.00',
            ],
            // OP-USR anual: mismos rangos
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['OP-USR'],
                'frequency' => 'annual',
                'min_quantity' => 1,
                'max_quantity' => 5,
                'unit_price' => '1836.00',
            ],
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['OP-USR'],
                'frequency' => 'annual',
                'min_quantity' => 6,
                'max_quantity' => 20,
                'unit_price' => '1632.00',
            ],
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['OP-USR'],
                'frequency' => 'annual',
                'min_quantity' => 21,
                'max_quantity' => null,
                'unit_price' => '1428.00',
            ],
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['SRV-HRS'],
                'frequency' => 'single',
                'min_quantity' => 1,
                'max_quantity' => null,
                'unit_price' => '950.00',
            ],
            // SRV-TIM: 1–99 / 100–499 / 500+
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['SRV-TIM'],
                'frequency' => 'single',
                'min_quantity' => 1,
                'max_quantity' => 99,
                'unit_price' => '1.50',
            ],
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['SRV-TIM'],
                'frequency' => 'single',
                'min_quantity' => 100,
                'max_quantity' => 499,
                'unit_price' => '1.20',
            ],
            [
                'price_list_id' => $listIds['operam_mxn'],
                'service_id' => $serviceIds['SRV-TIM'],
                'frequency' => 'single',
                'min_quantity' => 500,
                'max_quantity' => null,
                'unit_price' => '0.95',
            ],
            [
                'price_list_id' => $listIds['constru_mxn'],
                'service_id' => $serviceIds['CS-OBRA'],
                'frequency' => 'monthly',
                'min_quantity' => 1,
                'max_quantity' => null,
                'unit_price' => '3200.00',
            ],
            [
                'price_list_id' => $listIds['corp_usd'],
                'service_id' => $serviceIds['OP-BASE'],
                'frequency' => 'monthly',
                'min_quantity' => 1,
                'max_quantity' => null,
                'unit_price' => '149.00',
            ],
            [
                'price_list_id' => $listIds['corp_usd'],
                'service_id' => $serviceIds['OP-USR'],
                'frequency' => 'monthly',
                'min_quantity' => 1,
                'max_quantity' => null,
                'unit_price' => '12.00',
            ],
            [
                'price_list_id' => $listIds['corp_usd'],
                'service_id' => $serviceIds['OP-PKG5'],
                'frequency' => 'monthly',
                'min_quantity' => 1,
                'max_quantity' => null,
                'unit_price' => '50.00',
            ],
        ], $nowUtc);

        $this->upsertPromotions([
            [
                'code' => 'ANUAL2026',
                'name' => '15% descuento en frecuencia anual',
                'segment_id' => $operamId,
                'discount_type' => 'percentage',
                'discount_value' => '15.00',
                'applies_to' => 'recurring',
                'valid_from' => '2026-01-01',
                'valid_until' => '2026-12-31',
                'max_uses' => 500,
                'current_uses' => 42,
            ],
            [
                'code' => 'ARRANQUE50',
                'name' => '$1,500.00 MXN descuento fijo de arranque',
                'segment_id' => null,
                'discount_type' => 'fixed_amount',
                'discount_value' => '1500.00',
                'applies_to' => 'both',
                'valid_from' => '2026-01-01',
                'valid_until' => '2026-06-30',
                'max_uses' => 100,
                'current_uses' => 18,
            ],
            [
                'code' => 'MIGRACION1M',
                'name' => '1 mes gratis por migración',
                'segment_id' => $construId,
                'discount_type' => 'free_months',
                'discount_value' => '1.00',
                'applies_to' => 'recurring',
                'valid_from' => '2026-03-01',
                'valid_until' => '2026-09-30',
                'max_uses' => null,
                'current_uses' => 7,
            ],
        ], $nowUtc);
    }

    private function segmentIdByName(string $name): int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM segments WHERE name = '%s'",
            $this->quote($name)
        ));
        if (!$row) {
            throw new RuntimeException("Segmento no encontrado: {$name}");
        }

        return (int) $row['id'];
    }

    /** @param array<string, mixed> $data */
    private function upsertIssuer(array $data, string $nowUtc): int
    {
        $existing = $this->fetchRow(sprintf(
            "SELECT id FROM issuers WHERE rfc = '%s'",
            $this->quote((string) $data['rfc'])
        ));
        if ($existing) {
            return (int) $existing['id'];
        }

        $this->table('issuers')->insert([
            'name' => $data['name'],
            'rfc' => $data['rfc'],
            'api_url' => $data['api_url'],
            'is_active' => $data['is_active'],
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ])->saveData();

        $row = $this->fetchRow(sprintf(
            "SELECT id FROM issuers WHERE rfc = '%s'",
            $this->quote((string) $data['rfc'])
        ));

        return (int) $row['id'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function upsertServices(array $rows, string $nowUtc): array
    {
        $ids = [];
        $table = $this->table('services');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM services WHERE code = '%s'",
                $this->quote((string) $row['code'])
            ));
            if ($existing) {
                $ids[(string) $row['code']] = (int) $existing['id'];
                continue;
            }
            $table->insert([
                'segment_id' => $row['segment_id'],
                'default_issuer_id' => $row['default_issuer_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => $row['description'],
                'service_type' => $row['service_type'],
                'unit_of_measure' => $row['unit_of_measure'],
                'allow_package_conversion' => $row['allow_package_conversion'],
                'is_active' => 1,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
            $created = $this->fetchRow(sprintf(
                "SELECT id FROM services WHERE code = '%s'",
                $this->quote((string) $row['code'])
            ));
            $ids[(string) $row['code']] = (int) $created['id'];
        }

        return $ids;
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertInstances(array $rows, string $nowUtc): void
    {
        $table = $this->table('instances');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM instances WHERE code = '%s'",
                $this->quote((string) $row['code'])
            ));
            if ($existing) {
                continue;
            }
            $table->insert([
                'code' => $row['code'],
                'name' => $row['name'],
                'issuer_id' => $row['issuer_id'],
                'segment_id' => $row['segment_id'],
                'access_url' => $row['access_url'],
                'server_ip' => $row['server_ip'],
                'status' => $row['status'],
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function upsertPriceLists(array $rows, string $nowUtc): array
    {
        $ids = [];
        $table = $this->table('price_lists');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM price_lists WHERE name = '%s' AND segment_id = %d",
                $this->quote((string) $row['name']),
                (int) $row['segment_id']
            ));
            if ($existing) {
                $ids[(string) $row['key']] = (int) $existing['id'];
                continue;
            }
            $table->insert([
                'segment_id' => $row['segment_id'],
                'name' => $row['name'],
                'currency' => $row['currency'],
                'is_default' => $row['is_default'],
                'valid_from' => $row['valid_from'],
                'valid_until' => $row['valid_until'],
                'is_active' => 1,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
            $created = $this->fetchRow(sprintf(
                "SELECT id FROM price_lists WHERE name = '%s' AND segment_id = %d",
                $this->quote((string) $row['name']),
                (int) $row['segment_id']
            ));
            $ids[(string) $row['key']] = (int) $created['id'];
        }

        return $ids;
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertPriceItems(array $rows, string $nowUtc): void
    {
        $table = $this->table('price_list_items');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM price_list_items WHERE price_list_id = %d AND service_id = %d AND frequency = '%s' AND min_quantity = %d",
                (int) $row['price_list_id'],
                (int) $row['service_id'],
                $this->quote((string) $row['frequency']),
                (int) $row['min_quantity']
            ));
            $maxSql = $row['max_quantity'] === null
                ? 'NULL'
                : (string) (int) $row['max_quantity'];
            if ($existing) {
                // Actualiza rango y precio (idempotente) para los escalones RF-2.
                $this->execute(sprintf(
                    "UPDATE price_list_items SET max_quantity = %s, unit_price = '%s', updated_at = '%s' WHERE id = %d",
                    $maxSql,
                    $this->quote((string) $row['unit_price']),
                    $nowUtc,
                    (int) $existing['id']
                ));
                continue;
            }
            $table->insert([
                'price_list_id' => $row['price_list_id'],
                'service_id' => $row['service_id'],
                'frequency' => $row['frequency'],
                'min_quantity' => $row['min_quantity'],
                'max_quantity' => $row['max_quantity'],
                'unit_price' => $row['unit_price'],
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertPromotions(array $rows, string $nowUtc): void
    {
        $table = $this->table('promotions');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM promotions WHERE code = '%s'",
                $this->quote((string) $row['code'])
            ));
            if ($existing) {
                continue;
            }
            $table->insert([
                'code' => $row['code'],
                'name' => $row['name'],
                'segment_id' => $row['segment_id'],
                'discount_type' => $row['discount_type'],
                'discount_value' => $row['discount_value'],
                'applies_to' => $row['applies_to'],
                'valid_from' => $row['valid_from'],
                'valid_until' => $row['valid_until'],
                'max_uses' => $row['max_uses'],
                'current_uses' => $row['current_uses'],
                'is_active' => 1,
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
