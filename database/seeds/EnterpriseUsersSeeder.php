<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Usuarios iniciales empresariales (idempotente).
 * Hash: ARGON2ID con fallback BCRYPT.
 */
final class EnterpriseUsersSeeder extends AbstractSeed
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

        $users = [
            [
                'email' => 'admin@operam.mx',
                'nombre' => 'Admin General',
                'password' => 'Operam2026*',
                'rol' => 'admin',
                'segmento_id' => null,
            ],
            [
                'email' => 'operador@operam.mx',
                'nombre' => 'Operador Operam',
                'password' => 'Operam2026*',
                'rol' => 'operador',
                'segmento_id' => $operamId,
            ],
            [
                'email' => 'supervisor@construsync.mx',
                'nombre' => 'Supervisor Construsync',
                'password' => 'Constru2026*',
                'rol' => 'operador',
                'segmento_id' => $construId,
            ],
        ];

        $table = $this->table('usuario');

        foreach ($users as $user) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM usuario WHERE email = '%s'",
                $this->quote($user['email'])
            ));

            if ($existing) {
                continue;
            }

            $table->insert([
                'email' => $user['email'],
                'nombre' => $user['nombre'],
                'password_hash' => $this->hashPassword($user['password']),
                'rol' => $user['rol'],
                'segmento_id' => $user['segmento_id'],
                'activo' => 1,
                'ultimo_acceso' => null,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ])->saveData();
        }
    }

    private function hashPassword(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID);
        }

        return password_hash($plain, PASSWORD_BCRYPT);
    }

    private function segmentIdByName(string $nombre): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM segmento WHERE nombre = '%s'",
            $this->quote($nombre)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
