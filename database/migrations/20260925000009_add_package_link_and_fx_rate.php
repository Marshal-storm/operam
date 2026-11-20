<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Consolidado: package_service_id / package_factor / usd_exchange_rate
 * viven en create_catalog y create_dashboard. No-op de secuencia.
 */
final class AddPackageLinkAndFxRate extends AbstractMigration
{
    public function up(): void
    {
        // Intencionalmente vacío.
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
}
