<?php

namespace App\Services\Mapping;

use DateTimeImmutable;

/**
 * Resolves a single field of the mapping definition against a source record.
 *
 * A field spec is either a plain string (shorthand for ['from' => ...]) or:
 *
 *  [
 *      'from'        => 'source_field',      // or 'const' => 'fixed value'
 *      'default'     => 'used when empty',   // optional
 *      'map'         => ['ks' => 'C62'],     // optional lookup table
 *      'map_default' => 'C62',               // optional fallback for unmapped values
 *      'transform'   => [                    // optional, applied in order
 *          ['type' => 'date', 'from_format' => 'd.m.Y'],
 *          ['type' => 'decimal', 'decimal_separator' => ','],
 *          ['type' => 'trim'], ['type' => 'upper'], ['type' => 'lower'],
 *      ],
 *  ]
 */
class FieldResolver
{
    public function resolve(array|string $spec, Record $record, string $field): ?string
    {
        if (is_string($spec)) {
            $spec = ['from' => $spec];
        }

        if (array_key_exists('const', $spec)) {
            $value = trim((string) $spec['const']);
        } elseif (isset($spec['from'])) {
            $value = $record->get($spec['from']);
        } else {
            throw new MappingException(
                "Definícia poľa '{$field}' musí obsahovať 'from' alebo 'const'."
            );
        }

        if (($value === null || $value === '') && array_key_exists('default', $spec)) {
            $value = (string) $spec['default'];
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (isset($spec['map'])) {
            $value = $this->applyMap($spec, $value, $field, $record);
        }

        foreach ($spec['transform'] ?? [] as $transform) {
            $value = $this->applyTransform($transform, $value, $field, $record);
        }

        return $value;
    }

    private function applyMap(array $spec, string $value, string $field, Record $record): string
    {
        if (array_key_exists($value, $spec['map'])) {
            return (string) $spec['map'][$value];
        }

        if (array_key_exists('map_default', $spec)) {
            return (string) $spec['map_default'];
        }

        throw new MappingException(sprintf(
            "Neznáma hodnota '%s' poľa '%s' (%s). Povolené hodnoty: %s.",
            $value,
            $field,
            $record->describe(),
            implode(', ', array_keys($spec['map']))
        ));
    }

    private function applyTransform(array|string $transform, string $value, string $field, Record $record): string
    {
        if (is_string($transform)) {
            $transform = ['type' => $transform];
        }

        $type = $transform['type'] ?? '';

        return match ($type) {
            'trim' => trim($value),
            'upper' => mb_strtoupper($value),
            'lower' => mb_strtolower($value),
            'date' => $this->transformDate($transform, $value, $field, $record),
            'decimal' => $this->transformDecimal($transform, $value, $field, $record),
            default => throw new MappingException(
                "Neznáma transformácia '{$type}' poľa '{$field}' v mapovacej definícii."
            ),
        };
    }

    private function transformDate(array $transform, string $value, string $field, Record $record): string
    {
        $format = $transform['from_format'] ?? 'Y-m-d';

        // '!' resets unspecified parts to zero so the parse is strict about the date itself
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $parseErrors = DateTimeImmutable::getLastErrors();
        $hasParseIssue = $parseErrors !== false
            && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0);
        if ($date === false || $hasParseIssue) {
            throw new MappingException(sprintf(
                "Hodnota '%s' poľa '%s' nezodpovedá formátu dátumu '%s' (%s).",
                $value,
                $field,
                $format,
                $record->describe()
            ));
        }

        return $date->format('Y-m-d');
    }

    private function transformDecimal(array $transform, string $value, string $field, Record $record): string
    {
        $decimalSeparator = $transform['decimal_separator'] ?? ',';
        $thousandsSeparator = $transform['thousands_separator'] ?? ' ';

        $normalized = str_replace("\u{00A0}", '', $value); // non-breaking spaces from legacy exports
        if ($thousandsSeparator !== '') {
            $normalized = str_replace($thousandsSeparator, '', $normalized);
        }
        $normalized = str_replace($decimalSeparator, '.', $normalized);

        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new MappingException(sprintf(
                "Hodnota '%s' poľa '%s' nie je platné číslo (%s).",
                $value,
                $field,
                $record->describe()
            ));
        }

        return $normalized;
    }
}
