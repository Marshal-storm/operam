<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Inicializa system_settings (audit_logs_enabled = true por defecto).
 */
final class SystemSettingsSeeder extends AbstractSeed
{
    public function run(): void
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $existing = $this->fetchRow(
            "SELECT id FROM system_settings WHERE setting_key = 'audit_logs_enabled'"
        );

        if ($existing) {
            $this->execute(sprintf(
                "UPDATE system_settings SET setting_value = '1', value_type = 'boolean', "
                . "description = '%s', updated_at = '%s' WHERE id = %d",
                $this->quote('Activa o pausa el registro automático en audit_logs'),
                $nowUtc,
                (int) $existing['id']
            ));

            return;
        }

        $this->table('system_settings')->insert([
            'setting_key' => 'audit_logs_enabled',
            'setting_value' => '1',
            'value_type' => 'boolean',
            'description' => 'Activa o pausa el registro automático en audit_logs',
            'updated_by' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ])->saveData();
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
