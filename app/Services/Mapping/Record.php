<?php

namespace App\Services\Mapping;

/**
 * One source record (a CSV row, an XML element, ...) the mapper reads
 * values from. Implementations decide what a field path means: a column
 * name for CSV, a relative XPath for XML.
 */
interface Record
{
    /**
     * Returns the trimmed value at the given path, or null when the field
     * is missing or empty.
     */
    public function get(string $path): ?string;

    /**
     * Human-readable position of the record in the source, used in Slovak
     * error messages — e.g. "riadok 3" or "záznam č. 2".
     */
    public function describe(): string;

    /**
     * Serializable representation for persisting the raw source data with
     * an invoice. RecordFactory::fromExport() restores it.
     *
     * @return array{type: string, label: string, ...}
     */
    public function export(): array;
}
