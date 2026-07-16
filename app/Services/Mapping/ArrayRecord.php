<?php

namespace App\Services\Mapping;

class ArrayRecord implements Record
{
    /**
     * @param array<string, string|null> $values
     */
    public function __construct(
        private readonly array $values,
        private readonly string $label,
    ) {
    }

    public function get(string $path): ?string
    {
        $value = $this->values[$path] ?? null;
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function describe(): string
    {
        return $this->label;
    }
}
