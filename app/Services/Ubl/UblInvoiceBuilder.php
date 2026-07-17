<?php

namespace App\Services\Ubl;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Builds a minimal UBL 2.1 Invoice or CreditNote XML document aligned
 * with EN 16931, using the Peppol BIS Billing 3.0 customization.
 *
 * Input is a canonical invoice array:
 *
 *  [
 *      'type'       => 'invoice',           // optional: 'invoice' (default) | 'credit_note'
 *      'number'     => 'FA-2026-0001',
 *      'issue_date' => '2026-07-15',        // Y-m-d
 *      'due_date'   => '2026-07-29',        // optional, Y-m-d; ignored for credit notes
 *      'currency'   => 'EUR',               // ISO 4217
 *      'vat_currency' => 'EUR',             // optional BT-6: VAT accounting currency when the
 *                                           // document is in a foreign currency (SK: VAT in EUR)
 *      'vat_exchange_rate' => '0.0397',     // required with vat_currency: multiplier such that
 *                                           // amount_in_vat_currency = amount_in_document_currency × rate
 *      'prepaid_amount' => '500.00',        // optional BT-113: already-paid advances, deducted from payable
 *      'buyer_reference' => 'OBJ-123',      // BT-10; Peppol requires this or an order reference
 *      'invoice_reference' => 'FA-2026-0001', // optional; for credit notes: the corrected invoice number (BG-3)
 *      'supplier'   => [
 *          'name'       => 'Dodávateľ s.r.o.',
 *          'peppol_id'  => '0245:0000000001', // BT-34 electronic address "scheme:value"
 *          'street'     => 'Hlavná 1',      // optional
 *          'city'       => 'Bratislava',    // optional
 *          'zip'        => '811 01',        // optional
 *          'country'    => 'SK',            // ISO 3166-1 alpha-2
 *          'company_id' => '12345678',      // IČO, optional
 *          'vat_id'     => 'SK2020123456',  // IČ DPH, optional
 *      ],
 *      'customer'   => [ ... same shape ... ],
 *      'lines'      => [
 *          [
 *              'name'         => 'Konzultačné služby',
 *              'quantity'     => '10',
 *              'unit'         => 'HUR',     // UN/ECE rec 20, optional (default C62)
 *              'unit_price'   => '50.00',
 *              'vat_rate'     => '23',      // percent
 *              'vat_category' => 'S',       // UNCL5305, optional (default S)
 *              'vat_exemption_reason' => '…', // BT-121; required for category E, optional otherwise
 *          ],
 *      ],
 *  ]
 *
 * Line extension amounts, the VAT breakdown and document totals are computed
 * here with brick/math, rounded HALF_UP to 2 decimals per amount.
 */
class UblInvoiceBuilder
{
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
    private const PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';
    private const INVOICE_TYPE_CODE = '380';
    private const CREDIT_NOTE_TYPE_CODE = '381';

    // Reverse charge (AE) breakdowns must carry an exemption reason (BR-AE-10).
    private const REVERSE_CHARGE_REASON = 'Prenesenie daňovej povinnosti (reverse charge)';

    private DOMDocument $doc;

