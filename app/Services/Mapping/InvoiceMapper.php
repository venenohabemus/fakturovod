<?php

namespace App\Services\Mapping;

/**
 * Applies a mapping definition (versioned JSON per client — data, not code)
 * to source records and produces canonical invoice arrays in the shape
 * UblInvoiceBuilder consumes.
 *
 * Definition shape:
 *
 *  {
 *      "version": 1,
 *      "source": { "type": "csv"|"xml", "group_by": "field", ...reader config },
 *      "invoice": {
 *          "number": <field spec>, "issue_date": ..., "due_date": ..., "currency": ...,
 *          "supplier": { "name": ..., "country": ..., ... },
 *          "customer": { ... },
 *          "lines": { "fields": { "name": ..., "quantity": ..., "unit_price": ...,
 *                                 "vat_rate": ..., "unit": ..., "vat_category": ... } }
 *      }
 *  }
 *
 * With source.group_by set, records sharing the group value form one invoice
 * (header fields taken from the first record, one line per record). Without
 * it, all records form a single invoice.
 */
class InvoiceMapper
{
    private const PARTY_FIELDS = ['name', 'street', 'city', 'zip', 'country', 'company_id', 'vat_id', 'peppol_id'];
    private const LINE_OPTIONAL_FIELDS = ['unit', 'vat_category', 'vat_exemption_reason'];
    private const LINE_REQUIRED_FIELDS = ['name', 'quantity', 'unit_price', 'vat_rate'];

    public function __construct(
        private readonly FieldResolver $resolver = new FieldResolver(),
    ) {
    }

    /**
     * @param list<Record> $records
     * @return list<array> canonical invoices
     */
    public function map(array $definition, array $records): array
    {
        $invoiceDefinition = $definition['invoice']
            ?? throw new MappingException("V mapovacej definícii chýba sekcia 'invoice'.");

        if ($records === []) {
            throw new MappingException('Vstupný súbor neobsahuje žiadne záznamy.');
        }

        $invoices = [];
        foreach ($this->groupRecords($definition, $records) as $group) {
            $invoices[] = $this->mapGroup($definition, $group);
        }

        return $invoices;
    }

    /**
     * Splits source records into invoice groups, keyed by the group_by
     * value (a single '' key when no group_by is configured).
     *
     * @param list<Record> $records
     * @return array<string, non-empty-list<Record>>
     */
    public function groupRecords(array $definition, array $records): array
    {
        $groupBy = $definition['source']['group_by'] ?? null;
        if ($groupBy === null) {
            return $records === [] ? [] : ['' => $records];
        }

        $groups = [];
        foreach ($records as $record) {
            $key = $record->get($groupBy)
                ?? throw new MappingException(
                    "Chýba hodnota zoskupovacieho poľa '{$groupBy}' ({$record->describe()})."
                );
            $groups[$key][] = $record;
        }

        return $groups;
    }

    /**
     * Maps one invoice group to the canonical invoice array.
     *
     * @param non-empty-list<Record> $group
     */
    public function mapGroup(array $definition, array $group): array
    {
        $invoiceDefinition = $definition['invoice']
            ?? throw new MappingException("V mapovacej definícii chýba sekcia 'invoice'.");

        return $this->mapInvoice($invoiceDefinition, $group);
    }

