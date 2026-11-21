<?php
/**
 * Seeder idempotente — cierre septiembre 2026 (Dashboard / Puente de MRR).
 * Segmentos: Operam, Construsync, Piloto, Cibus.
 * Esquema 100% inglés. Textos visibles (nombres, motivos) permanecen en español.
 * movements es inmutable (RNF-3): solo INSERT si no existe. Prohibido DELETE/TRUNCATE/UPDATE.
 */

declare(strict_types=1);

use Operam\Support\Money;
use Phinx\Seed\AbstractSeed;

final class September2026DashboardSeeder extends AbstractSeed
{
    public function run(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        $segmentIds = $this->upsertSegments([
            ['name' => 'Operam', 'default_credit_days' => 15],
            ['name' => 'Construsync', 'default_credit_days' => 30],
            ['name' => 'Piloto', 'default_credit_days' => 15],
            ['name' => 'Cibus', 'default_credit_days' => 30],
        ], $nowUtc);

        $issuerId = $this->upsertIssuer([
            'name' => 'Operam SA de CV',
            'rfc' => 'OPE010101AAA',
            'api_url' => null,
            'is_active' => 1,
        ], $nowUtc);

        $clientIds = $this->upsertClients([
            [
                'key' => 'A',
                'segment_id' => $segmentIds['Operam'],
                'issuer_id' => $issuerId,
                'erp_client_id' => 'ERP-A',
                'name' => '[Cliente A]',
                'rfc' => 'XAXX010101000',
                'legal_name' => '[Cliente A] SA de CV',
            ],
            [
                'key' => 'B',
                'segment_id' => $segmentIds['Construsync'],
                'issuer_id' => $issuerId,
                'erp_client_id' => 'ERP-B',
                'name' => '[Cliente B]',
                'rfc' => 'XAXX010101001',
                'legal_name' => '[Cliente B] SA de CV',
            ],
            [
                'key' => 'C',
                'segment_id' => $segmentIds['Operam'],
                'issuer_id' => $issuerId,
                'erp_client_id' => 'ERP-C',
                'name' => '[Cliente C]',
                'rfc' => 'XAXX010101002',
                'legal_name' => '[Cliente C] SA de CV',
            ],
            [
                'key' => 'P',
                'segment_id' => $segmentIds['Piloto'] ?? $segmentIds['Operam'],
                'issuer_id' => $issuerId,
                'erp_client_id' => 'ERP-P',
                'name' => '[Cliente Piloto]',
                'rfc' => 'XAXX010101003',
                'legal_name' => '[Cliente Piloto] SA de CV',
            ],
            [
                'key' => 'Z',
                'segment_id' => $segmentIds['Cibus'] ?? $segmentIds['Construsync'],
                'issuer_id' => $issuerId,
                'erp_client_id' => 'ERP-Z',
                'name' => '[Cliente Cibus]',
                'rfc' => 'XAXX010101004',
                'legal_name' => '[Cliente Cibus] SA de CV',
            ],
        ], $nowUtc);

        $contractIds = $this->upsertContracts([
            [
                'folio' => 'CT-0412',
                'client_id' => $clientIds['A'],
                'segment_id' => $segmentIds['Operam'],
                'anniversary_date' => '2026-03-24',
                'next_cutoff_date' => '2026-10-24',
                'status' => 'active',
            ],
            [
                'folio' => 'CT-0598',
                'client_id' => $clientIds['B'],
                'segment_id' => $segmentIds['Construsync'],
                'anniversary_date' => '2026-09-23',
                'next_cutoff_date' => '2026-10-23',
                'status' => 'active',
            ],
            [
                'folio' => 'CT-0217',
                'client_id' => $clientIds['C'],
                'segment_id' => $segmentIds['Operam'],
                'anniversary_date' => '2025-09-22',
                'next_cutoff_date' => null,
                'status' => 'suspended',
            ],
            [
                'folio' => 'CT-0701',
                'client_id' => $clientIds['P'],
                'segment_id' => $segmentIds['Piloto'],
                'anniversary_date' => '2026-01-10',
                'next_cutoff_date' => '2026-10-10',
                'status' => 'active',
            ],
            [
                'folio' => 'CT-0802',
                'client_id' => $clientIds['Z'],
                'segment_id' => $segmentIds['Cibus'],
                'anniversary_date' => '2026-05-05',
                'next_cutoff_date' => '2026-10-05',
                'status' => 'active',
            ],
        ], $nowUtc);

        // movement_type es string (no ENUM). Códigos en inglés; el motivo visible sigue en español.
        // quantity_change = cambio de cantidad; activation = alta; suspension = suspensión.
        $this->insertMovementsIfMissing([
            [
                'contract_id' => $contractIds['CT-0412'],
                'movement_type' => 'quantity_change',
                'mrr_category' => 'expansion',
                'effective_date' => '2026-09-24',
                'quantity_before' => '8.00',
                'quantity_after' => '10.00',
                'mrr_before' => '1512.50',
                'mrr_after' => '1815.00',
                'mrr_delta_mxn' => '302.50',
                'reason' => 'Alta de puestos adicionales',
            ],
            [
                'contract_id' => $contractIds['CT-0598'],
                'movement_type' => 'activation',
                'mrr_category' => 'new',
                'effective_date' => '2026-09-23',
                'quantity_before' => '0.00',
                'quantity_after' => '1.00',
                'mrr_before' => '0.00',
                'mrr_after' => '8900.00',
                'mrr_delta_mxn' => '8900.00',
                'reason' => 'Alta comercial',
            ],
            [
                'contract_id' => $contractIds['CT-0217'],
                'movement_type' => 'suspension',
                'mrr_category' => 'churn',
                'effective_date' => '2026-09-22',
                'quantity_before' => '1.00',
                'quantity_after' => '0.00',
                'mrr_before' => '2450.00',
                'mrr_after' => '0.00',
                'mrr_delta_mxn' => '-2450.00',
                'reason' => 'Suspensión automática por mora',
            ],
            [
                'contract_id' => $contractIds['CT-0701'],
                'movement_type' => 'activation',
                'mrr_category' => 'new',
                'effective_date' => '2026-09-15',
                'quantity_before' => '0.00',
                'quantity_after' => '1.00',
                'mrr_before' => '0.00',
                'mrr_after' => '3200.00',
                'mrr_delta_mxn' => '3200.00',
                'reason' => 'Alta Piloto septiembre',
            ],
            [
                'contract_id' => $contractIds['CT-0802'],
                'movement_type' => 'quantity_change',
                'mrr_category' => 'expansion',
                'effective_date' => '2026-09-12',
                'quantity_before' => '2.00',
                'quantity_after' => '3.00',
                'mrr_before' => '4500.00',
                'mrr_after' => '6750.00',
                'mrr_delta_mxn' => '2250.00',
                'reason' => 'Expansión Cibus',
            ],
        ], $nowUtc);

        $this->upsertCollectionStatuses([
            [
                'contract_id' => $contractIds['CT-0412'],
                'overdue_balance' => '0.00',
                'overdue_days' => 0,
                'notice_level' => 0,
                'is_suspended' => 0,
            ],
            [
                'contract_id' => $contractIds['CT-0598'],
                'overdue_balance' => '0.00',
                'overdue_days' => 0,
                'notice_level' => 0,
                'is_suspended' => 0,
            ],
            [
                'contract_id' => $contractIds['CT-0217'],
                'overdue_balance' => '2450.00',
                'overdue_days' => 95,
                'notice_level' => 4,
                'is_suspended' => 1,
            ],
            [
                'contract_id' => $contractIds['CT-0701'],
                'overdue_balance' => '0.00',
                'overdue_days' => 0,
                'notice_level' => 0,
                'is_suspended' => 0,
            ],
            [
                'contract_id' => $contractIds['CT-0802'],
                'overdue_balance' => '1200.00',
                'overdue_days' => 18,
                'notice_level' => 1,
                'is_suspended' => 0,
            ],
        ], $nowUtc);

        // Puente de MRR sep-2026 por segmento (DECIMAL exacto).
        // Categorías ENUM en inglés: start, new, expansion, price_adjustment,
        // frequency_change, contraction, churn, reactivation, end.
        $bridgeBySegment = [
            'Operam' => [
                'start' => '780000.00',
                'new' => '14000.00',
                'expansion' => '11000.00',
                'price_adjustment' => '4000.00',
                'frequency_change' => '1200.00',
                'contraction' => '-4500.00',
                'churn' => '-9000.00',
                'reactivation' => '2500.00',
                'end' => '799200.00',
            ],
            'Construsync' => [
                'start' => '474300.00',
                'new' => '7600.00',
                'expansion' => '7200.00',
                'price_adjustment' => '2100.00',
                'frequency_change' => '700.00',
                'contraction' => '-2900.00',
                'churn' => '-5300.00',
                'reactivation' => '1600.00',
                'end' => '485300.00',
            ],
            'Piloto' => [
                'start' => '85000.00',
                'new' => '3200.00',
                'expansion' => '1500.00',
                'price_adjustment' => '0.00',
                'frequency_change' => '0.00',
                'contraction' => '-800.00',
                'churn' => '-1200.00',
                'reactivation' => '400.00',
                'end' => '88100.00',
            ],
            'Cibus' => [
                'start' => '120000.00',
                'new' => '4500.00',
                'expansion' => '2250.00',
                'price_adjustment' => '500.00',
                'frequency_change' => '0.00',
                'contraction' => '-900.00',
                'churn' => '-2100.00',
                'reactivation' => '700.00',
                'end' => '124950.00',
            ],
        ];

        $this->assertBridgeBalances($bridgeBySegment);
        $this->replaceMonthlyMrr('2026-09', $bridgeBySegment, $segmentIds, $issuerId, $nowUtc);
    }

