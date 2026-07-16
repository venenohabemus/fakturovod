<?php

namespace App\Console\Commands;

use App\Services\Mapping\MappingException;
use App\Services\Pipeline\InvoicePipeline;
use Illuminate\Console\Command;

class InvoiceIngest extends Command
{
    protected $signature = 'invoice:ingest
        {input : Vstupný súbor (CSV alebo XML export)}
        {mapping : Mapovacia definícia (JSON)}
        {--process : Hneď po prijatí spustiť spracovanie (mapovanie, validácia, odoslanie)}';

    protected $description = 'Ingest a legacy export into the invoice pipeline (idempotent by external id)';

    public function handle(InvoicePipeline $pipeline): int
    {
        $inputPath = $this->argument('input');
        $mappingPath = $this->argument('mapping');

        foreach ([$inputPath, $mappingPath] as $path) {
            if (!is_file($path)) {
                $this->error("Súbor neexistuje: {$path}");

                return self::FAILURE;
            }
        }

        $definition = json_decode(file_get_contents($mappingPath), true);
        if (!is_array($definition)) {
            $this->error('Mapovacia definícia nie je platný JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        try {
            $result = $pipeline->ingest(file_get_contents($inputPath), $definition);
        } catch (MappingException $exception) {
            $this->error('Chyba pri načítaní: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['created'] as $invoice) {
            $this->info("✓ prijatá #{$invoice->id} ({$invoice->external_id})");
        }
        foreach ($result['duplicates'] as $externalId) {
            $this->line("– preskočená duplicita ({$externalId})");
        }
        $this->newLine();
        $this->info(sprintf(
            'Prijatých: %d, duplicít: %d',
            count($result['created']),
            count($result['duplicates'])
        ));

        if ($this->option('process') && $result['created'] !== []) {
            $this->newLine();

            return $this->call('invoice:process');
        }

        return self::SUCCESS;
    }
}
