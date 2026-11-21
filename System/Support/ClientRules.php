<?php

declare(strict_types=1);

namespace System\Support;

/**
 * Reglas de negocio puras del directorio de clientes (sin I/O).
 * Usadas por ClientsService y por tests unitarios.
 */
final class ClientRules
{
    /**
     * Normaliza RFC a mayúsculas sin espacios laterales.
     */
    public static function normalizeRfc(string $rfc): string
    {
        return strtoupper(trim($rfc));
    }

    /**
     * Valida formato SAT (12–13 caracteres). Null = válido.
     */
    public static function validateRfcFormat(string $rfc): ?string
    {
        $value = self::normalizeRfc($rfc);
        if ($value === '') {
            return 'El RFC es obligatorio.';
        }
        if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $value)) {
            return 'RFC inválido. Use 12 o 13 caracteres en mayúsculas (formato SAT).';
        }

        return null;
    }

    /**
     * Resuelve días de crédito: si el input viene vacío/null, hereda el default del segmento.
     *
     * @return array{credit_days: int, inherited: bool}|array{error: string}
     */
    public static function resolveCreditDays(mixed $inputDays, ?int $segmentDefaultDays): array
    {
        if ($inputDays === null || $inputDays === '') {
            if ($segmentDefaultDays === null || $segmentDefaultDays < 0) {
                return ['error' => 'No se pudo heredar días de crédito del segmento.'];
            }

            return ['credit_days' => $segmentDefaultDays, 'inherited' => true];
        }

        if (!is_numeric($inputDays)) {
            return ['error' => 'Los días de crédito deben ser un número entero ≥ 0.'];
        }

        $days = (int) $inputDays;
        if ($days < 0) {
            return ['error' => 'Los días de crédito deben ser ≥ 0.'];
        }

        return ['credit_days' => $days, 'inherited' => false];
    }
}