    /**
     * Valida Inicio + deltas = Fin con brick/math (RNF-1), sin float.
     *
     * @param array<string, array<string, string>> $bridgeBySegment
     */
    private function assertBridgeBalances(array $bridgeBySegment): void
    {
        $deltaKeys = [
            'new',
            'expansion',
            'price_adjustment',
            'frequency_change',
            'contraction',
            'churn',
            'reactivation',
        ];

        foreach ($bridgeBySegment as $segmentName => $categories) {
            $total = Money::of($categories['start']);
            foreach ($deltaKeys as $key) {
                $total = $total->plus(Money::of($categories[$key]));
            }

            if ($total->toDecimalString() !== Money::of($categories['end'])->toDecimalString()) {
                throw new RuntimeException(
                    "Puente MRR inválido para {$segmentName}: acumulado {$total->toDecimalString()} != fin {$categories['end']}"
                );
            }
        }
    }

    /**
     * @param array<int, array{name:string,default_credit_days:int}> $rows
     * @return array<string, int>
     */
    private function upsertSegments(array $rows, string $nowUtc): array
    {
        $ids = [];
        $table = $this->table('segments');

        foreach ($rows as $row) {
            $existing = $this->fetchRow(
                sprintf("SELECT id FROM segments WHERE name = '%s'", $this->quote($row['name']))
            );

            if ($existing) {
                $ids[$row['name']] = (int) $existing['id'];
                // Mantiene alineados los defaults RF-1 (p. ej. Operam 15 / Construsync 30).
                // usd_exchange_rate no se pisa: conserva el tipo de cambio ya capturado.
                $this->execute(sprintf(
                    "UPDATE segments SET default_credit_days = %d, updated_at = '%s' WHERE id = %d",
                    (int) $row['default_credit_days'],
                    $this->quote($nowUtc),
                    (int) $existing['id'],
                ));
                continue;
            }

            $table->insert([
                'name' => $row['name'],
                'default_credit_days' => $row['default_credit_days'],
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();

            $created = $this->fetchRow(
                sprintf("SELECT id FROM segments WHERE name = '%s'", $this->quote($row['name']))
            );
            $ids[$row['name']] = (int) $created['id'];
        }

        return $ids;
    }

    /**
     * @param array{name:string,rfc:string,api_url:?string,is_active:int} $row
     */
    private function upsertIssuer(array $row, string $nowUtc): int
    {
        $existing = $this->fetchRow(
            sprintf("SELECT id FROM issuers WHERE rfc = '%s'", $this->quote($row['rfc']))
        );

        if ($existing) {
            return (int) $existing['id'];
        }

        $this->table('issuers')->insert([
            'name' => $row['name'],
            'rfc' => $row['rfc'],
            'api_url' => $row['api_url'],
            'is_active' => $row['is_active'],
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ])->saveData();

        $created = $this->fetchRow(
            sprintf("SELECT id FROM issuers WHERE rfc = '%s'", $this->quote($row['rfc']))
        );

        return (int) $created['id'];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function upsertClients(array $rows, string $nowUtc): array
    {
        $ids = [];
        $table = $this->table('clients');

        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $existing = $this->fetchRow(
                sprintf(
                    "SELECT id FROM clients WHERE erp_client_id = '%s'",
                    $this->quote((string) $row['erp_client_id'])
                )
            );

            if ($existing) {
                $ids[$key] = (int) $existing['id'];
                continue;
            }

            $table->insert([
                'segment_id' => (int) ($row['segment_id'] ?? 0),
                'issuer_id' => $row['issuer_id'],
                'erp_client_id' => $row['erp_client_id'],
                'name' => $row['name'],
                'rfc' => $row['rfc'],
                'legal_name' => $row['legal_name'],
                'credit_days' => 30,
                'stamp_on_issue' => 1,
                'exclude_suspension' => 0,
                'active' => 1,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();

            $created = $this->fetchRow(
                sprintf(
                    "SELECT id FROM clients WHERE erp_client_id = '%s'",
                    $this->quote((string) $row['erp_client_id'])
                )
            );
            $ids[$key] = (int) $created['id'];
        }

        return $ids;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function upsertContracts(array $rows, string $nowUtc): array
    {
        $ids = [];
        $table = $this->table('contracts');

        foreach ($rows as $row) {
            $folio = (string) $row['folio'];
            $existing = $this->fetchRow(
                sprintf("SELECT id FROM contracts WHERE folio = '%s'", $this->quote($folio))
            );

            if ($existing) {
                $ids[$folio] = (int) $existing['id'];
                continue;
            }

            $table->insert([
                'folio' => $folio,
                'client_id' => $row['client_id'],
                'segment_id' => $row['segment_id'],
                'frequency' => 'monthly',
                'anniversary_date' => $row['anniversary_date'],
                'currency' => 'MXN',
                'credit_days' => 30,
                'stamp_on_issue' => 1,
                'status' => $row['status'],
                'next_cutoff_date' => $row['next_cutoff_date'],
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();

            $created = $this->fetchRow(
                sprintf("SELECT id FROM contracts WHERE folio = '%s'", $this->quote($folio))
            );
            $ids[$folio] = (int) $created['id'];
        }

        return $ids;
    }

    /**
     * Idempotencia pura RNF-3: solo INSERT si no existe la misma huella.
     * Prohibido DELETE, TRUNCATE o UPDATE sobre `movements` (triggers + bitácora inmutable).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertMovementsIfMissing(array $rows, string $nowUtc): void
    {
        $table = $this->table('movements');

        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM movements WHERE contract_id = %d AND mrr_category = '%s' AND effective_date = '%s' AND mrr_delta_mxn = '%s' LIMIT 1",
                (int) $row['contract_id'],
                $this->quote((string) $row['mrr_category']),
                $this->quote((string) $row['effective_date']),
                $this->quote((string) $row['mrr_delta_mxn'])
            ));

            if ($existing) {
                continue;
            }

            $table->insert([
                'contract_id' => $row['contract_id'],
                'contract_line_id' => null,
                'service_id' => null,
                'movement_type' => $row['movement_type'],
                'mrr_category' => $row['mrr_category'],
                'effective_date' => $row['effective_date'],
                'quantity_before' => $row['quantity_before'],
                'quantity_after' => $row['quantity_after'],
                'mrr_before' => $row['mrr_before'],
                'mrr_after' => $row['mrr_after'],
                'mrr_delta_mxn' => $row['mrr_delta_mxn'],
                'exchange_rate' => '1.000000',
                'user_id' => null,
                'request_id' => null,
                'reason' => $row['reason'],
                'has_exception' => 0,
                'created_at' => $nowUtc,
            ])->saveData();
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function upsertCollectionStatuses(array $rows, string $nowUtc): void
    {
        $table = $this->table('collection_statuses');

        foreach ($rows as $row) {
            $existing = $this->fetchRow(sprintf(
                'SELECT contract_id FROM collection_statuses WHERE contract_id = %d',
                (int) $row['contract_id']
            ));

            if ($existing) {
                continue;
            }

            $table->insert([
                'contract_id' => $row['contract_id'],
                'overdue_balance' => $row['overdue_balance'],
                'overdue_days' => $row['overdue_days'],
                'notice_level' => $row['notice_level'],
                'is_suspended' => $row['is_suspended'],
                'updated_at' => $nowUtc,
            ])->saveData();
        }
    }

    /**
     * Reemplaza de forma idempotente el agregado MRR del mes indicado.
     * `month` es palabra reservada de MySQL: se cita en SQL crudo.
     *
     * @param array<string, array<string, string>> $bridgeBySegment
     * @param array<string, int> $segmentIds
     */
    private function replaceMonthlyMrr(
        string $month,
        array $bridgeBySegment,
        array $segmentIds,
        int $issuerId,
        string $nowUtc
    ): void {
        $this->execute(sprintf(
            "DELETE FROM monthly_mrr WHERE `month` = '%s'",
            $this->quote($month)
        ));

        $rows = [];
        foreach ($bridgeBySegment as $segmentName => $categories) {
            $segmentId = $segmentIds[$segmentName];
            foreach ($categories as $category => $amount) {
                $rows[] = [
                    'month' => $month,
                    'segment_id' => $segmentId,
                    'issuer_id' => $issuerId,
                    'service_id' => null,
                    'category' => $category,
                    'amount' => $amount,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ];
            }
        }

        if ($rows !== []) {
            $this->table('monthly_mrr')->insert($rows)->saveData();
        }
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
