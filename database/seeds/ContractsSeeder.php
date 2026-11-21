<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seeder idempotente — 4 contratos realistas (Operam / Construsync) con líneas,
 * movimientos iniciales y sync de monthly_mrr (Módulo 3 Paso 2).
 * Folios CT-0601…CT-0604 (no colisionan con el dashboard CT-0412…).
 */
final class ContractsSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return [
            September2026DashboardSeeder::class,
            CatalogAndPricingSeeder::class,
            ClientsSeeder::class,
        ];
    }

    public function run(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');

        $operamId = $this->segmentIdByName('Operam');
        $construId = $this->segmentIdByName('Construsync');
        if ($operamId === null || $construId === null) {
            throw new RuntimeException('Faltan segmentos Operam/Construsync.');
        }

        $pilotoId = $this->issuerIdByNameLike('Piloto');
        $cibusId = $this->issuerIdByNameLike('Cibus');
        if ($pilotoId === null || $cibusId === null) {
            throw new RuntimeException('Faltan emisores Piloto/Cibus.');
        }

        $clientAcme = $this->clientIdByErp('ERP-OP-ACME');
        $clientNexo = $this->clientIdByErp('ERP-OP-NEXO');
        $clientObra = $this->clientIdByErp('ERP-CS-HORIZON');
        $clientSteel = $this->clientIdByErp('ERP-CS-ATLAS');
        // Fallback a clientes del dashboard si el ClientsSeeder aún no corrió.
        if ($clientAcme === null) {
            $clientAcme = $this->clientIdByErp('ERP-A');
        }
        if ($clientNexo === null) {
            $clientNexo = $this->clientIdByErp('ERP-B');
        }
        if ($clientObra === null) {
            $clientObra = $this->clientIdByErp('ERP-B');
        }
        if ($clientSteel === null) {
            $clientSteel = $this->clientIdByErp('ERP-C');
        }
        if ($clientAcme === null || $clientNexo === null || $clientObra === null || $clientSteel === null) {
            throw new RuntimeException('Faltan clientes para ContractsSeeder.');
        }

        $listOperam = $this->priceListIdByNameLike('Lista Oficial Operam');
        $listConstru = $this->priceListIdByNameLike('Lista Construsync');
        if ($listOperam === null || $listConstru === null) {
            throw new RuntimeException('Faltan listas de precios Operam/Construsync.');
        }

        $svcBase = $this->serviceIdBySku('OP-BASE');
        $svcUsr = $this->serviceIdBySku('OP-USR');
        $svcPkg = $this->serviceIdBySku('OP-PKG5');
        if ($svcBase === null || $svcUsr === null || $svcPkg === null) {
            throw new RuntimeException('Faltan servicios OP-BASE / OP-USR / OP-PKG5.');
        }

        $contracts = [
            [
                'folio' => 'CT-0601',
                'client_id' => $clientAcme,
                'segment_id' => $operamId,
                'issuer_id' => $pilotoId,
                'price_list_id' => $listOperam,
                'frequency' => 'monthly',
                'anniversary_date' => '2026-06-01',
                'next_cutoff_date' => '2026-12-01',
                'currency' => 'MXN',
                'exchange_rate' => '1.0000',
                'credit_days' => 15,
                'stamp_on_issue' => 1,
                'status' => 'active',
                'mrr_mxn' => '4359.00',
                'notes' => 'Contrato mensual Operam — Acme Logística (demo M3)',
                'lines' => [
                    [
                        'service_id' => $svcBase,
                        'description' => 'Operam Base',
                        'service_type' => 'recurring',
                        'billing_frequency' => 'monthly',
                        'quantity' => 1,
                        'unit_price' => '2499.00',
                        'discount_amount' => '0.00',
                        'subtotal' => '2499.00',
                        'mrr_mxn' => '2499.00',
                        'is_package_converted' => 0,
                    ],
                    // RF-2: 12 usuarios → 2 paquetes + 2 sueltos
                    [
                        'service_id' => $svcPkg,
                        'description' => 'Paquete 5 Usuarios',
                        'service_type' => 'recurring',
                        'billing_frequency' => 'monthly',
                        'quantity' => 2,
                        'unit_price' => '750.00',
                        'discount_amount' => '0.00',
                        'subtotal' => '1500.00',
                        'mrr_mxn' => '1500.00',
                        'is_package_converted' => 1,
                    ],
                    [
                        'service_id' => $svcUsr,
                        'description' => 'Usuario Individual',
                        'service_type' => 'recurring',
                        'billing_frequency' => 'monthly',
                        'quantity' => 2,
                        'unit_price' => '180.00',
                        'discount_amount' => '0.00',
                        'subtotal' => '360.00',
                        'mrr_mxn' => '360.00',
                        'is_package_converted' => 1,
                    ],
                ],
            ],
            [
                'folio' => 'CT-0602',
                'client_id' => $clientNexo,
                'segment_id' => $operamId,
                'issuer_id' => $pilotoId,
                'price_list_id' => $listOperam,
                'frequency' => 'annual',
                'anniversary_date' => '2026-01-15',
                'next_cutoff_date' => '2027-01-15',
                'currency' => 'MXN',
                'exchange_rate' => '1.0000',
                'credit_days' => 30,
                'stamp_on_issue' => 0,
                'status' => 'active',
                // Anual: MRR = subtotal/12 (2249.10 + 765.00)
                'mrr_mxn' => '3014.10',
                'notes' => 'Contrato anual Operam — Nexo Retail (demo M3)',
                'lines' => [
                    [
                        'service_id' => $svcBase,
                        'description' => 'Operam Base (anual)',
                        'service_type' => 'recurring',
                        'billing_frequency' => 'annual',
                        'quantity' => 1,
                        'unit_price' => '26989.20',
                        'discount_amount' => '0.00',
                        'subtotal' => '26989.20',
                        'mrr_mxn' => '2249.10',
                        'is_package_converted' => 0,
                    ],
                    [
                        'service_id' => $svcUsr,
                        'description' => 'Usuario Individual (anual)',
                        'service_type' => 'recurring',
                        'billing_frequency' => 'annual',
                        'quantity' => 5,
                        'unit_price' => '1836.00',
                        'discount_amount' => '0.00',
                        'subtotal' => '9180.00',
                        'mrr_mxn' => '765.00',
                        'is_package_converted' => 0,
                    ],
                ],
            ],
            [
                'folio' => 'CT-0603',
                'client_id' => $clientObra,
                'segment_id' => $construId,
                'issuer_id' => $cibusId,
                'price_list_id' => $listConstru,
                'frequency' => 'monthly',
                'anniversary_date' => '2026-08-10',
                'next_cutoff_date' => '2026-12-10',
                'currency' => 'MXN',
                'exchange_rate' => '1.0000',
                'credit_days' => 30,
                'stamp_on_issue' => 1,
                'status' => 'active',
                'mrr_mxn' => '2499.00',
                'notes' => 'Contrato mensual Construsync (demo M3)',
                'lines' => [
                    [
                        'service_id' => $svcBase,
                        'description' => 'Operam Base',
                        'service_type' => 'recurring',
                        'billing_frequency' => 'monthly',
                        'quantity' => 1,
                        'unit_price' => '2499.00',
                        'discount_amount' => '0.00',
                        'subtotal' => '2499.00',
                        'mrr_mxn' => '2499.00',
                        'is_package_converted' => 0,
                    ],
                ],
            ],
            [
                'folio' => 'CT-0604',
                'client_id' => $clientSteel,
                'segment_id' => $construId,
                'issuer_id' => $cibusId,
                'price_list_id' => $listConstru,
                'frequency' => 'annual',
                'anniversary_date' => '2026-03-01',
                'next_cutoff_date' => '2027-03-01',
                'currency' => 'MXN',
                'exchange_rate' => '1.0000',
                'credit_days' => 45,
                'stamp_on_issue' => 1,
                'status' => 'suspended',
                'mrr_mxn' => '0.00',
                'notes' => 'Contrato anual suspendido Construsync (demo M3)',
                'lines' => [
                    [
                        'service_id' => $svcBase,
                        'description' => 'Operam Base (anual)',
                        'service_type' => 'recurring',
                        'billing_frequency' => 'annual',
                        'quantity' => 1,
                        'unit_price' => '26989.20',
                        'discount_amount' => '0.00',
                        'subtotal' => '26989.20',
                        'mrr_mxn' => '2249.10',
                        'is_package_converted' => 0,
                    ],
                ],
            ],
        ];

        // Recalcular mrr del suspendido a 0 (no aporta al KPI activo).
        $contracts[3]['mrr_mxn'] = '0.00';
        $contracts[3]['lines'][0]['mrr_mxn'] = '0.00';

        foreach ($contracts as $contract) {
            $this->upsertContractBundle($contract, $nowUtc);
        }
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function upsertContractBundle(array $contract, string $nowUtc): void
    {
        $folio = (string) $contract['folio'];
        $existing = $this->fetchRow(sprintf(
            "SELECT id FROM contracts WHERE folio = '%s'",
            $this->quote($folio)
        ));

        if ($existing) {
            $contractId = (int) $existing['id'];
            $this->ensureLines($contractId, $contract['lines'], $nowUtc);
            $this->ensureNewMovement($contractId, $contract, $nowUtc);
            $this->ensureMonthlyMrr($contract, $nowUtc);

            return;
        }

        $this->table('contracts')->insert([
            'folio' => $folio,
            'client_id' => (int) $contract['client_id'],
            'segment_id' => (int) $contract['segment_id'],
            'issuer_id' => (int) $contract['issuer_id'],
            'instance_id' => null,
            'price_list_id' => (int) $contract['price_list_id'],
            'promotion_id' => null,
            'frequency' => (string) $contract['frequency'],
            'anniversary_date' => (string) $contract['anniversary_date'],
            'currency' => (string) $contract['currency'],
            'exchange_rate' => (string) $contract['exchange_rate'],
            'credit_days' => (int) $contract['credit_days'],
            'stamp_on_issue' => (int) $contract['stamp_on_issue'],
            'status' => (string) $contract['status'],
            'mrr_mxn' => (string) $contract['mrr_mxn'],
            'next_cutoff_date' => (string) $contract['next_cutoff_date'],
            'notes' => (string) $contract['notes'],
            'created_by' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ])->saveData();

        $created = $this->fetchRow(sprintf(
            "SELECT id FROM contracts WHERE folio = '%s'",
            $this->quote($folio)
        ));
        if (!$created) {
            throw new RuntimeException("No se pudo crear el contrato {$folio}");
        }

        $contractId = (int) $created['id'];
        $this->ensureLines($contractId, $contract['lines'], $nowUtc);
        $this->ensureNewMovement($contractId, $contract, $nowUtc);
        $this->ensureMonthlyMrr($contract, $nowUtc);
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function ensureLines(int $contractId, array $lines, string $nowUtc): void
    {
        $countRow = $this->fetchRow(sprintf(
            'SELECT COUNT(*) AS c FROM contract_lines WHERE contract_id = %d',
            $contractId
        ));
        if ($countRow && (int) $countRow['c'] > 0) {
            return;
        }

        foreach ($lines as $line) {
            $this->table('contract_lines')->insert([
                'contract_id' => $contractId,
                'service_id' => (int) $line['service_id'],
                'description' => (string) $line['description'],
                'service_type' => (string) $line['service_type'],
                'billing_frequency' => (string) $line['billing_frequency'],
                'quantity' => (int) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'discount_amount' => (string) $line['discount_amount'],
                'subtotal' => (string) $line['subtotal'],
                'mrr_mxn' => (string) $line['mrr_mxn'],
                'is_package_converted' => (int) $line['is_package_converted'],
                'active' => 1,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
        }
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function ensureNewMovement(int $contractId, array $contract, string $nowUtc): void
    {
        $mrr = (string) $contract['mrr_mxn'];
        // Suspendidos: no insertamos movimiento de alta con MRR positivo.
        if ((string) $contract['status'] === 'suspended' || bccomp($mrr, '0.00', 2) === 0) {
            return;
        }

        $effective = (string) $contract['anniversary_date'];
        $existing = $this->fetchRow(sprintf(
            "SELECT id FROM movements WHERE contract_id = %d AND mrr_category = 'new' AND effective_date = '%s' AND mrr_delta_mxn = '%s' LIMIT 1",
            $contractId,
            $this->quote($effective),
            $this->quote($mrr)
        ));
        if ($existing) {
            return;
        }

        $this->table('movements')->insert([
            'contract_id' => $contractId,
            'contract_line_id' => null,
            'service_id' => null,
            'movement_type' => 'contract_created',
            'mrr_category' => 'new',
            'effective_date' => $effective,
            'quantity_before' => '0.00',
            'quantity_after' => '1.00',
            'mrr_before' => '0.00',
            'mrr_after' => $mrr,
            'mrr_delta_mxn' => $mrr,
            'exchange_rate' => '1.000000',
            'user_id' => null,
            'request_id' => null,
            'reason' => 'Seeder M3 — alta ' . (string) $contract['folio'],
            'has_exception' => 0,
            'created_at' => $nowUtc,
        ])->saveData();
    }

    /**
     * Upsert idempotente de monthly_mrr (categoría new) sumando con bcadd si ya existe.
     *
     * @param array<string, mixed> $contract
     */
    private function ensureMonthlyMrr(array $contract, string $nowUtc): void
    {
        $mrr = (string) $contract['mrr_mxn'];
        if ((string) $contract['status'] !== 'active' || bccomp($mrr, '0.00', 2) === 0) {
            return;
        }

        $month = substr((string) $contract['anniversary_date'], 0, 7);
        $segmentId = (int) $contract['segment_id'];
        $issuerId = (int) $contract['issuer_id'];
        $folio = (string) $contract['folio'];

        // Marca de idempotencia: solo inserta/suma una vez por folio+mes vía reason en movements.
        $marker = $this->fetchRow(sprintf(
            "SELECT id FROM monthly_mrr WHERE `month` = '%s' AND segment_id = %d AND issuer_id = %d AND service_id IS NULL AND category = 'new' LIMIT 1",
            $this->quote($month),
            $segmentId,
            $issuerId
        ));

        // Evita doble suma: si el movimiento del folio ya existía antes de este seed, no tocamos.
        $movementExists = $this->fetchRow(sprintf(
            "SELECT id FROM movements WHERE reason = '%s' LIMIT 1",
            $this->quote('Seeder M3 — alta ' . $folio)
        ));

        if ($marker) {
            // Si el movimiento del seeder ya está, asumimos que el MRR ya se contabilizó.
            if ($movementExists) {
                return;
            }
            $newAmount = bcadd((string) $marker['amount'], $mrr, 2);
            $this->execute(sprintf(
                "UPDATE monthly_mrr SET amount = '%s', updated_at = '%s' WHERE id = %d",
                $this->quote($newAmount),
                $this->quote($nowUtc),
                (int) $marker['id']
            ));

            return;
        }

        $this->table('monthly_mrr')->insert([
            'month' => $month,
            'segment_id' => $segmentId,
            'issuer_id' => $issuerId,
            'service_id' => null,
            'category' => 'new',
            'amount' => $mrr,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ])->saveData();
    }

    private function segmentIdByName(string $name): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM segments WHERE name = '%s'",
            $this->quote($name)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function issuerIdByNameLike(string $needle): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM issuers WHERE name LIKE '%%%s%%' ORDER BY id ASC LIMIT 1",
            $this->quote($needle)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function clientIdByErp(string $erpId): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM clients WHERE erp_client_id = '%s'",
            $this->quote($erpId)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function priceListIdByNameLike(string $needle): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM price_lists WHERE name LIKE '%%%s%%' ORDER BY id ASC LIMIT 1",
            $this->quote($needle)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function serviceIdBySku(string $sku): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM services WHERE code = '%s'",
            $this->quote($sku)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
