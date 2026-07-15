<?php

namespace App\Services\Ubl;

use DOMDocument;
use InvalidArgumentException;

/**
 * Layer 1 of invoice validation: structural check against the UBL 2.1
 * XSD schema. Schematron (EN 16931 / Peppol BIS rules) and business
 * checks are separate layers on top of this.
 */
class XsdValidator
{
    public function __construct(private readonly string $xsdPath)
    {
        if (!is_file($this->xsdPath)) {
            throw new InvalidArgumentException("XSD schema not found: {$this->xsdPath}");
        }
    }

    /**
     * @return list<string> validation errors; empty array means the document is valid
     */
    public function validate(string $xml): array
    {
        $previousSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument();
            if (!$document->loadXML($xml)) {
                return $this->collectErrors();
            }

            if (!$document->schemaValidate($this->xsdPath)) {
                return $this->collectErrors();
            }

            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
        }
    }

    /**
     * @return list<string>
     */
    private function collectErrors(): array
    {
        $errors = [];
        foreach (libxml_get_errors() as $error) {
            $errors[] = sprintf('riadok %d: %s', $error->line, trim($error->message));
        }

        return $errors;
    }
}
