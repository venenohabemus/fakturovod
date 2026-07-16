<?php

namespace App\Services\Mapping;

use DOMNode;
use DOMXPath;

class XmlRecord implements Record
{
    public function __construct(
        private readonly DOMXPath $xpath,
        private readonly DOMNode $node,
        private readonly string $label,
    ) {
    }

    public function get(string $path): ?string
    {
        $value = $this->xpath->evaluate("string({$path})", $this->node);
        if ($value === false) {
            throw new MappingException("Neplatný XPath výraz '{$path}' v mapovacej definícii.");
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function describe(): string
    {
        return $this->label;
    }

    public function export(): array
    {
        return [
            'type' => 'xml',
            'xml' => $this->node->ownerDocument->saveXML($this->node),
            'label' => $this->label,
        ];
    }
}
