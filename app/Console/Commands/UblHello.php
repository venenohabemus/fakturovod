<?php

namespace App\Console\Commands;

use App\Services\Ubl\UblInvoiceBuilder;
use App\Services\Ubl\XsdValidator;
use Illuminate\Console\Command;

class UblHello extends Command
{
    protected $signature = 'ubl:hello {--out=ubl-hello.xml : Output filename (relative to storage/app)}';

    protected $description = 'Generate a sample UBL 2.1 invoice and validate it against the UBL XSD schema';

    public function handle(UblInvoiceBuilder $builder): int
    {
        $invoice = [
            'number' => 'FA-2026-0001',
            'issue_date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(14)->format('Y-m-d'),
            'currency' => 'EUR',
            'buyer_reference' => 'OBJ-2026-042',
            'supplier' => [
                'peppol_id' => '0245:0000000001',
                'name' => 'Ukážkový dodávateľ s.r.o.',
                'street' => 'Hlavná 1',
                'city' => 'Bratislava',
                'zip' => '811 01',
                'country' => 'SK',
                'company_id' => '12345678',
                'vat_id' => 'SK2020123456',
            ],
            'customer' => [
                'peppol_id' => '0245:0000000002',
                'name' => 'Ukážkový odberateľ a.s.',
                'street' => 'Nákupná 22',
                'city' => 'Košice',
                'zip' => '040 01',
                'country' => 'SK',
                'company_id' => '87654321',
                'vat_id' => 'SK2020654321',
            ],
            'lines' => [
                [
                    'name' => 'Konzultačné služby',
                    'quantity' => '10',
                    'unit' => 'HUR',
                    'unit_price' => '50.00',
                    'vat_rate' => '23',
                ],
                [
                    'name' => 'Odborná literatúra',
                    'quantity' => '3',
                    'unit' => 'C62',
                    'unit_price' => '19.90',
                    'vat_rate' => '19',
                ],
            ],
        ];

        $xml = $builder->build($invoice);

        $outputPath = storage_path('app/'.$this->option('out'));
        file_put_contents($outputPath, $xml);
        $this->info("Vygenerované: {$outputPath}");

        $validator = new XsdValidator(
            resource_path('schemas/ubl-2.1/maindoc/UBL-Invoice-2.1.xsd')
        );
        $errors = $validator->validate($xml);

        if ($errors !== []) {
            $this->error('XSD validácia zlyhala:');
            foreach ($errors as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        $this->info('XSD validácia prešla — dokument je platný UBL 2.1 Invoice.');

        return self::SUCCESS;
    }
}
