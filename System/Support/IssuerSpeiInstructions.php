<?php

declare(strict_types=1);

namespace System\Support;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Instrucciones SPEI reales del emisor (RF-7.6) — sin datos bancarios quemados en código.
 * Compartido por el tablero del Portal y el checkout SPEI de la Tienda.
 */
final class IssuerSpeiInstructions
{
    /** @return array<string, mixed> */
    public static function forIssuer(int $issuerId): array
    {
        return self::fromRow(
            $issuerId > 0 ? Capsule::table('issuers')->where('id', $issuerId)->first() : null,
        );
    }

    /** @return array<string, mixed> */
    public static function fromRow(?object $issuer): array
    {
        $clabe = $issuer !== null ? trim((string) ($issuer->spei_clabe ?? '')) : '';

        return [
            'issuer_id' => $issuer !== null ? (int) $issuer->id : null,
            'issuer_name' => $issuer !== null ? (string) $issuer->name : null,
            'bank_name' => self::nullableText($issuer, 'spei_bank_name'),
            'clabe' => $clabe !== '' ? $clabe : null,
            'account_holder' => self::nullableText($issuer, 'spei_account_holder'),
            'reference_hint' => self::nullableText($issuer, 'spei_reference_hint'),
            'is_configured' => $clabe !== '',
        ];
    }

    private static function nullableText(?object $row, string $field): ?string
    {
        if ($row === null) {
            return null;
        }
        $value = trim((string) ($row->{$field} ?? ''));

        return $value !== '' ? $value : null;
    }
}
