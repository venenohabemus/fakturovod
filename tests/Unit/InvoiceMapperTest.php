<?php

namespace Tests\Unit;

use App\Services\Mapping\ArrayRecord;
use App\Services\Mapping\InvoiceMapper;
use App\Services\Mapping\MappingException;
use App\Services\Mapping\Readers\XmlReader;
use PHPUnit\Framework\TestCase;

class InvoiceMapperTest extends TestCase
{
    private function definition(): array
    {
        return [
            'version' => 1,
            'source' => ['type' => 'csv', 'group_by' => 'cislo'],
            'invoice' => [
                'number' => 'cislo',
                'issue_date' => ['from' => 'datum', 'transform' => [['type' => 'date', 'from_format' => 'd.m.Y']]],
                'currency' => ['const' => 'EUR'],
                'supplier' => [
                    'name' => ['const' => 'Dodávateľ s.r.o.'],
                    'country' => ['const' => 'SK'],
                ],
                'customer' => [
                    'name' => 'odberatel',
                    'country' => ['const' => 'SK'],
                ],
                'lines' => [
                    'fields' => [
                        'name' => 'polozka',
                        'quantity' => ['from' => 'mnozstvo', 'transform' => [['type' => 'decimal']]],
                        'unit_price' => ['from' => 'cena', 'transform' => [['type' => 'decimal']]],
                        'vat_rate' => 'dph',
                        'unit' => ['from' => 'mj', 'map' => ['ks' => 'C62', 'hod' => 'HUR'], 'map_default' => 'C62'],
                    ],
                ],
            ],
        ];
    }

    private function row(array $values, string $label = 'riadok 2'): ArrayRecord
    {
        return new ArrayRecord($values, $label);
    }

    public function test_groups_rows_into_invoices_by_group_by_field(): void
    {
        $rows = [
            $this->row(['cislo' => 'FA-1', 'datum' => '1.7.2026', 'odberatel' => 'Alfa', 'polozka' => 'A', 'mnozstvo' => '2', 'cena' => '10,00', 'dph' => '23', 'mj' => 'ks'], 'riadok 2'),
            $this->row(['cislo' => 'FA-1', 'datum' => '1.7.2026', 'odberatel' => 'Alfa', 'polozka' => 'B', 'mnozstvo' => '1', 'cena' => '5,50', 'dph' => '19', 'mj' => 'hod'], 'riadok 3'),
            $this->row(['cislo' => 'FA-2', 'datum' => '2.7.2026', 'odberatel' => 'Beta', 'polozka' => 'C', 'mnozstvo' => '3', 'cena' => '7,00', 'dph' => '23', 'mj' => 'bal'], 'riadok 4'),
        ];

        $invoices = (new InvoiceMapper())->map($this->definition(), $rows);

        $this->assertCount(2, $invoices);

        $first = $invoices[0];
        $this->assertSame('FA-1', $first['number']);
        $this->assertSame('2026-07-01', $first['issue_date']);
        $this->assertSame('EUR', $first['currency']);
        $this->assertSame('Alfa', $first['customer']['name']);
        $this->assertCount(2, $first['lines']);
        $this->assertSame(
            ['name' => 'A', 'quantity' => '2', 'unit_price' => '10.00', 'vat_rate' => '23', 'unit' => 'C62'],
            $first['lines'][0]
        );
        $this->assertSame('HUR', $first['lines'][1]['unit']);

        $second = $invoices[1];
        $this->assertSame('FA-2', $second['number']);
        $this->assertSame('Beta', $second['customer']['name']);
        $this->assertCount(1, $second['lines']);
        $this->assertSame('C62', $second['lines'][0]['unit'], 'map_default applies to unmapped unit');
    }

    public function test_without_group_by_all_rows_form_one_invoice(): void
    {
        $definition = $this->definition();
        unset($definition['source']['group_by']);

        $rows = [
            $this->row(['cislo' => 'FA-1', 'datum' => '1.7.2026', 'odberatel' => 'Alfa', 'polozka' => 'A', 'mnozstvo' => '1', 'cena' => '10,00', 'dph' => '23']),
            $this->row(['cislo' => 'FA-1', 'datum' => '1.7.2026', 'odberatel' => 'Alfa', 'polozka' => 'B', 'mnozstvo' => '1', 'cena' => '20,00', 'dph' => '23']),
        ];

        $invoices = (new InvoiceMapper())->map($definition, $rows);

        $this->assertCount(1, $invoices);
        $this->assertCount(2, $invoices[0]['lines']);
    }

