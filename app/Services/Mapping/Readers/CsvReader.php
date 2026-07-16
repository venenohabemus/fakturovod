<?php

namespace App\Services\Mapping\Readers;

use App\Services\Mapping\ArrayRecord;
use App\Services\Mapping\MappingException;

/**
 * Reads a CSV export into records keyed by header column names.
 *
 * Source config (all optional):
 *  - delimiter: default ';'
 *  - enclosure: default '"'
 *  - encoding:  source encoding, converted to UTF-8 (e.g. 'Windows-1250')
 */
class CsvReader
{
    /**
     * @return list<ArrayRecord>
     */
    public function read(string $content, array $config = []): array
    {
        $encoding = strtoupper($config['encoding'] ?? 'UTF-8');
        if ($encoding !== 'UTF-8') {
            // iconv, not mbstring: the Windows PHP build lacks mbstring support
            // for CP1250, the most common encoding of Slovak legacy exports
            $converted = @iconv($encoding, 'UTF-8', $content);
            if ($converted === false) {
                throw new MappingException(
                    "Vstupný súbor sa nepodarilo prekódovať z '{$encoding}' do UTF-8."
                );
            }
            $content = $converted;
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // strip UTF-8 BOM

        $delimiter = $config['delimiter'] ?? ';';
        $enclosure = $config['enclosure'] ?? '"';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $records = [];
        $rowNumber = 0;

        try {
            while (($row = fgetcsv($stream, null, $delimiter, $enclosure)) !== false) {
                $rowNumber++;

                if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                    continue; // blank line
                }

                if ($header === null) {
                    $header = array_map(fn ($column) => trim((string) $column), $row);
                    continue;
                }

                $values = [];
                foreach ($header as $index => $column) {
                    $values[$column] = isset($row[$index]) ? (string) $row[$index] : null;
                }

                $records[] = new ArrayRecord($values, "riadok {$rowNumber}");
            }
        } finally {
            fclose($stream);
        }

        if ($header === null) {
            throw new MappingException('Vstupný CSV súbor je prázdny.');
        }

        return $records;
    }
}
