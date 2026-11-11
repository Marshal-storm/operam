<?php

declare(strict_types=1);

namespace System\Core;

/**
 * Formateo de entrada/salida según definición de campos.
 */
final class DataFormat
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, array{hide_output?:bool}> $fields
     * @return array<string, mixed>
     */
    public static function formatInput(array $input, array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $_rules) {
            if (array_key_exists($name, $input)) {
                $out[$name] = $input[$name];
            }
        }

        return $out;
    }

    /**
     * Oculta campos con hide_output = true (p. ej. password_hash).
     *
     * @param array<string, mixed>|list<array<string, mixed>> $output
     * @param array<string, array{hide_output?:bool}> $fields
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function formatOutput(array $output, array $fields): array
    {
        $hidden = array_keys(array_filter(
            $fields,
            static fn (array $rules): bool => (bool) ($rules['hide_output'] ?? false)
        ));

        if ($hidden === []) {
            return $output;
        }

        if (array_is_list($output)) {
            return array_map(
                static fn (array $row): array => self::stripHidden($row, $hidden),
                $output
            );
        }

        return self::stripHidden($output, $hidden);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $hidden
     * @return array<string, mixed>
     */
    private static function stripHidden(array $row, array $hidden): array
    {
        foreach ($hidden as $field) {
            unset($row[$field]);
        }

        return $row;
    }
}
