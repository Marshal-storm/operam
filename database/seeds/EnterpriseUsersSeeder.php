<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Usuarios iniciales empresariales (idempotente).
 * Hash: ARGON2ID con fallback BCRYPT.
 *
 * `users` no tiene columna `rol`. role_id queda NULL aquí y lo asigna
 * RbacRolesAndPermissionsSeeder. Nombres visibles permanecen en español.
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
                'name' => 'Admin General',
                'password' => 'Operam2026*',
                'segment_id' => null,
            ],
            [
                'email' => 'operador@operam.mx',
                'name' => 'Operador Operam',
                'password' => 'Operam2026*',
                'segment_id' => $operamId,
            ],
            [
                'email' => 'supervisor@construsync.mx',
                'name' => 'Supervisor Construsync',
                'password' => 'Constru2026*',
                'segment_id' => $construId,
            ],
        ];

        $table = $this->table('users');

        foreach ($users as $user) {
            $existing = $this->fetchRow(sprintf(
                "SELECT id FROM users WHERE email = '%s'",
                $this->quote($user['email'])
            ));

            if ($existing) {
                continue;
            }

            $table->insert([
                'email' => $user['email'],
                'name' => $user['name'],
                'password_hash' => $this->hashPassword($user['password']),
                'segment_id' => $user['segment_id'],
                'is_active' => 1,
                'last_login_at' => null,
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

    private function segmentIdByName(string $name): ?int
    {
        $row = $this->fetchRow(sprintf(
            "SELECT id FROM segments WHERE name = '%s'",
            $this->quote($name)
        ));

        return $row ? (int) $row['id'] : null;
    }

    private function quote(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
