<?php

namespace App\Console\Commands;

use App\Services\Postar\PostarAdapterInterface;
use App\Services\Postar\PostarException;
use App\Services\Ubl\UblInvoiceBuilder;
use App\Services\Ubl\XsdValidator;
use Illuminate\Console\Command;

/**
 * Sends a UBL invoice to the poštár sandbox. Given no --file, it builds the
 * same sample invoice as ubl:hello, so this doubles as an end-to-end smoke
 * test of the outbound path against dev.epostak.sk.
 */
class PostarSend extends Command
{
    protected $signature = 'postar:send
        {--file= : UBL XML súbor na odoslanie (predvolene sa vygeneruje ukážková faktúra)}
        {--receiver=0245:0000000002 : Peppol ID príjemcu (predvolene demo Participant 2)}
        {--status : Po odoslaní zisti stav dokumentu}';

    protected $description = 'Send a UBL 2.1 invoice to the ePošťák sandbox and print the result';

    public function handle(PostarAdapterInterface $postar, UblInvoiceBuilder $builder): int
    {
        $file = $this->option('file');
        if ($file !== null) {
            if (!is_file($file)) {
                $this->error("Súbor neexistuje: {$file}");

                return self::FAILURE;
            }
            $xml = file_get_contents($file);
        } else {
            $xml = $builder->build($this->sampleInvoice());
        }

        $validator = new XsdValidator(resource_path('schemas/ubl-2.1/maindoc/UBL-Invoice-2.1.xsd'));
        $xsdErrors = $validator->validate($xml);
        if ($xsdErrors !== []) {
            $this->error('XSD validácia zlyhala — neodosielam:');
            foreach ($xsdErrors as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        $receiver = $this->option('receiver');
        $idempotencyKey = 'faktura-'.hash('sha256', $xml.$receiver);

        try {
            $this->info("Odosielam príjemcovi {$receiver} …");
            $result = $postar->sendInvoice($xml, $receiver, $idempotencyKey);
        } catch (PostarException $exception) {
            $this->error('Odoslanie zlyhalo: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Odoslané.');
        $this->table(['Pole', 'Hodnota'], [
            ['documentId', $result->documentId],
            ['messageId', $result->messageId ?? '—'],
            ['status', $result->status],
            ['payloadSha256', $result->payloadSha256 ?? '—'],
        ]);

        if ($this->option('status')) {
            try {
                $status = $postar->getStatus($result->documentId);
                $this->info("Stav dokumentu: {$status->status}"
                    .($status->deliveredAt !== null ? " (doručené {$status->deliveredAt})" : ''));
                if ($status->validationErrors !== []) {
                    $this->error('Validačné chyby poštára:');
                    foreach ($status->validationErrors as $error) {
                        $this->line('  - '.$error);
                    }

                    return self::FAILURE;
                }
            } catch (PostarException $exception) {
                $this->warn('Stav sa nepodarilo zistiť: '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function sampleInvoice(): array
    {
        return [
            // Unique per run — the sandbox deduplicates by invoice number.
            'number' => 'FA-DEMO-'.now()->format('YmdHis'),
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
                ['name' => 'Konzultačné služby', 'quantity' => '10', 'unit' => 'HUR', 'unit_price' => '50.00', 'vat_rate' => '23'],
                ['name' => 'Odborná literatúra', 'quantity' => '3', 'unit_price' => '19.90', 'vat_rate' => '19'],
            ],
        ];
    }
}
