<?php

namespace Tests\Feature;

use App\Services\Mapping\InvoiceMapper;
use App\Services\Mapping\Readers\CsvReader;
use App\Services\Ubl\UblInvoiceBuilder;
use App\Services\Ubl\XsdValidator;
use Tests\TestCase;

/**
 * End-to-end test of the outbound core: legacy CSV export → mapping →
 * canonical model → UBL 2.1 XML → XSD validation. Uses the sample files
 * shipped in resources/samples.
 */
class InvoiceConvertTest extends TestCase
{
    public function test_sample_csv_export_converts_to_valid_ubl(): void
    {
        $definition = json_decode(
            file_get_contents(resource_path('samples/mapping-legacy-csv.json')),
            true
        );
        $content = file_get_contents(resource_path('samples/legacy-export.csv'));

        $records = (new CsvReader())->read($content, $definition['source']);
        $invoices = (new InvoiceMapper())->map($definition, $records);

        $this->assertCount(2, $invoices);
        $this->assertSame(['FA-2026-0101', 'FA-2026-0102'], array_column($invoices, 'number'));

        // First invoice: 12 × 45.00 + 1 × 120.50 = 660.50; VAT 23 % = 151.92 (HALF_UP)
        $builder = new UblInvoiceBuilder();
        $validator = new XsdValidator(resource_path('schemas/ubl-2.1/maindoc/UBL-Invoice-2.1.xsd'));

        $xml = $builder->build($invoices[0]);
        $this->assertSame([], $validator->validate($xml));
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="EUR">812.42</cbc:PayableAmount>', $xml);
        $this->assertStringContainsString('unitCode="HUR"', $xml, "'hod' is mapped to UN/ECE HUR");

        $xml = $builder->build($invoices[1]);
        $this->assertSame([], $validator->validate($xml));
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="EUR">1230.00</cbc:PayableAmount>', $xml);
    }

    public function test_convert_command_processes_sample_files(): void
    {
        $outDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'faktura-convert-test-'.uniqid();

        $this->artisan('invoice:convert', [
            'input' => resource_path('samples/legacy-export.csv'),
            'mapping' => resource_path('samples/mapping-legacy-csv.json'),
            '--out-dir' => $outDir,
        ])->assertExitCode(0);

        $this->assertFileExists($outDir.DIRECTORY_SEPARATOR.'FA-2026-0101.xml');
        $this->assertFileExists($outDir.DIRECTORY_SEPARATOR.'FA-2026-0102.xml');

        collect(glob($outDir.DIRECTORY_SEPARATOR.'*.xml'))->each(fn ($file) => unlink($file));
        rmdir($outDir);
    }

    public function test_convert_command_fails_with_readable_error_for_broken_input(): void
    {
        $brokenCsv = tempnam(sys_get_temp_dir(), 'faktura-broken').'.csv';
        file_put_contents($brokenCsv, "cislo;vystavena\nFA-1;32.13.2026\n");

        $this->artisan('invoice:convert', [
            'input' => $brokenCsv,
            'mapping' => resource_path('samples/mapping-legacy-csv.json'),
            '--out-dir' => sys_get_temp_dir(),
        ])->assertExitCode(1);

        unlink($brokenCsv);
    }
}