    public function test_missing_required_line_value_reports_row_in_slovak(): void
    {
        $rows = [
            $this->row(['cislo' => 'FA-1', 'datum' => '1.7.2026', 'odberatel' => 'Alfa', 'polozka' => 'A', 'mnozstvo' => '', 'cena' => '10,00', 'dph' => '23'], 'riadok 5'),
        ];

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("Chýba povinná hodnota poľa 'quantity' na položke faktúry (riadok 5).");
        (new InvoiceMapper())->map($this->definition(), $rows);
    }

    public function test_all_errors_of_an_invoice_are_collected_at_once(): void
    {
        // Error queue must show every problem in one pass, not fail-fast.
        $rows = [
            $this->row([
                'cislo' => 'FA-1', 'datum' => '99.7.2026', 'odberatel' => '',
                'polozka' => 'A', 'mnozstvo' => '', 'cena' => '45 EUR', 'dph' => '23',
            ], 'riadok 2'),
        ];

        try {
            (new InvoiceMapper())->map($this->definition(), $rows);
            $this->fail('Očakávala sa MappingException.');
        } catch (MappingException $exception) {
            $this->assertCount(4, $exception->errors);
            $this->assertStringContainsString('Faktúra obsahuje 4 chyby.', $exception->getMessage());

            $joined = implode("\n", $exception->errors);
            $this->assertStringContainsString("nezodpovedá formátu dátumu 'd.m.Y'", $joined);
            $this->assertStringContainsString("poľa 'customer.name'", $joined);
            $this->assertStringContainsString("poľa 'quantity'", $joined);
            $this->assertStringContainsString("'45 EUR' poľa 'lines.unit_price' nie je platné číslo", $joined);
        }
    }

    public function test_single_error_message_is_not_prefixed_with_a_count(): void
    {
        $rows = [
            $this->row(['cislo' => 'FA-1', 'datum' => '1.7.2026', 'odberatel' => 'Alfa',
                'polozka' => 'A', 'mnozstvo' => '', 'cena' => '10,00', 'dph' => '23'], 'riadok 5'),
        ];

        try {
            (new InvoiceMapper())->map($this->definition(), $rows);
            $this->fail('Očakávala sa MappingException.');
        } catch (MappingException $exception) {
            $this->assertCount(1, $exception->errors);
            $this->assertSame(
                "Chýba povinná hodnota poľa 'quantity' na položke faktúry (riadok 5).",
                $exception->getMessage()
            );
        }
    }

    public function test_empty_input_throws(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('neobsahuje žiadne záznamy');
        (new InvoiceMapper())->map($this->definition(), []);
    }

    public function test_maps_xml_records(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <faktury>
                <faktura>
                    <cislo>FA-XML-1</cislo>
                    <datum>2.7.2026</datum>
                    <odberatel><nazov>Gama s.r.o.</nazov></odberatel>
                    <polozka><nazov>Servis</nazov><mnozstvo>1</mnozstvo><cena>99,90</cena><dph>23</dph></polozka>
                </faktura>
            </faktury>
            XML;

        $records = (new XmlReader())->read($xml, ['record_xpath' => '/faktury/faktura']);

        $definition = $this->definition();
        unset($definition['source']['group_by']);
        $definition['invoice']['number'] = 'cislo';
        $definition['invoice']['issue_date'] = ['from' => 'datum', 'transform' => [['type' => 'date', 'from_format' => 'd.m.Y']]];
        $definition['invoice']['customer']['name'] = 'odberatel/nazov';
        $definition['invoice']['lines']['fields'] = [
            'name' => 'polozka/nazov',
            'quantity' => ['from' => 'polozka/mnozstvo', 'transform' => [['type' => 'decimal']]],
            'unit_price' => ['from' => 'polozka/cena', 'transform' => [['type' => 'decimal']]],
            'vat_rate' => 'polozka/dph',
        ];

        $invoices = (new InvoiceMapper())->map($definition, $records);

        $this->assertCount(1, $invoices);
        $this->assertSame('FA-XML-1', $invoices[0]['number']);
        $this->assertSame('2026-07-02', $invoices[0]['issue_date']);
        $this->assertSame('Gama s.r.o.', $invoices[0]['customer']['name']);
        $this->assertSame('99.90', $invoices[0]['lines'][0]['unit_price']);
    }
}
