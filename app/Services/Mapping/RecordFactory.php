<?php

namespace App\Services\Mapping;

use DOMDocument;
use DOMXPath;

/**
 * Restores Record instances from their export() representation — used when
 * reprocessing an invoice whose raw source rows are persisted in the DB.
 */
class RecordFactory
{
    public static function fromExport(array $export): Record
    {
        $label = $export['label'] ?? 'záznam';

        return match ($export['type'] ?? '') {
            'array' => new ArrayRecord($export['values'] ?? [], $label),
            'xml' => self::xmlRecord($export['xml'] ?? '', $label),
            default => throw new MappingException(
                "Neznámy typ uloženého záznamu '".($export['type'] ?? '')."'."
            ),
        };
    }

    private static function xmlRecord(string $fragment, string $label): XmlRecord
    {
        $document = new DOMDocument();
        if ($fragment === '' || !@$document->loadXML($fragment)) {
            throw new MappingException('Uložený XML záznam sa nepodarilo obnoviť.');
        }

        return new XmlRecord(new DOMXPath($document), $document->documentElement, $label);
    }
}
