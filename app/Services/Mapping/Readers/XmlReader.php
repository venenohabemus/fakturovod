<?php

namespace App\Services\Mapping\Readers;

use App\Services\Mapping\MappingException;
use App\Services\Mapping\XmlRecord;
use DOMDocument;
use DOMXPath;

/**
 * Reads an XML export into records. Source config:
 *  - record_xpath (required): XPath selecting one node per record,
 *    e.g. '/faktury/faktura'. Field paths in the mapping are then
 *    evaluated relative to that node.
 */
class XmlReader
{
    /**
     * @return list<XmlRecord>
     */
    public function read(string $content, array $config = []): array
    {
        $recordPath = $config['record_xpath']
            ?? throw new MappingException("V mapovaní chýba 'source.record_xpath' pre XML vstup.");

        $previousSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument();
            if (!$document->loadXML($content)) {
                $firstError = libxml_get_errors()[0] ?? null;
                throw new MappingException(
                    'Vstupný XML súbor sa nepodarilo načítať'
                    .($firstError !== null ? ': '.trim($firstError->message) : '.')
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query($recordPath);
        if ($nodes === false) {
            throw new MappingException("Neplatný XPath výraz '{$recordPath}' v mapovacej definícii.");
        }

        $records = [];
        foreach ($nodes as $index => $node) {
            $records[] = new XmlRecord($xpath, $node, 'záznam č. '.($index + 1));
        }

        return $records;
    }
}
