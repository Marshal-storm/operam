<?php
/**
 * Seeder idempotente — Catálogos, Instancias, Listas de Precios y Promociones (Módulo 2).
 * Depende de segmentos/emisores del Dashboard seeder.
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

        $pilotoId = $this->upsertEmisor([
            'nombre' => 'Piloto Automatico SA de CV',
            'rfc' => 'PIL010101PA1',
            'api_url' => 'https://api.piloto.example',
            'activo' => 1,
        ], $nowUtc);

        $cibusId = $this->upsertEmisor([
            'nombre' => 'Cibus Cloud SA de CV',
            'rfc' => 'CIB010101CB1',
            'api_url' => 'https://api.cibus.example',
            'activo' => 1,
        ], $nowUtc);

        $serviceIds = $this->upsertServices([
            [
                'codigo' => 'OP-BASE',
                'nombre' => 'Licencia Base Operam ERP',
                'descripcion' => 'Licencia recurrente base del ERP Operam',
                'segmento_id' => $operamId,
                'emisor_default_id' => $pilotoId,
                'tipo' => 'recurrente',
                'unidad_medida' => 'licencia',
                'permite_conversion_paquete' => 0,
            ],
            [
                'codigo' => 'OP-USR',
                'nombre' => 'Usuario Individual Operam',
                'descripcion' => 'Usuario suelto; RF-2 convierte cada 5 en paquete',
                'segmento_id' => $operamId,
                'emisor_default_id' => $pilotoId,
                'tipo' => 'recurrente',
                'unidad_medida' => 'usuario',
                'permite_conversion_paquete' => 1,
            ],
            [
                'codigo' => 'OP-PKG5',
                'nombre' => 'Paquete 5 Usuarios Operam',
                'descripcion' => 'Paquete con ahorro vs 5 usuarios sueltos',
                'segmento_id' => $operamId,
                'emisor_default_id' => $pilotoId,
                'tipo' => 'recurrente',
                'unidad_medida' => 'paquete_5_usuarios',
                'permite_conversion_paquete' => 0,
            ],
            [
                'codigo' => 'CS-OBRA',
                'nombre' => 'Licencia Obra Construsync',
                'descripcion' => 'Licencia por obra / proyecto Construsync',
                'segmento_id' => $construId,
                'emisor_default_id' => $cibusId,
                'tipo' => 'recurrente',
                'unidad_medida' => 'licencia',
                'permite_conversion_paquete' => 0,
            ],
            [
                'codigo' => 'SRV-HRS',
                'nombre' => 'Horas de Desarrollo / Implementación',
                'descripcion' => 'Bolsa de horas de implementación',
                'segmento_id' => $operamId,
                'emisor_default_id' => $pilotoId,
                'tipo' => 'unico',
                'unidad_medida' => 'hora',
                'permite_conversion_paquete' => 0,
            ],
            [
                'codigo' => 'SRV-TIM',
                'nombre' => 'Paquete de Timbres CFDI',
                'descripcion' => 'Consumo de timbres fiscales',
                'segmento_id' => $operamId,
                'emisor_default_id' => $cibusId,
                'tipo' => 'consumo',
                'unidad_medida' => 'timbre',
                'permite_conversion_paquete' => 0,
            ],
        ], $nowUtc);

        $this->upsertInstances([
            [
                'codigo' => 'ins-operam-norte',
                'nombre' => 'Operam Norte',
                'emisor_id' => $pilotoId,
                'segmento_id' => $operamId,
                'url_acceso' => 'https://norte.operam.example',
                'ip_servidor' => '189.10.20.31',
                'estado' => 'operativa',
            ],
            [
                'codigo' => 'ins-operam-centro',
                'nombre' => 'Operam Centro',
                'emisor_id' => $pilotoId,
                'segmento_id' => $operamId,
                'url_acceso' => 'https://centro.operam.example',
                'ip_servidor' => '189.10.20.32',
                'estado' => 'operativa',
            ],
            [
                'codigo' => 'ins-cibus-cloud',
                'nombre' => 'Cibus Cloud Shared',
                'emisor_id' => $cibusId,
                'segmento_id' => $operamId,
                'url_acceso' => 'https://cloud.cibus.example',
                'ip_servidor' => '52.10.8.90',
                'estado' => 'mantenimiento',
            ],
            [
                'codigo' => 'ins-construsync-01',
                'nombre' => 'Construsync Obra 01',
                'emisor_id' => $cibusId,
                'segmento_id' => $construId,
                'url_acceso' => 'https://obra01.construsync.example',
                'ip_servidor' => '201.150.40.11',
                'estado' => 'operativa',
            ],
        ], $nowUtc);

        $listIds = $this->upsertPriceLists([
            [
                'key' => 'operam_mxn',
                'segmento_id' => $operamId,
                'nombre' => 'Lista Oficial Operam 2026 (MXN)',
                'moneda' => 'MXN',
                'es_default' => 1,
                'vigente_desde' => '2026-01-01',
                'vigente_hasta' => null,
            ],
            [
                'key' => 'constru_mxn',
                'segmento_id' => $construId,
                'nombre' => 'Lista Construsync Enterprise 2026 (MXN)',
                'moneda' => 'MXN',
                'es_default' => 1,
                'vigente_desde' => '2026-01-01',
                'vigente_hasta' => null,
            ],
            [
                'key' => 'corp_usd',
                'segmento_id' => $operamId,
                'nombre' => 'Lista Corporativa USD',
                'moneda' => 'USD',
                'es_default' => 0,
                'vigente_desde' => '2026-01-01',
                'vigente_hasta' => null,
            ],
        ], $nowUtc);

        // RF-2: OP-USR y SRV-TIM con escalones por volumen; resto precio único 1+.
        // 5 usuarios sueltos ($180) = $900 vs paquete 5 ($750) → ahorro $150
        $this->upsertPriceItems([
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['OP-BASE'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 1,
                'cantidad_max' => null,
                'precio_unitario' => '2499.00',
            ],
            // OP-USR mensual: 1–5 / 6–20 / 21+
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['OP-USR'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 1,
                'cantidad_max' => 5,
                'precio_unitario' => '180.00',
            ],
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['OP-USR'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 6,
                'cantidad_max' => 20,
                'precio_unitario' => '160.00',
            ],
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['OP-USR'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 21,
                'cantidad_max' => null,
                'precio_unitario' => '140.00',
            ],
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['OP-PKG5'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 1,
                'cantidad_max' => null,
                'precio_unitario' => '750.00',
            ],
            // OP-USR anual: mismos rangos
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['OP-USR'],
                'frecuencia' => 'anual',
                'cantidad_min' => 1,
                'cantidad_max' => 5,
                'precio_unitario' => '1836.00',
            ],
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['OP-USR'],
                'frecuencia' => 'anual',
                'cantidad_min' => 6,
                'cantidad_max' => 20,
                'precio_unitario' => '1632.00',
            ],
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['OP-USR'],
                'frecuencia' => 'anual',
                'cantidad_min' => 21,
                'cantidad_max' => null,
                'precio_unitario' => '1428.00',
            ],
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['SRV-HRS'],
                'frecuencia' => 'unico',
                'cantidad_min' => 1,
                'cantidad_max' => null,
                'precio_unitario' => '950.00',
            ],
            // SRV-TIM: 1–99 / 100–499 / 500+
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['SRV-TIM'],
                'frecuencia' => 'unico',
                'cantidad_min' => 1,
                'cantidad_max' => 99,
                'precio_unitario' => '1.50',
            ],
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['SRV-TIM'],
                'frecuencia' => 'unico',
                'cantidad_min' => 100,
                'cantidad_max' => 499,
                'precio_unitario' => '1.20',
            ],
            [
                'lista_precio_id' => $listIds['operam_mxn'],
                'servicio_id' => $serviceIds['SRV-TIM'],
                'frecuencia' => 'unico',
                'cantidad_min' => 500,
                'cantidad_max' => null,
                'precio_unitario' => '0.95',
            ],
            [
                'lista_precio_id' => $listIds['constru_mxn'],
                'servicio_id' => $serviceIds['CS-OBRA'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 1,
                'cantidad_max' => null,
                'precio_unitario' => '3200.00',
            ],
            [
                'lista_precio_id' => $listIds['corp_usd'],
                'servicio_id' => $serviceIds['OP-BASE'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 1,
                'cantidad_max' => null,
                'precio_unitario' => '149.00',
            ],
            [
                'lista_precio_id' => $listIds['corp_usd'],
                'servicio_id' => $serviceIds['OP-USR'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 1,
                'cantidad_max' => null,
                'precio_unitario' => '12.00',
            ],
            [
                'lista_precio_id' => $listIds['corp_usd'],
                'servicio_id' => $serviceIds['OP-PKG5'],
                'frecuencia' => 'mensual',
                'cantidad_min' => 1,
                'cantidad_max' => null,
                'precio_unitario' => '50.00',
            ],
        ], $nowUtc);

        $this->upsertPromotions([
            [
                'codigo' => 'ANUAL2026',
                'nombre' => '15% descuento en frecuencia anual',
                'segmento_id' => $operamId,
                'tipo_descuento' => 'porcentaje',
                'valor_descuento' => '15.00',
                'aplica_a' => 'recurrente',
                'vigente_desde' => '2026-01-01',
                'vigente_hasta' => '2026-12-31',
                'usos_maximos' => 500,
                'usos_actuales' => 42,
            ],
            [
                'codigo' => 'ARRANQUE50',
                'nombre' => '$1,500.00 MXN descuento fijo de arranque',
                'segmento_id' => null,
                'tipo_descuento' => 'monto_fijo',
                'valor_descuento' => '1500.00',
                'aplica_a' => 'ambos',
                'vigente_desde' => '2026-01-01',
                'vigente_hasta' => '2026-06-30',
                'usos_maximos' => 100,
                'usos_actuales' => 18,
            ],
            [
                'codigo' => 'MIGRACION1M',
                'nombre' => '1 mes gratis por migración',
                'segmento_id' => $construId,
                'tipo_descuento' => 'meses_gratis',
                'valor_descuento' => '1.00',
                'aplica_a' => 'recurrente',
                'vigente_desde' => '2026-03-01',
                'vigente_hasta' => '2026-09-30',
                'usos_maximos' => null,
                'usos_actuales' => 7,
            ],
        ], $nowUtc);
    }

    private function segmentIdByName(string $name): int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM segmento WHERE nombre = '%s'",
            $this->quote($name)
        ));
        if (!$row) {
            throw new RuntimeException("Segmento no encontrado: {$name}");
        }

        return (int) $row['id'];
    }

    /** @param array<string, mixed> $data */
    private function upsertEmisor(array $data, string $nowUtc): int
    {
        $existing = $this->fetchRow(sprintf(
            "SELECT id FROM emisor WHERE rfc = '%s'",
            $this->quote((string) $data['rfc'])
        ));
        if ($existing) {
            return (int) $existing['id'];
        }

        $this->table('emisor')->insert([
            'nombre' => $data['nombre'],
            'rfc' => $data['rfc'],
            'api_url' => $data['api_url'],
            'activo' => $data['activo'],
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ])->saveData();

        $row = $this->fetchRow(sprintf(
            "SELECT id FROM emisor WHERE rfc = '%s'",
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
        $table = $this->table('servicio');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM servicio WHERE codigo = '%s'",
                $this->quote((string) $row['codigo'])
            ));
            if ($existing) {
                $ids[(string) $row['codigo']] = (int) $existing['id'];
                continue;
            }
            $table->insert([
                'segmento_id' => $row['segmento_id'],
                'emisor_default_id' => $row['emisor_default_id'],
                'codigo' => $row['codigo'],
                'nombre' => $row['nombre'],
                'descripcion' => $row['descripcion'],
                'tipo' => $row['tipo'],
                'unidad_medida' => $row['unidad_medida'],
                'permite_conversion_paquete' => $row['permite_conversion_paquete'],
                'activo' => 1,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
            $created = $this->fetchRow(sprintf(
                "SELECT id FROM servicio WHERE codigo = '%s'",
                $this->quote((string) $row['codigo'])
            ));
            $ids[(string) $row['codigo']] = (int) $created['id'];
        }

        return $ids;
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertInstances(array $rows, string $nowUtc): void
    {
        $table = $this->table('instancia');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM instancia WHERE codigo = '%s'",
                $this->quote((string) $row['codigo'])
            ));
            if ($existing) {
                continue;
            }
            $table->insert([
                'codigo' => $row['codigo'],
                'nombre' => $row['nombre'],
                'emisor_id' => $row['emisor_id'],
                'segmento_id' => $row['segmento_id'],
                'url_acceso' => $row['url_acceso'],
                'ip_servidor' => $row['ip_servidor'],
                'estado' => $row['estado'],
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
        $table = $this->table('lista_precio');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM lista_precio WHERE nombre = '%s' AND segmento_id = %d",
                $this->quote((string) $row['nombre']),
                (int) $row['segmento_id']
            ));
            if ($existing) {
                $ids[(string) $row['key']] = (int) $existing['id'];
                continue;
            }
            $table->insert([
                'segmento_id' => $row['segmento_id'],
                'nombre' => $row['nombre'],
                'moneda' => $row['moneda'],
                'es_default' => $row['es_default'],
                'vigente_desde' => $row['vigente_desde'],
                'vigente_hasta' => $row['vigente_hasta'],
                'activa' => 1,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
            $created = $this->fetchRow(sprintf(
                "SELECT id FROM lista_precio WHERE nombre = '%s' AND segmento_id = %d",
                $this->quote((string) $row['nombre']),
                (int) $row['segmento_id']
            ));
            $ids[(string) $row['key']] = (int) $created['id'];
        }

        return $ids;
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertPriceItems(array $rows, string $nowUtc): void
    {
        $table = $this->table('lista_precio_item');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM lista_precio_item WHERE lista_precio_id = %d AND servicio_id = %d AND frecuencia = '%s' AND cantidad_min = %d",
                (int) $row['lista_precio_id'],
                (int) $row['servicio_id'],
                $this->quote((string) $row['frecuencia']),
                (int) $row['cantidad_min']
            ));
            $maxSql = $row['cantidad_max'] === null
                ? 'NULL'
                : (string) (int) $row['cantidad_max'];
            if ($existing) {
                // Actualiza rango/precio (idempotente) para escalones RF-2.
                $this->execute(sprintf(
                    "UPDATE lista_precio_item SET cantidad_max = %s, precio_unitario = '%s', updated_at = '%s' WHERE id = %d",
                    $maxSql,
                    $this->quote((string) $row['precio_unitario']),
                    $nowUtc,
                    (int) $existing['id']
                ));
                continue;
            }
            $table->insert([
                'lista_precio_id' => $row['lista_precio_id'],
                'servicio_id' => $row['servicio_id'],
                'frecuencia' => $row['frecuencia'],
                'cantidad_min' => $row['cantidad_min'],
                'cantidad_max' => $row['cantidad_max'],
                'precio_unitario' => $row['precio_unitario'],
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertPromotions(array $rows, string $nowUtc): void
    {
        $table = $this->table('promocion');
        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM promocion WHERE codigo = '%s'",
                $this->quote((string) $row['codigo'])
            ));
            if ($existing) {
                continue;
            }
            $table->insert([
                'codigo' => $row['codigo'],
                'nombre' => $row['nombre'],
                'segmento_id' => $row['segmento_id'],
                'tipo_descuento' => $row['tipo_descuento'],
                'valor_descuento' => $row['valor_descuento'],
                'aplica_a' => $row['aplica_a'],
                'vigente_desde' => $row['vigente_desde'],
                'vigente_hasta' => $row['vigente_hasta'],
                'usos_maximos' => $row['usos_maximos'],
                'usos_actuales' => $row['usos_actuales'],
                'activa' => 1,
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
