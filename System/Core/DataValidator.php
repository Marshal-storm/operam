<?php

declare(strict_types=1);

namespace System\Core;

/**
 * Validación de campos al estilo operam/servicios.
 */
final class DataValidator
{
    /** @var array<string, array{type:string,required:bool,hide_output:bool}> */
    private array $fields = [];

    /** @var list<string> */
    private array $errors = [];

    public function setField(
        string $name,
        string $type,
        bool $required = true,
        mixed $default = null,
        mixed $options = null,
        bool $hideOutput = false,
    ): self {
        $this->fields[$name] = [
            'type' => $type,
            'required' => $required,
            'default' => $default,
            'options' => $options,
            'hide_output' => $hideOutput,
        ];

        return $this;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,data:array<string,mixed>,errors:list<string>}
     */
    public function startValidation(array $input): array
    {
        $this->errors = [];
        $data = [];

        foreach ($this->fields as $name => $rules) {
            $value = $input[$name] ?? $rules['default'];

            if ($rules['required'] && ($value === null || $value === '')) {
                $this->errors[] = "El campo {$name} es obligatorio.";
                continue;
            }

            if ($value === null || $value === '') {
                $data[$name] = $rules['default'];
                continue;
            }

            $data[$name] = match ($rules['type']) {
                DataTypes::Email => $this->validateEmail($name, (string) $value),
                DataTypes::Integer => (int) $value,
                DataTypes::Boolean => (bool) $value,
                default => $value,
            };
        }

        return [
            'ok' => $this->errors === [],
            'data' => $data,
            'errors' => $this->errors,
        ];
    }

    /** @return array<string, array{type:string,required:bool,hide_output:bool}> */
    public function getFields(): array
    {
        return $this->fields;
    }

    private function validateEmail(string $name, string $value): string
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "El campo {$name} no es un correo válido.";
        }

        return strtolower(trim($value));
    }
}