    public function build(array $invoice): string
    {
        $this->assertValid($invoice);

        $isCreditNote = ($invoice['type'] ?? 'invoice') === 'credit_note';
        $currency = $invoice['currency'];
        $lines = $this->computeLines($invoice['lines']);
        $breakdown = $this->computeVatBreakdown($lines);

        $lineTotal = array_reduce(
            $lines,
            fn (BigDecimal $sum, array $line) => $sum->plus($line['line_extension']),
            BigDecimal::zero()
        )->toScale(2);

        $vatTotal = array_reduce(
            $breakdown,
            fn (BigDecimal $sum, array $group) => $sum->plus($group['vat']),
            BigDecimal::zero()
        )->toScale(2);

        $taxInclusive = $lineTotal->plus($vatTotal);

        $prepaid = isset($invoice['prepaid_amount']) && $invoice['prepaid_amount'] !== ''
            ? BigDecimal::of($invoice['prepaid_amount'])->toScale(2, RoundingMode::HalfUp)
            : null;
        if ($prepaid !== null && $prepaid->isGreaterThan($taxInclusive)) {
            // Surfaces to the error queue via the pipeline — keep it Slovak.
            throw new InvalidArgumentException(
                "Odpočítaná záloha ({$prepaid}) je vyššia ako celková suma faktúry ({$taxInclusive})."
            );
        }
        $payable = $prepaid !== null ? $taxInclusive->minus($prepaid) : $taxInclusive;

        // BT-6: VAT accounting currency, only meaningful when it differs.
        $vatCurrency = $invoice['vat_currency'] ?? null;
        if ($vatCurrency === $currency) {
            $vatCurrency = null;
        }

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        $root = $this->doc->createElementNS(
            $isCreditNote ? self::NS_CREDIT_NOTE : self::NS_INVOICE,
            $isCreditNote ? 'CreditNote' : 'Invoice'
        );
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $this->doc->appendChild($root);

        $this->cbc($root, 'CustomizationID', self::CUSTOMIZATION_ID);
        $this->cbc($root, 'ProfileID', self::PROFILE_ID);
        $this->cbc($root, 'ID', $invoice['number']);
        $this->cbc($root, 'IssueDate', $invoice['issue_date']);
        if ($isCreditNote) {
            // UBL 2.1 CreditNote has no cbc:DueDate element.
            $this->cbc($root, 'CreditNoteTypeCode', self::CREDIT_NOTE_TYPE_CODE);
        } else {
            if (!empty($invoice['due_date'])) {
                $this->cbc($root, 'DueDate', $invoice['due_date']);
            }
            $this->cbc($root, 'InvoiceTypeCode', self::INVOICE_TYPE_CODE);
        }
        $this->cbc($root, 'DocumentCurrencyCode', $currency);
        if ($vatCurrency !== null) {
            $this->cbc($root, 'TaxCurrencyCode', $vatCurrency);
        }
        if (!empty($invoice['buyer_reference'])) {
            $this->cbc($root, 'BuyerReference', $invoice['buyer_reference']);
        }

        // BG-3: reference to the corrected invoice — expected on credit notes.
        if (!empty($invoice['invoice_reference'])) {
            $billingReference = $this->cac($root, 'BillingReference');
            $this->cbc(
                $this->cac($billingReference, 'InvoiceDocumentReference'),
                'ID',
                $invoice['invoice_reference']
            );
        }

        $this->appendParty($this->cac($root, 'AccountingSupplierParty'), $invoice['supplier']);
        $this->appendParty($this->cac($root, 'AccountingCustomerParty'), $invoice['customer']);

        $taxTotal = $this->cac($root, 'TaxTotal');
        $this->cbc($taxTotal, 'TaxAmount', (string) $vatTotal, ['currencyID' => $currency]);
        foreach ($breakdown as $group) {
            $subtotal = $this->cac($taxTotal, 'TaxSubtotal');
            $this->cbc($subtotal, 'TaxableAmount', (string) $group['taxable'], ['currencyID' => $currency]);
            $this->cbc($subtotal, 'TaxAmount', (string) $group['vat'], ['currencyID' => $currency]);
            $category = $this->cac($subtotal, 'TaxCategory');
            $this->cbc($category, 'ID', $group['category']);
            $this->cbc($category, 'Percent', (string) $group['rate']);
            if ($group['exemption_reason'] !== null) {
                $this->cbc($category, 'TaxExemptionReason', $group['exemption_reason']);
            }
            $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');
        }

        // BT-111: total VAT restated in the accounting currency — a second
        // TaxTotal carrying only the converted amount (BR-53).
        if ($vatCurrency !== null) {
            $vatInVatCurrency = $vatTotal
                ->multipliedBy(BigDecimal::of($invoice['vat_exchange_rate']))
                ->toScale(2, RoundingMode::HalfUp);
            $secondTaxTotal = $this->cac($root, 'TaxTotal');
            $this->cbc($secondTaxTotal, 'TaxAmount', (string) $vatInVatCurrency, ['currencyID' => $vatCurrency]);
        }

        $totals = $this->cac($root, 'LegalMonetaryTotal');
        $this->cbc($totals, 'LineExtensionAmount', (string) $lineTotal, ['currencyID' => $currency]);
        $this->cbc($totals, 'TaxExclusiveAmount', (string) $lineTotal, ['currencyID' => $currency]);
        $this->cbc($totals, 'TaxInclusiveAmount', (string) $taxInclusive, ['currencyID' => $currency]);
        if ($prepaid !== null) {
            $this->cbc($totals, 'PrepaidAmount', (string) $prepaid, ['currencyID' => $currency]);
        }
        $this->cbc($totals, 'PayableAmount', (string) $payable, ['currencyID' => $currency]);

        foreach ($lines as $line) {
            $this->appendLine($root, $line, $currency, $isCreditNote);
        }

        return $this->doc->saveXML();
    }

