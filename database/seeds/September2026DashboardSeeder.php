<?php
/**
 * Seeder idempotente — cierre septiembre 2026 (Dashboard / Puente de MRR).
 * Segmentos: Operam, Construsync, Piloto, Cibus.
 */

declare(strict_types=1);

use Operam\Support\Money;
use Phinx\Seed\AbstractSeed;

final class September2026DashboardSeeder extends AbstractSeed
{
    public function run(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        $segmentIds = $this->upsertSegmentos([
            ['nombre' => 'Operam', 'dias_credito_default' => 30],
            ['nombre' => 'Construsync', 'dias_credito_default' => 30],
            ['nombre' => 'Piloto', 'dias_credito_default' => 15],
            ['nombre' => 'Cibus', 'dias_credito_default' => 30],
        ], $nowUtc);

        $emisorId = $this->upsertEmisor([
            'nombre' => 'Operam SA de CV',
            'rfc' => 'OPE010101AAA',
            'api_url' => null,
            'activo' => 1,
        ], $nowUtc);

        $clienteIds = $this->upsertClientes([
            [
                'key' => 'A',
                'emisor_id' => $emisorId,
                'erp_cliente_id' => 'ERP-A',
                'nombre' => '[Cliente A]',
                'rfc' => 'XAXX010101000',
                'razon_social' => '[Cliente A] SA de CV',
            ],
            [
                'key' => 'B',
                'emisor_id' => $emisorId,
                'erp_cliente_id' => 'ERP-B',
                'nombre' => '[Cliente B]',
                'rfc' => 'XAXX010101001',
                'razon_social' => '[Cliente B] SA de CV',
            ],
            [
                'key' => 'C',
                'emisor_id' => $emisorId,
                'erp_cliente_id' => 'ERP-C',
                'nombre' => '[Cliente C]',
                'rfc' => 'XAXX010101002',
                'razon_social' => '[Cliente C] SA de CV',
            ],
            [
                'key' => 'P',
                'emisor_id' => $emisorId,
                'erp_cliente_id' => 'ERP-P',
                'nombre' => '[Cliente Piloto]',
                'rfc' => 'XAXX010101003',
                'razon_social' => '[Cliente Piloto] SA de CV',
            ],
            [
                'key' => 'Z',
                'emisor_id' => $emisorId,
                'erp_cliente_id' => 'ERP-Z',
                'nombre' => '[Cliente Cibus]',
                'rfc' => 'XAXX010101004',
                'razon_social' => '[Cliente Cibus] SA de CV',
            ],
        ], $nowUtc);

        $contratoIds = $this->upsertContratos([
            [
                'folio' => 'CT-0412',
                'cliente_id' => $clienteIds['A'],
                'segmento_id' => $segmentIds['Operam'],
                'fecha_aniversario' => '2026-03-24',
                'proximo_corte' => '2026-10-24',
                'estado' => 'activo',
            ],
            [
                'folio' => 'CT-0598',
                'cliente_id' => $clienteIds['B'],
                'segmento_id' => $segmentIds['Construsync'],
                'fecha_aniversario' => '2026-09-23',
                'proximo_corte' => '2026-10-23',
                'estado' => 'activo',
            ],
            [
                'folio' => 'CT-0217',
                'cliente_id' => $clienteIds['C'],
                'segmento_id' => $segmentIds['Operam'],
                'fecha_aniversario' => '2025-09-22',
                'proximo_corte' => null,
                'estado' => 'suspendido',
            ],
            [
                'folio' => 'CT-0701',
                'cliente_id' => $clienteIds['P'],
                'segmento_id' => $segmentIds['Piloto'],
                'fecha_aniversario' => '2026-01-10',
                'proximo_corte' => '2026-10-10',
                'estado' => 'activo',
            ],
            [
                'folio' => 'CT-0802',
                'cliente_id' => $clienteIds['Z'],
                'segmento_id' => $segmentIds['Cibus'],
                'fecha_aniversario' => '2026-05-05',
                'proximo_corte' => '2026-10-05',
                'estado' => 'activo',
            ],
        ], $nowUtc);

        $this->upsertMovimientos([
            [
                'contrato_id' => $contratoIds['CT-0412'],
                'tipo' => 'cambio_cantidad',
                'categoria_mrr' => 'expansion',
                'fecha_efectiva' => '2026-09-24',
                'cantidad_antes' => '8.00',
                'cantidad_despues' => '10.00',
                'mrr_antes' => '1512.50',
                'mrr_despues' => '1815.00',
                'mrr_delta_mxn' => '302.50',
                'motivo' => 'Alta de puestos adicionales',
            ],
            [
                'contrato_id' => $contratoIds['CT-0598'],
                'tipo' => 'alta',
                'categoria_mrr' => 'nuevo',
                'fecha_efectiva' => '2026-09-23',
                'cantidad_antes' => '0.00',
                'cantidad_despues' => '1.00',
                'mrr_antes' => '0.00',
                'mrr_despues' => '8900.00',
                'mrr_delta_mxn' => '8900.00',
                'motivo' => 'Alta comercial',
            ],
            [
                'contrato_id' => $contratoIds['CT-0217'],
                'tipo' => 'suspension',
                'categoria_mrr' => 'churn',
                'fecha_efectiva' => '2026-09-22',
                'cantidad_antes' => '1.00',
                'cantidad_despues' => '0.00',
                'mrr_antes' => '2450.00',
                'mrr_despues' => '0.00',
                'mrr_delta_mxn' => '-2450.00',
                'motivo' => 'Suspensión automática por mora',
            ],
            [
                'contrato_id' => $contratoIds['CT-0701'],
                'tipo' => 'alta',
                'categoria_mrr' => 'nuevo',
                'fecha_efectiva' => '2026-09-15',
                'cantidad_antes' => '0.00',
                'cantidad_despues' => '1.00',
                'mrr_antes' => '0.00',
                'mrr_despues' => '3200.00',
                'mrr_delta_mxn' => '3200.00',
                'motivo' => 'Alta Piloto septiembre',
            ],
            [
                'contrato_id' => $contratoIds['CT-0802'],
                'tipo' => 'cambio_cantidad',
                'categoria_mrr' => 'expansion',
                'fecha_efectiva' => '2026-09-12',
                'cantidad_antes' => '2.00',
                'cantidad_despues' => '3.00',
                'mrr_antes' => '4500.00',
                'mrr_despues' => '6750.00',
                'mrr_delta_mxn' => '2250.00',
                'motivo' => 'Expansión Cibus',
            ],
        ], $nowUtc);

        $this->upsertCobranza([
            [
                'contrato_id' => $contratoIds['CT-0412'],
                'saldo_vencido' => '0.00',
                'dias_vencido' => 0,
                'nivel_aviso' => 0,
                'suspendido' => 0,
            ],
            [
                'contrato_id' => $contratoIds['CT-0598'],
                'saldo_vencido' => '0.00',
                'dias_vencido' => 0,
                'nivel_aviso' => 0,
                'suspendido' => 0,
            ],
            [
                'contrato_id' => $contratoIds['CT-0217'],
                'saldo_vencido' => '2450.00',
                'dias_vencido' => 95,
                'nivel_aviso' => 4,
                'suspendido' => 1,
            ],
            [
                'contrato_id' => $contratoIds['CT-0701'],
                'saldo_vencido' => '0.00',
                'dias_vencido' => 0,
                'nivel_aviso' => 0,
                'suspendido' => 0,
            ],
            [
                'contrato_id' => $contratoIds['CT-0802'],
                'saldo_vencido' => '1200.00',
                'dias_vencido' => 18,
                'nivel_aviso' => 1,
                'suspendido' => 0,
            ],
        ], $nowUtc);

        // Puente de MRR sep-2026 por segmento (DECIMAL exacto).
        $bridgeBySegment = [
            'Operam' => [
                'inicio' => '780000.00',
                'nuevo' => '14000.00',
                'expansion' => '11000.00',
                'ajuste_precio' => '4000.00',
                'cambio_frecuencia' => '1200.00',
                'contraccion' => '-4500.00',
                'churn' => '-9000.00',
                'reactivacion' => '2500.00',
                'fin' => '799200.00',
            ],
            'Construsync' => [
                'inicio' => '474300.00',
                'nuevo' => '7600.00',
                'expansion' => '7200.00',
                'ajuste_precio' => '2100.00',
                'cambio_frecuencia' => '700.00',
                'contraccion' => '-2900.00',
                'churn' => '-5300.00',
                'reactivacion' => '1600.00',
                'fin' => '485300.00',
            ],
            'Piloto' => [
                'inicio' => '85000.00',
                'nuevo' => '3200.00',
                'expansion' => '1500.00',
                'ajuste_precio' => '0.00',
                'cambio_frecuencia' => '0.00',
                'contraccion' => '-800.00',
                'churn' => '-1200.00',
                'reactivacion' => '400.00',
                'fin' => '88100.00',
            ],
            'Cibus' => [
                'inicio' => '120000.00',
                'nuevo' => '4500.00',
                'expansion' => '2250.00',
                'ajuste_precio' => '500.00',
                'cambio_frecuencia' => '0.00',
                'contraccion' => '-900.00',
                'churn' => '-2100.00',
                'reactivacion' => '700.00',
                'fin' => '124950.00',
            ],
        ];

        $this->assertBridgeBalances($bridgeBySegment);
        $this->replaceMrrMensual('2026-09', $bridgeBySegment, $segmentIds, $emisorId, $nowUtc);
    }