    /**
     * Maps one invoice, collecting ALL problems before failing — the error
     * queue must show everything at once so the client fixes the file in
     * a single pass, not one error at a time.
     *
     * @param non-empty-list<Record> $group
     */
    private function mapInvoice(array $definition, array $group): array
    {
        $header = $group[0];
        $errors = [];

        $invoice = [
            'number' => $this->attempt($errors, fn () => $this->requiredValue($definition, 'number', $header)),
            'issue_date' => $this->attempt($errors, fn () => $this->requiredValue($definition, 'issue_date', $header)),
            'currency' => $this->attempt($errors, fn () => $this->requiredValue($definition, 'currency', $header)),
            'supplier' => $this->attempt($errors, fn () => $this->mapParty($definition, 'supplier', $header)),
            'customer' => $this->attempt($errors, fn () => $this->mapParty($definition, 'customer', $header)),
            'lines' => [],
        ];

        foreach ($group as $record) {
            $line = $this->attempt($errors, fn () => $this->mapLine($definition, $record));
            if ($line !== null) {
                $invoice['lines'][] = $line;
            }
        }

        foreach (['type', 'due_date', 'buyer_reference', 'invoice_reference'] as $optionalField) {
            if (!isset($definition[$optionalField])) {
                continue;
            }
            $value = $this->attempt(
                $errors,
                fn () => $this->resolver->resolve($definition[$optionalField], $header, $optionalField)
            );
            if ($value !== null) {
                $invoice[$optionalField] = $value;
            }
        }

        if ($errors !== []) {
            throw MappingException::withErrors($errors);
        }

        return $invoice;
    }

    private function mapParty(array $definition, string $partyKey, Record $record): array
    {
        $partyDefinition = $definition[$partyKey]
            ?? throw new MappingException("V mapovacej definícii chýba sekcia '{$partyKey}'.");

        $errors = [];
        $party = [];
        foreach (self::PARTY_FIELDS as $field) {
            $required = in_array($field, ['name', 'country'], true);

            if (!isset($partyDefinition[$field])) {
                if ($required) {
                    $errors[] = "V mapovacej definícii chýba pole '{$partyKey}.{$field}'.";
                }
                continue;
            }

            $errorsBefore = count($errors);
            $value = $this->attempt(
                $errors,
                fn () => $this->resolver->resolve($partyDefinition[$field], $record, "{$partyKey}.{$field}")
            );

            if ($value !== null) {
                $party[$field] = $value;
            } elseif ($required && count($errors) === $errorsBefore) {
                $errors[] = "Chýba povinná hodnota poľa '{$partyKey}.{$field}' ({$record->describe()}).";
            }
        }

        if ($errors !== []) {
            throw MappingException::withErrors($errors);
        }

        return $party;
    }

    private function mapLine(array $definition, Record $record): array
    {
        $fields = $definition['lines']['fields']
            ?? throw new MappingException("V mapovacej definícii chýba sekcia 'lines.fields'.");

        $errors = [];
        $line = [];
        foreach (self::LINE_REQUIRED_FIELDS as $field) {
            $spec = $fields[$field] ?? null;
            if ($spec === null) {
                $errors[] = "V mapovacej definícii chýba pole 'lines.fields.{$field}'.";
                continue;
            }
            $errorsBefore = count($errors);
            $value = $this->attempt(
                $errors,
                fn () => $this->resolver->resolve($spec, $record, "lines.{$field}")
            );
            if ($value === null) {
                if (count($errors) === $errorsBefore) {
                    $errors[] = "Chýba povinná hodnota poľa '{$field}' na položke faktúry ({$record->describe()}).";
                }
                continue;
            }
            $line[$field] = $value;
        }

        foreach (self::LINE_OPTIONAL_FIELDS as $field) {
            if (!isset($fields[$field])) {
                continue;
            }
            $value = $this->attempt(
                $errors,
                fn () => $this->resolver->resolve($fields[$field], $record, "lines.{$field}")
            );
            if ($value !== null) {
                $line[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw MappingException::withErrors($errors);
        }

        return $line;
    }

    private function requiredValue(array $definition, string $field, Record $record): string
    {
        $spec = $definition[$field]
            ?? throw new MappingException("V mapovacej definícii chýba pole '{$field}'.");

        return $this->resolver->resolve($spec, $record, $field)
            ?? throw new MappingException(
                "Chýba povinná hodnota poľa '{$field}' ({$record->describe()})."
            );
    }

    /**
     * Runs one mapping step; on failure appends its error message(s) to the
     * collection and returns null so the remaining steps still run.
     *
     * @template T
     * @param list<string> $errors
     * @param callable(): T $step
     * @return T|null
     */
    private function attempt(array &$errors, callable $step): mixed
    {
        try {
            return $step();
        } catch (MappingException $exception) {
            array_push($errors, ...$exception->errors);

            return null;
        }
    }
}