    /**
     * @return list<array{id: string, name: string, quantity: BigDecimal, unit: string,
     *               unit_price: BigDecimal, vat_category: string, vat_rate: BigDecimal,
     *               line_extension: BigDecimal}>
     */
    private function computeLines(array $lines): array
    {
        $computed = [];
        foreach (array_values($lines) as $index => $line) {
            $quantity = BigDecimal::of($line['quantity']);
            $unitPrice = BigDecimal::of($line['unit_price']);

            $computed[] = [
                'id' => (string) ($index + 1),
                'name' => $line['name'],
                'quantity' => $quantity,
                'unit' => $line['unit'] ?? 'C62',
                'unit_price' => $unitPrice,
                'vat_category' => $line['vat_category'] ?? 'S',
                'vat_rate' => BigDecimal::of($line['vat_rate']),
                'vat_exemption_reason' => $line['vat_exemption_reason'] ?? null,
                'line_extension' => $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp),
            ];
        }

        return $computed;
    }

    /**
     * Groups line extensions by (VAT category, rate) and computes the VAT
     * amount per group — the EN 16931 VAT breakdown (BG-23).
     *
     * @return list<array{category: string, rate: BigDecimal, taxable: BigDecimal, vat: BigDecimal}>
     */
    private function computeVatBreakdown(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = $line['vat_category'].'|'.$line['vat_rate'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $line['vat_category'],
                    'rate' => $line['vat_rate'],
                    'taxable' => BigDecimal::zero()->toScale(2),
                    // BT-121 per breakdown: first line reason wins; reverse
                    // charge gets a fixed default (BR-AE-10).
                    'exemption_reason' => $line['vat_exemption_reason']
                        ?? ($line['vat_category'] === 'AE' ? self::REVERSE_CHARGE_REASON : null),
                ];
            }
            $groups[$key]['taxable'] = $groups[$key]['taxable']->plus($line['line_extension']);
            $groups[$key]['exemption_reason'] ??= $line['vat_exemption_reason'];
        }

        foreach ($groups as &$group) {
            $group['vat'] = $group['taxable']
                ->multipliedBy($group['rate'])
                ->dividedBy(100, 2, RoundingMode::HalfUp);
        }

        return array_values($groups);
    }

    private function appendParty(DOMElement $wrapper, array $party): void
    {
        $node = $this->cac($wrapper, 'Party');

        // Electronic address (BT-34/BT-49), "scheme:value" e.g. "0245:0000000001".
        // Peppol requires it for both parties; EndpointID must be the first child.
        if (!empty($party['peppol_id'])) {
            [$scheme, $identifier] = $this->splitPeppolId($party['peppol_id']);
            $this->cbc($node, 'EndpointID', $identifier, ['schemeID' => $scheme]);
        }

        $this->cbc($this->cac($node, 'PartyName'), 'Name', $party['name']);

        $address = $this->cac($node, 'PostalAddress');
        if (!empty($party['street'])) {
            $this->cbc($address, 'StreetName', $party['street']);
        }
        if (!empty($party['city'])) {
            $this->cbc($address, 'CityName', $party['city']);
        }
        if (!empty($party['zip'])) {
            $this->cbc($address, 'PostalZone', $party['zip']);
        }
        $this->cbc($this->cac($address, 'Country'), 'IdentificationCode', $party['country']);

        if (!empty($party['vat_id'])) {
            $taxScheme = $this->cac($node, 'PartyTaxScheme');
            $this->cbc($taxScheme, 'CompanyID', $party['vat_id']);
            $this->cbc($this->cac($taxScheme, 'TaxScheme'), 'ID', 'VAT');
        }

        $legalEntity = $this->cac($node, 'PartyLegalEntity');
        $this->cbc($legalEntity, 'RegistrationName', $party['name']);
        if (!empty($party['company_id'])) {
            $this->cbc($legalEntity, 'CompanyID', $party['company_id']);
        }
    }

    private function appendLine(DOMElement $root, array $line, string $currency, bool $isCreditNote): void
    {
        $node = $this->cac($root, $isCreditNote ? 'CreditNoteLine' : 'InvoiceLine');
        $this->cbc($node, 'ID', $line['id']);
        $this->cbc(
            $node,
            $isCreditNote ? 'CreditedQuantity' : 'InvoicedQuantity',
            (string) $line['quantity'],
            ['unitCode' => $line['unit']]
        );
        $this->cbc($node, 'LineExtensionAmount', (string) $line['line_extension'], ['currencyID' => $currency]);

        $item = $this->cac($node, 'Item');
        $this->cbc($item, 'Name', $line['name']);
        $category = $this->cac($item, 'ClassifiedTaxCategory');
        $this->cbc($category, 'ID', $line['vat_category']);
        $this->cbc($category, 'Percent', (string) $line['vat_rate']);
        $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');

        $price = $this->cac($node, 'Price');
        $this->cbc($price, 'PriceAmount', (string) $line['unit_price'], ['currencyID' => $currency]);
    }

    private function assertValid(array $invoice): void
    {
        $type = $invoice['type'] ?? 'invoice';
        if (!in_array($type, ['invoice', 'credit_note'], true)) {
            throw new InvalidArgumentException(
                "Unsupported document type '{$type}' — expected 'invoice' or 'credit_note'."
            );
        }

        foreach (['number', 'issue_date', 'currency', 'supplier', 'customer', 'lines'] as $key) {
            if (empty($invoice[$key])) {
                throw new InvalidArgumentException("Missing required invoice field: {$key}");
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice['issue_date'])) {
            throw new InvalidArgumentException('issue_date must be in Y-m-d format');
        }

        $vatCurrency = $invoice['vat_currency'] ?? null;
        if ($vatCurrency !== null && $vatCurrency !== $invoice['currency']) {
            $rate = $invoice['vat_exchange_rate'] ?? null;
            if (!is_numeric($rate) || (float) $rate <= 0) {
                throw new InvalidArgumentException(
                    'vat_currency requires a positive numeric vat_exchange_rate '
                    .'(amount_in_vat_currency = amount_in_document_currency × rate)'
                );
            }
        }

        $prepaid = $invoice['prepaid_amount'] ?? null;
        if ($prepaid !== null && $prepaid !== '' && (!is_numeric($prepaid) || (float) $prepaid < 0)) {
            throw new InvalidArgumentException('prepaid_amount must be a non-negative number');
        }

        foreach (['supplier', 'customer'] as $partyKey) {
            foreach (['name', 'country'] as $field) {
                if (empty($invoice[$partyKey][$field])) {
                    throw new InvalidArgumentException("Missing required field: {$partyKey}.{$field}");
                }
            }
        }

        foreach (array_values($invoice['lines']) as $index => $line) {
            foreach (['name', 'quantity', 'unit_price', 'vat_rate'] as $field) {
                if (!isset($line[$field]) || $line[$field] === '') {
                    throw new InvalidArgumentException('Missing required field on line '.($index + 1).": {$field}");
                }
            }
        }
    }

    /**
     * @return array{0: string, 1: string} [scheme, identifier]
     */
    private function splitPeppolId(string $peppolId): array
    {
        $parts = explode(':', $peppolId, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException(
                "peppol_id must be in 'scheme:identifier' format, got '{$peppolId}'"
            );
        }

        return $parts;
    }

    private function cbc(DOMElement $parent, string $name, string $value, array $attributes = []): DOMElement
    {
        $element = $this->doc->createElementNS(self::NS_CBC, 'cbc:'.$name);
        $element->textContent = $value;
        foreach ($attributes as $attribute => $attributeValue) {
            $element->setAttribute($attribute, $attributeValue);
        }
        $parent->appendChild($element);

        return $element;
    }

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        $element = $this->doc->createElementNS(self::NS_CAC, 'cac:'.$name);
        $parent->appendChild($element);

        return $element;
    }
}
