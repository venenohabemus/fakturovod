<?php

namespace Tests\Feature;

use App\Services\Ubl\UblInvoiceBuilder;
use App\Services\Ubl\XsdValidator;
use Tests\TestCase;

class UblXsdValidationTest extends TestCase
{
    private function validator(): XsdValidator
    {
        return new XsdValidator(
            resource_path('schemas/ubl-2.1/maindoc/UBL-Invoice-2.1.xsd')
        );
    }

    public function test_generated_invoice_passes_xsd_validation(): void
    {
        $xml = (new UblInvoiceBuilder())->build([
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
                'vat_id' => 'SK2020123456',
            ],
            'customer' => [
                'name' => 'Odberateľ a.s.',
                'street' => 'Nákupná 22',
                'city' => 'Košice',
                'zip' => '040 01',
                'country' => 'SK',
                'vat_id' => 'SK2020654321',
            ],
            'lines' => [
                ['name' => 'Konzultačné služby', 'quantity' => '10', 'unit' => 'HUR', 'unit_price' => '50.00', 'vat_rate' => '23'],
                ['name' => 'Odborná literatúra', 'quantity' => '3', 'unit_price' => '19.90', 'vat_rate' => '19'],
            ],
        ]);

        $this->assertSame([], $this->validator()->validate($xml));
    }

    public function test_incomplete_invoice_document_fails_xsd_validation(): void
    {
        // An Invoice without its required children (ID, IssueDate, parties, ...)
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>';

        $this->assertNotSame([], $this->validator()->validate($xml));
    }

    public function test_malformed_xml_fails_validation(): void
    {
        $this->assertNotSame([], $this->validator()->validate('<Invoice><unclosed>'));
    }
}
