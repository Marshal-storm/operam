<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use System\Support\ClientRules;

/**
 * Reglas de negocio del directorio de clientes (sin MySQL).
 */
final class ClientRulesTest extends TestCase
{
    public function testNormalizeRfcUppercasesAndTrims(): void
    {
        $this->assertSame('ALO150315ABC', ClientRules::normalizeRfc('  alo150315abc  '));
        $this->assertSame('XAXX010101000', ClientRules::normalizeRfc('xaxx010101000'));
    }

    public function testValidateRfcFormatRejectsEmptyAndInvalid(): void
    {
        $this->assertSame('El RFC es obligatorio.', ClientRules::validateRfcFormat('   '));
        $this->assertNotNull(ClientRules::validateRfcFormat('ABC123'));
        $this->assertNull(ClientRules::validateRfcFormat('ALO150315ABC'));
        $this->assertNull(ClientRules::validateRfcFormat('XAXX010101000'));
    }

    public function testResolveCreditDaysInheritsFromSegmentWhenEmpty(): void
    {
        $resolved = ClientRules::resolveCreditDays(null, 15);
        $this->assertSame(15, $resolved['credit_days']);
        $this->assertTrue($resolved['inherited']);

        $resolvedEmpty = ClientRules::resolveCreditDays('', 30);
        $this->assertSame(30, $resolvedEmpty['credit_days']);
        $this->assertTrue($resolvedEmpty['inherited']);
    }

    public function testResolveCreditDaysKeepsExplicitValue(): void
    {
        $resolved = ClientRules::resolveCreditDays(45, 15);
        $this->assertSame(45, $resolved['credit_days']);
        $this->assertFalse($resolved['inherited']);
    }

    public function testResolveCreditDaysRejectsNegative(): void
    {
        $resolved = ClientRules::resolveCreditDays(-1, 30);
        $this->assertArrayHasKey('error', $resolved);
        $this->assertStringContainsString('≥ 0', (string) $resolved['error']);
    }

    public function testResolveCreditDaysFailsWithoutSegmentDefault(): void
    {
        $resolved = ClientRules::resolveCreditDays(null, null);
        $this->assertArrayHasKey('error', $resolved);
    }
}
