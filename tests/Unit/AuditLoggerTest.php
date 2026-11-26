<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\AuditLogger;

/**
 * Sanitización de secretos y cálculo de diff (sin tocar MySQL).
 */
final class AuditLoggerTest extends TestCase
{
    public function testSanitizeRedactsPasswordAndSecretKeys(): void
    {
        $input = [
            'email' => 'admin@operam.mx',
            'password' => 'Operam2026*',
            'password_hash' => '$argon2id$v=19$...',
            'secret_ref' => 'vault/erp/key',
            'api_token' => 'tok_live_abc',
            'name' => 'Admin',
            'nested' => [
                'token' => 'should-hide',
                'rfc' => 'OPE010101AAA',
            ],
        ];

        $sanitized = AuditLogger::sanitize($input);

        $this->assertSame('admin@operam.mx', $sanitized['email']);
        $this->assertSame('Admin', $sanitized['name']);
        $this->assertSame('[REDACTED]', $sanitized['password']);
        $this->assertSame('[REDACTED]', $sanitized['password_hash']);
        $this->assertSame('[REDACTED]', $sanitized['secret_ref']);
        $this->assertSame('[REDACTED]', $sanitized['api_token']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['token']);
        $this->assertSame('OPE010101AAA', $sanitized['nested']['rfc']);
    }

    public function testDiffReturnsOnlyChangedFields(): void
    {
        $old = [
            'name' => 'Antes',
            'is_active' => true,
            'rfc' => 'AAA010101AAA',
            'password_hash' => '[REDACTED]',
        ];
        $new = [
            'name' => 'Después',
            'is_active' => true,
            'rfc' => 'AAA010101AAA',
            'password_hash' => '[REDACTED]',
        ];

        $diff = AuditLogger::diff($old, $new);

        $this->assertSame(['name' => 'Antes'], $diff['old']);
        $this->assertSame(['name' => 'Después'], $diff['new']);
        $this->assertArrayNotHasKey('is_active', $diff['old']);
        $this->assertArrayNotHasKey('rfc', $diff['new']);
    }

    public function testDiffEmptyWhenIdentical(): void
    {
        $row = ['code' => 'OP-BASE', 'is_active' => 1];
        $diff = AuditLogger::diff($row, $row);
        $this->assertSame([], $diff['old']);
        $this->assertSame([], $diff['new']);
    }

    public function testSkipsClientPortalScopeAndPortalApiRoutes(): void
    {
        $this->assertTrue(AuditLogger::shouldSkipPortalContext(['scope' => 'client_portal']));
        $this->assertTrue(AuditLogger::shouldSkipPortalContext(['scope' => 'CLIENT_PORTAL']));
        $this->assertTrue(AuditLogger::shouldSkipPortalContext([], '/api/portal/requests'));
        $this->assertTrue(AuditLogger::shouldSkipPortalContext([], '/api/portal/store/checkout?contract_id=1'));
        $this->assertTrue(AuditLogger::shouldSkipPortalContext([], 'api/portal/auth/exchange'));

        $this->assertFalse(AuditLogger::shouldSkipPortalContext(['user_id' => 1], '/api/clients'));
        $this->assertFalse(AuditLogger::shouldSkipPortalContext(['user_id' => 1, 'scope' => 'internal'], '/api/services'));
        // Acciones de admin interno (generar liga mágica desde panel) NO deben omitirse.
        $this->assertFalse(AuditLogger::shouldSkipPortalContext(['user_id' => 1], '/api/clients/10/contacts/2/magic-link'));
    }

    public function testLogReturnsImmediatelyForClientPortalEvenWhenForced(): void
    {
        // Sin Capsule inicializado, si intentara consultar/insertar en MySQL lanzaría error interno;
        // al retornar en la guarda inicial, el flujo termina de inmediato sin tocar BD.
        AuditLogger::log(
            ['scope' => 'client_portal', 'name' => 'Cliente Portal', 'email' => 'cliente@ejemplo.com'],
            'portal',
            'purchases',
            '42',
            'create',
            'Compra en tienda del portal',
            null,
            ['total' => '1160.00'],
            true,
        );

        $this->assertTrue(true);
    }
}
