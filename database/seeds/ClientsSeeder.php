<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seeder idempotente — Directorio de clientes (Módulo 3 Paso 1).
 * ≥8 clientes realistas entre Operam/Construsync y emisores Piloto/Cibus.
 */
final class ClientsSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return [
            September2026DashboardSeeder::class,
        ];
    }

    public function run(): void
    {
        // Alinea la sesión Phinx a UTC (RNF-11) — evita timestamps “futuros” vs Capsule.
        $this->execute("SET time_zone = '+00:00'");
        $nowUtc = gmdate('Y-m-d H:i:s');

        $operamId = $this->segmentIdByName('Operam');
        $construId = $this->segmentIdByName('Construsync');
        if ($operamId === null || $construId === null) {
            throw new \RuntimeException('Faltan segmentos Operam/Construsync. Ejecuta September2026DashboardSeeder primero.');
        }

        $pilotoId = $this->issuerIdByNameLike('Piloto');
        $cibusId = $this->issuerIdByNameLike('Cibus');
        if ($pilotoId === null || $cibusId === null) {
            throw new \RuntimeException('Faltan emisores Piloto/Cibus. Ejecuta los seeders de Dashboard/Catálogo primero.');
        }

        $operamDays = $this->segmentDefaultCreditDays($operamId) ?? 15;
        $construDays = $this->segmentDefaultCreditDays($construId) ?? 30;

        $rows = [
            [
                'erp_client_id' => 'ERP-OP-ACME',
                'segment_id' => $operamId,
                'issuer_id' => $pilotoId,
                'name' => 'Acme Logística',
                'legal_name' => 'Acme Logística SA de CV',
                'rfc' => 'ALO150315ABC',
                'contact_name' => 'Laura Méndez',
                'contact_email' => 'laura.mendez@acmelogistica.mx',
                'contact_phone' => '+52 55 4001 1100',
                'credit_days' => $operamDays,
                'stamp_on_issue' => 1,
                'exclude_suspension' => 0,
                'active' => 1,
            ],
            [
                'erp_client_id' => 'ERP-OP-NEXO',
                'segment_id' => $operamId,
                'issuer_id' => $pilotoId,
                'name' => 'Nexo Retail',
                'legal_name' => 'Nexo Retail México SA de CV',
                'rfc' => 'NRM180920XY9',
                'contact_name' => 'Carlos Ruiz',
                'contact_email' => 'cruiz@nexoretail.mx',
                'contact_phone' => '+52 33 2100 4455',
                'credit_days' => $operamDays,
                'stamp_on_issue' => 0,
                'exclude_suspension' => 0,
                'active' => 1,
            ],
            [
                'erp_client_id' => 'ERP-OP-VISTA',
                'segment_id' => $operamId,
                'issuer_id' => $cibusId,
                'name' => 'Vista Foods',
                'legal_name' => 'Vista Foods Internacional SA de CV',
                'rfc' => 'VFI120511KK1',
                'contact_name' => 'Ana Solís',
                'contact_email' => 'ana.solis@vistafoods.mx',
                'contact_phone' => '+52 81 8000 2211',
                'credit_days' => $operamDays,
                'stamp_on_issue' => 1,
                'exclude_suspension' => 1,
                'active' => 1,
            ],
            [
                'erp_client_id' => 'ERP-OP-DELTA',
                'segment_id' => $operamId,
                'issuer_id' => $pilotoId,
                'name' => 'Delta Servicios',
                'legal_name' => 'Delta Servicios Corporativos SA de CV',
                'rfc' => 'DSC090812QQ2',
                'contact_name' => 'Jorge Palacios',
                'contact_email' => 'jpalacios@deltaservicios.mx',
                'contact_phone' => '+52 55 6123 9001',
                'credit_days' => 45,
                'stamp_on_issue' => 0,
                'exclude_suspension' => 0,
                'active' => 0,
            ],
            [
                'erp_client_id' => 'ERP-CS-HORIZON',
                'segment_id' => $construId,
                'issuer_id' => $cibusId,
                'name' => 'Horizon Constructora',
                'legal_name' => 'Horizon Constructora del Norte SA de CV',
                'rfc' => 'HCN140228RR3',
                'contact_name' => 'Patricia Gómez',
                'contact_email' => 'pgomez@horizon.mx',
                'contact_phone' => '+52 81 7000 3344',
                'credit_days' => $construDays,
                'stamp_on_issue' => 0,
                'exclude_suspension' => 0,
                'active' => 1,
            ],
            [
                'erp_client_id' => 'ERP-CS-ATLAS',
                'segment_id' => $construId,
                'issuer_id' => $pilotoId,
                'name' => 'Atlas Obra Civil',
                'legal_name' => 'Atlas Obra Civil SA de CV',
                'rfc' => 'AOC160705TT4',
                'contact_name' => 'Miguel Ángel Torres',
                'contact_email' => 'matorres@atlasobra.mx',
                'contact_phone' => '+52 222 400 7788',
                'credit_days' => $construDays,
                'stamp_on_issue' => 1,
                'exclude_suspension' => 0,
                'active' => 1,
            ],
            [
                'erp_client_id' => 'ERP-CS-PIRAMIDE',
                'segment_id' => $construId,
                'issuer_id' => $cibusId,
                'name' => 'Pirámide Desarrollos',
                'legal_name' => 'Pirámide Desarrollos Urbanos SA de CV',
                'rfc' => 'PDU110319UU5',
                'contact_name' => 'Sofía Herrera',
                'contact_email' => 'sherrera@piramide.mx',
                'contact_phone' => '+52 55 9001 5566',
                'credit_days' => $construDays,
                'stamp_on_issue' => 0,
                'exclude_suspension' => 1,
                'active' => 1,
            ],
            [
                'erp_client_id' => 'ERP-CS-METRO',
                'segment_id' => $construId,
                'issuer_id' => $pilotoId,
                'name' => 'Metro Ingeniería',
                'legal_name' => 'Metro Ingeniería y Proyectos SA de CV',
                'rfc' => 'MIP170901VV6',
                'contact_name' => 'Ricardo Peña',
                'contact_email' => 'rpena@metroing.mx',
                'contact_phone' => '+52 33 1555 0099',
                'credit_days' => 60,
                'stamp_on_issue' => 1,
                'exclude_suspension' => 0,
                'active' => 1,
            ],
        ];

        foreach ($rows as $row) {
            $this->upsertClient($row, $nowUtc);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertClient(array $row, string $nowUtc): void
    {
        $erpId = (string) $row['erp_client_id'];
        $existing = $this->fetchRow(sprintf(
            "SELECT id FROM clients WHERE erp_client_id = '%s'",
            $this->quote($erpId)
        ));

        if ($existing) {
            $this->execute(sprintf(
                "UPDATE clients SET
                    segment_id = %d,
                    issuer_id = %d,
                    name = '%s',
                    legal_name = '%s',
                    rfc = '%s',
                    contact_name = '%s',
                    contact_email = '%s',
                    contact_phone = '%s',
                    credit_days = %d,
                    stamp_on_issue = %d,
                    exclude_suspension = %d,
                    active = %d,
                    updated_at = '%s'
                 WHERE id = %d",
                (int) $row['segment_id'],
                (int) $row['issuer_id'],
                $this->quote((string) $row['name']),
                $this->quote((string) $row['legal_name']),
                $this->quote((string) $row['rfc']),
                $this->quote((string) $row['contact_name']),
                $this->quote((string) $row['contact_email']),
                $this->quote((string) $row['contact_phone']),
                (int) $row['credit_days'],
                (int) $row['stamp_on_issue'],
                (int) $row['exclude_suspension'],
                (int) $row['active'],
                $this->quote($nowUtc),
                (int) $existing['id']
            ));

            return;
        }

        $this->table('clients')->insert([
            'segment_id' => (int) $row['segment_id'],
            'issuer_id' => (int) $row['issuer_id'],
            'erp_client_id' => $erpId,
            'name' => (string) $row['name'],
            'legal_name' => (string) $row['legal_name'],
            'rfc' => (string) $row['rfc'],
            'contact_name' => (string) $row['contact_name'],
            'contact_email' => (string) $row['contact_email'],
            'contact_phone' => (string) $row['contact_phone'],
            'credit_days' => (int) $row['credit_days'],
            'stamp_on_issue' => (int) $row['stamp_on_issue'],
            'exclude_suspension' => (int) $row['exclude_suspension'],
            'active' => (int) $row['active'],
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

    private function segmentDefaultCreditDays(int $segmentId): ?int
    {
        $row = $this->fetchRow(sprintf(
            'SELECT default_credit_days FROM segments WHERE id = %d',
            $segmentId
        ));

        return $row ? (int) $row['default_credit_days'] : null;
    }

    private function issuerIdByNameLike(string $needle): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM issuers WHERE name LIKE '%%%s%%' ORDER BY id ASC LIMIT 1",
            $this->quote($needle)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