    /**
     * Valida Inicio + deltas = Fin con brick/math (RNF-1), sin float.
     *
     * @param array<string, array<string, string>> $bridgeBySegment
     */
    private function assertBridgeBalances(array $bridgeBySegment): void
    {
        foreach ($bridgeBySegment as $segmentName => $categories) {
            $total = Money::of($categories['inicio']);
            foreach (['nuevo', 'expansion', 'ajuste_precio', 'cambio_frecuencia', 'contraccion', 'churn', 'reactivacion'] as $key) {
                $total = $total->plus(Money::of($categories[$key]));
            }

            if ($total->toDecimalString() !== Money::of($categories['fin'])->toDecimalString()) {
                throw new RuntimeException(
                    "Puente MRR inválido para {$segmentName}: acumulado {$total->toDecimalString()} != fin {$categories['fin']}"
                );
            }
        }
    }

    /**
     * @param array<int, array{nombre:string,dias_credito_default:int}> $rows
     * @return array<string,int>
     */
    private function upsertSegmentos(array $rows, string $nowUtc): array
    {
        $ids = [];
        $table = $this->table('segmento');

        foreach ($rows as $row) {
            $existing = $this->fetchRow(
                sprintf("SELECT id FROM segmento WHERE nombre = '%s'", $this->quote($row['nombre']))
            );

            if ($existing) {
                $ids[$row['nombre']] = (int) $existing['id'];
                continue;
            }

            $table->insert([
                'nombre' => $row['nombre'],
                'dias_credito_default' => $row['dias_credito_default'],
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();

            $created = $this->fetchRow(
                sprintf("SELECT id FROM segmento WHERE nombre = '%s'", $this->quote($row['nombre']))
            );
            $ids[$row['nombre']] = (int) $created['id'];
        }

        return $ids;
    }

    /**
     * @param array{nombre:string,rfc:string,api_url:?string,activo:int} $row
     */
    private function upsertEmisor(array $row, string $nowUtc): int
    {
        $existing = $this->fetchRow(
            sprintf("SELECT id FROM emisor WHERE rfc = '%s'", $this->quote($row['rfc']))
        );

        if ($existing) {
            return (int) $existing['id'];
        }

        $this->table('emisor')->insert([
            'nombre' => $row['nombre'],
            'rfc' => $row['rfc'],
            'api_url' => $row['api_url'],
            'activo' => $row['activo'],
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ])->saveData();

        $created = $this->fetchRow(
            sprintf("SELECT id FROM emisor WHERE rfc = '%s'", $this->quote($row['rfc']))
        );

        return (int) $created['id'];
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function upsertClientes(array $rows, string $nowUtc): array
    {
        $ids = [];
        $table = $this->table('cliente');

        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $existing = $this->fetchRow(
                sprintf(
                    "SELECT id FROM cliente WHERE erp_cliente_id = '%s'",
                    $this->quote((string) $row['erp_cliente_id'])
                )
            );

            if ($existing) {
                $ids[$key] = (int) $existing['id'];
                continue;
            }

            $table->insert([
                'emisor_id' => $row['emisor_id'],
                'erp_cliente_id' => $row['erp_cliente_id'],
                'nombre' => $row['nombre'],
                'rfc' => $row['rfc'],
                'razon_social' => $row['razon_social'],
                'dias_credito' => 30,
                'timbrar_al_emitir' => 1,
                'excluir_suspension' => 0,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();

            $created = $this->fetchRow(
                sprintf(
                    "SELECT id FROM cliente WHERE erp_cliente_id = '%s'",
                    $this->quote((string) $row['erp_cliente_id'])
                )
            );
            $ids[$key] = (int) $created['id'];
        }

        return $ids;
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function upsertContratos(array $rows, string $nowUtc): array
    {
        $ids = [];
        $table = $this->table('contrato');

        foreach ($rows as $row) {
            $folio = (string) $row['folio'];
            $existing = $this->fetchRow(
                sprintf("SELECT id FROM contrato WHERE folio = '%s'", $this->quote($folio))
            );

            if ($existing) {
                $ids[$folio] = (int) $existing['id'];
                continue;
            }

            $table->insert([
                'folio' => $folio,
                'cliente_id' => $row['cliente_id'],
                'segmento_id' => $row['segmento_id'],
                'frecuencia' => 'mensual',
                'fecha_aniversario' => $row['fecha_aniversario'],
                'moneda' => 'MXN',
                'dias_credito' => 30,
                'timbrar_al_emitir' => 1,
                'estado' => $row['estado'],
                'proximo_corte' => $row['proximo_corte'],
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();

            $created = $this->fetchRow(
                sprintf("SELECT id FROM contrato WHERE folio = '%s'", $this->quote($folio))
            );
            $ids[$folio] = (int) $created['id'];
        }

        return $ids;
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    /**
     * Idempotencia pura RNF-3: solo INSERT si no existe.
     * Prohibido DELETE/TRUNCATE/UPDATE sobre `movimiento` (triggers + bitácora inmutable).
     */
    private function upsertMovimientos(array $rows, string $nowUtc): void
    {
        $table = $this->table('movimiento');

        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM movimiento WHERE contrato_id = %d AND categoria_mrr = '%s' AND fecha_efectiva = '%s' AND mrr_delta_mxn = '%s' LIMIT 1",
                (int) $row['contrato_id'],
                $this->quote((string) $row['categoria_mrr']),
                $this->quote((string) $row['fecha_efectiva']),
                $this->quote((string) $row['mrr_delta_mxn'])
            ));

            if ($existing) {
                continue;
            }

            $table->insert([
                'contrato_id' => $row['contrato_id'],
                'contrato_linea_id' => null,
                'servicio_id' => null,
                'tipo' => $row['tipo'],
                'categoria_mrr' => $row['categoria_mrr'],
                'fecha_efectiva' => $row['fecha_efectiva'],
                'cantidad_antes' => $row['cantidad_antes'],
                'cantidad_despues' => $row['cantidad_despues'],
                'mrr_antes' => $row['mrr_antes'],
                'mrr_despues' => $row['mrr_despues'],
                'mrr_delta_mxn' => $row['mrr_delta_mxn'],
                'tipo_cambio' => '1.000000',
                'usuario_id' => null,
                'solicitud_id' => null,
                'motivo' => $row['motivo'],
                'con_excepcion' => 0,
                'created_at' => $nowUtc,
            ])->saveData();
        }
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    private function upsertCobranza(array $rows, string $nowUtc): void
    {
        $table = $this->table('cobranza_estado');

        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                'SELECT contrato_id FROM cobranza_estado WHERE contrato_id = %d',
                (int) $row['contrato_id']
            ));

            if ($existing) {
                continue;
            }

            $table->insert([
                'contrato_id' => $row['contrato_id'],
                'saldo_vencido' => $row['saldo_vencido'],
                'dias_vencido' => $row['dias_vencido'],
                'nivel_aviso' => $row['nivel_aviso'],
                'suspendido' => $row['suspendido'],
                'actualizado' => $nowUtc,
            ])->saveData();
        }
    }

    /**
     * Reemplaza de forma idempotente el agregado MRR del mes indicado.
     *
     * @param array<string, array<string, string>> $bridgeBySegment
     * @param array<string, int> $segmentIds
     */
    private function replaceMrrMensual(
        string $mes,
        array $bridgeBySegment,
        array $segmentIds,
        int $emisorId,
        string $nowUtc
    ): void {
        $this->execute(sprintf(
            "DELETE FROM mrr_mensual WHERE mes = '%s'",
            $this->quote($mes)
        ));

        $rows = [];
        foreach ($bridgeBySegment as $segmentName => $categories) {
            $segmentId = $segmentIds[$segmentName];
            foreach ($categories as $categoria => $monto) {
                $rows[] = [
                    'mes' => $mes,
                    'segmento_id' => $segmentId,
                    'emisor_id' => $emisorId,
                    'servicio_id' => null,
                    'categoria' => $categoria,
                    'monto' => $monto,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ];
            }
        }

        if ($rows !== []) {
            $this->table('mrr_mensual')->insert($rows)->saveData();
        }
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
