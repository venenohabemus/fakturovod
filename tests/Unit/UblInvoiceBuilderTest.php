<?php

namespace Tests\Unit;

use App\Services\Ubl\UblInvoiceBuilder;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UblInvoiceBuilderTest extends TestCase
{
    private function baseInvoice(array $overrides = []): array
    {
        return array_merge([
            'number' => 'FA-2026-0001',
            'issue_date' => '2026-07-15',
            'due_date' => '2026-07-29',
            'currency' => 'EUR',
            'supplier' => [
                'name' => 'Dodávateľ s.r.o.',
                'street' => 'Hlavná 1',
                'city' => 'Bratislava',
                'zip' => '811 01',
                'country' => 'SK',
                'company_id' => '12345678',
                'vat_id' => 'SK2020123457',
            ],
            'customer' => [
                'name' => 'Odberateľ a.s.',
                'country' => 'SK',
            ],
            'lines' => [
                [
                    'name' => 'Konzultačné služby',
                    'quantity' => '10',
                    'unit' => 'HUR',
                    'unit_price' => '50.00',
                    'vat_rate' => '23',
                ],
            ],
        ], $overrides);
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($xml));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xpath;
    }

    private function value(DOMXPath $xpath, string $query): string
    {
        return (string) $xpath->evaluate("string({$query})");
    }

    public function test_credit_note_structure(): void
    {
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'type' => 'credit_note',
            'number' => 'DBP-2026-0001',
            'invoice_reference' => 'FA-2026-0001',
        ]));

        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cn', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $this->assertSame('CreditNote', $document->documentElement->localName);
        $this->assertSame('381', $this->value($xpath, '/cn:CreditNote/cbc:CreditNoteTypeCode'));
        $this->assertSame('DBP-2026-0001', $this->value($xpath, '/cn:CreditNote/cbc:ID'));
        $this->assertSame(
            'FA-2026-0001',
            $this->value($xpath, '/cn:CreditNote/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID')
        );
        $this->assertSame('10', $this->value($xpath, '/cn:CreditNote/cac:CreditNoteLine[1]/cbc:CreditedQuantity'));
        // Amounts stay positive — a credit note credits, the sign lives in the type.
        $this->assertSame('500.00', $this->value($xpath, '/cn:CreditNote/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'));
        // UBL CreditNote has no DueDate element.
        $this->assertSame('', $this->value($xpath, '/cn:CreditNote/cbc:DueDate'));
    }

    public function test_exempt_category_gets_exemption_reason_in_breakdown(): void
    {
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'lines' => [
                [
                    'name' => 'Poisťovacia služba',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'vat_rate' => '0',
                    'vat_category' => 'E',
                    'vat_exemption_reason' => 'Oslobodené podľa § 37 zákona o DPH',
                ],
            ],
        ]));
        $xpath = $this->xpath($xml);

        $this->assertSame(
            'Oslobodené podľa § 37 zákona o DPH',
            $this->value($xpath, '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason')
        );
        $this->assertSame('0.00', $this->value($xpath, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
    }

    public function test_reverse_charge_gets_default_exemption_reason(): void
    {
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'lines' => [
                [
                    'name' => 'Stavebné práce',
                    'quantity' => '1',
                    'unit_price' => '1000.00',
                    'vat_rate' => '0',
                    'vat_category' => 'AE',
                ],
            ],
        ]));
        $xpath = $this->xpath($xml);

        $this->assertSame(
            'Prenesenie daňovej povinnosti (reverse charge)',
            $this->value($xpath, '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason')
        );
    }

    public function test_foreign_currency_adds_tax_currency_and_converted_vat_total(): void
    {
        // 10 × 50.00 CZK @ 23 % → VAT 115.00 CZK; × 0.0397 = 4.5655 → 4.57 EUR
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'currency' => 'CZK',
            'vat_currency' => 'EUR',
            'vat_exchange_rate' => '0.0397',
        ]));
        $xpath = $this->xpath($xml);

        $this->assertSame('CZK', $this->value($xpath, '/inv:Invoice/cbc:DocumentCurrencyCode'));
        $this->assertSame('EUR', $this->value($xpath, '/inv:Invoice/cbc:TaxCurrencyCode'));

        $this->assertSame(2, $xpath->query('/inv:Invoice/cac:TaxTotal')->length);
        $this->assertSame('115.00', $this->value($xpath, '/inv:Invoice/cac:TaxTotal[1]/cbc:TaxAmount'));
        $this->assertSame('4.57', $this->value($xpath, '/inv:Invoice/cac:TaxTotal[2]/cbc:TaxAmount'));
        $this->assertSame(
            'EUR',
            (string) $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal[2]/cbc:TaxAmount/@currencyID)')
        );
        $this->assertSame(
            0,
            $xpath->query('/inv:Invoice/cac:TaxTotal[2]/cac:TaxSubtotal')->length,
            'the second TaxTotal carries only the converted amount (BT-111)'
        );
    }

    public function test_same_vat_currency_emits_no_second_tax_total(): void
    {
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'vat_currency' => 'EUR',
        ]));
        $xpath = $this->xpath($xml);

        $this->assertSame('', $this->value($xpath, '/inv:Invoice/cbc:TaxCurrencyCode'));
        $this->assertSame(1, $xpath->query('/inv:Invoice/cac:TaxTotal')->length);
    }

    public function test_vat_currency_without_exchange_rate_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('vat_exchange_rate');
        (new UblInvoiceBuilder())->build($this->baseInvoice([
            'currency' => 'CZK',
            'vat_currency' => 'EUR',
        ]));
    }

    public function test_prepaid_amount_reduces_payable(): void
    {
        // Total 615.00; advance 500.00 → payable 115.00
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'prepaid_amount' => '500.00',
        ]));
        $xpath = $this->xpath($xml);

        $this->assertSame('615.00', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'));
        $this->assertSame('500.00', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount'));
        $this->assertSame('115.00', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
    }

    public function test_prepaid_exceeding_total_throws_slovak_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('je vyššia ako celková suma faktúry');
        (new UblInvoiceBuilder())->build($this->baseInvoice([
            'prepaid_amount' => '1000.00',
        ]));
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UblInvoiceBuilder())->build($this->baseInvoice(['type' => 'proforma']));
    }

    public function test_single_line_totals(): void
    {
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice());
        $xpath = $this->xpath($xml);

        $this->assertSame('500.00', $this->value($xpath, '/inv:Invoice/cac:InvoiceLine[1]/cbc:LineExtensionAmount'));
        $this->assertSame('115.00', $this->value($xpath, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
        $this->assertSame('500.00', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'));
        $this->assertSame('500.00', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
        $this->assertSame('615.00', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'));
        $this->assertSame('615.00', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
    }

    public function test_multiple_vat_rates_produce_separate_subtotals(): void
    {
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'lines' => [
                ['name' => 'Služba A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '23'],
                ['name' => 'Tovar B', 'quantity' => '1', 'unit_price' => '200.00', 'vat_rate' => '19'],
            ],
        ]));
        $xpath = $this->xpath($xml);

        $this->assertSame(
            2,
            $xpath->query('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal')->length
        );

        $subtotal23 = '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cbc:Percent="23"]';
        $this->assertSame('100.00', $this->value($xpath, "{$subtotal23}/cbc:TaxableAmount"));
        $this->assertSame('23.00', $this->value($xpath, "{$subtotal23}/cbc:TaxAmount"));

        $subtotal19 = '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cbc:Percent="19"]';
        $this->assertSame('200.00', $this->value($xpath, "{$subtotal19}/cbc:TaxableAmount"));
        $this->assertSame('38.00', $this->value($xpath, "{$subtotal19}/cbc:TaxAmount"));

        $this->assertSame('61.00', $this->value($xpath, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
        $this->assertSame('361.00', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
    }

    public function test_same_rate_lines_are_grouped_into_one_subtotal(): void
    {
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'lines' => [
                ['name' => 'A', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '23'],
                ['name' => 'B', 'quantity' => '2', 'unit_price' => '5.00', 'vat_rate' => '23'],
            ],
        ]));
        $xpath = $this->xpath($xml);

        $this->assertSame(1, $xpath->query('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal')->length);
        $this->assertSame('20.00', $this->value($xpath, '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount'));
        $this->assertSame('4.60', $this->value($xpath, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
    }

    public function test_line_extension_rounds_half_up(): void
    {
        // 3 × 0.335 = 1.005 → 1.01; VAT 23 % of 1.01 = 0.2323 → 0.23
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice([
            'lines' => [
                ['name' => 'Drobný tovar', 'quantity' => '3', 'unit_price' => '0.335', 'vat_rate' => '23'],
            ],
        ]));
        $xpath = $this->xpath($xml);

        $this->assertSame('1.01', $this->value($xpath, '/inv:Invoice/cac:InvoiceLine[1]/cbc:LineExtensionAmount'));
        $this->assertSame('0.23', $this->value($xpath, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
        $this->assertSame('1.24', $this->value($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
    }

    public function test_header_fields_and_parties(): void
    {
        $xml = (new UblInvoiceBuilder())->build($this->baseInvoice());
        $xpath = $this->xpath($xml);

        $this->assertSame('FA-2026-0001', $this->value($xpath, '/inv:Invoice/cbc:ID'));
        $this->assertSame('2026-07-15', $this->value($xpath, '/inv:Invoice/cbc:IssueDate'));
        $this->assertSame('380', $this->value($xpath, '/inv:Invoice/cbc:InvoiceTypeCode'));
        $this->assertSame('EUR', $this->value($xpath, '/inv:Invoice/cbc:DocumentCurrencyCode'));

        $supplier = '/inv:Invoice/cac:AccountingSupplierParty/cac:Party';
        $this->assertSame('Dodávateľ s.r.o.', $this->value($xpath, "{$supplier}/cac:PartyName/cbc:Name"));
        $this->assertSame('SK', $this->value($xpath, "{$supplier}/cac:PostalAddress/cac:Country/cbc:IdentificationCode"));
        $this->assertSame('SK2020123457', $this->value($xpath, "{$supplier}/cac:PartyTaxScheme/cbc:CompanyID"));
        $this->assertSame('12345678', $this->value($xpath, "{$supplier}/cac:PartyLegalEntity/cbc:CompanyID"));

        $customer = '/inv:Invoice/cac:AccountingCustomerParty/cac:Party';
        $this->assertSame('Odberateľ a.s.', $this->value($xpath, "{$customer}/cac:PartyName/cbc:Name"));
    }

    public function test_missing_required_field_throws(): void
    {
        $invoice = $this->baseInvoice();
        unset($invoice['number']);

        $this->expectException(InvalidArgumentException::class);
        (new UblInvoiceBuilder())->build($invoice);
    }

    public function test_invalid_issue_date_format_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UblInvoiceBuilder())->build($this->baseInvoice(['issue_date' => '15.07.2026']));
    }

    public function test_line_missing_vat_rate_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UblInvoiceBuilder())->build($this->baseInvoice([
            'lines' => [['name' => 'X', 'quantity' => '1', 'unit_price' => '1.00']],
        ]));
    }
}
