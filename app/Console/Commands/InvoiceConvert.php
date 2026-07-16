<?php

namespace App\Console\Commands;

use App\Services\Mapping\InvoiceMapper;
use App\Services\Mapping\MappingException;
use App\Services\Mapping\Readers\CsvReader;
use App\Services\Mapping\Readers\XmlReader;
use App\Services\Ubl\UblInvoiceBuilder;
use App\Services\Ubl\XsdValidator;
use Illuminate\Console\Command;

class InvoiceConvert extends Command
{
    protected $signature = 'invoice:convert
        {input : Vstupný súbor (CSV alebo XML export)}
        {mapping : Mapovacia definícia (JSON)}
        {--out-dir= : Výstupný adresár (predvolene storage/app/converted)}';

    protected $description = 'Convert a legacy invoice export to validated UBL 2.1 XML using a mapping definition';

    public function handle(InvoiceMapper $mapper, UblInvoiceBuilder $builder): int
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

        $outDir = $this->option('out-dir') ?: storage_path('app/converted');
        if (!is_dir($outDir)) {
            mkdir($outDir, recursive: true);
        }

        $validator = new XsdValidator(
            resource_path('schemas/ubl-2.1/maindoc/UBL-Invoice-2.1.xsd')
        );

        try {
            $records = $this->readSource($inputPath, $definition);
            $invoices = $mapper->map($definition, $records);
        } catch (MappingException $exception) {
            $this->error('Chyba mapovania: '.$exception->getMessage());

            return self::FAILURE;
        }

        $failures = 0;
        foreach ($invoices as $invoice) {
            $xml = $builder->build($invoice);
            $errors = $validator->validate($xml);

            $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice['number']).'.xml';
            $outputPath = $outDir.DIRECTORY_SEPARATOR.$fileName;
            file_put_contents($outputPath, $xml);

            if ($errors === []) {
                $this->info("✓ {$invoice['number']} → {$outputPath} (XSD validácia prešla)");
            } else {
                $failures++;
                $this->error("✗ {$invoice['number']} → {$outputPath} (XSD validácia zlyhala)");
                foreach ($errors as $error) {
                    $this->line('    - '.$error);
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('Spracovaných faktúr: %d, chybných: %d', count($invoices), $failures));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function readSource(string $inputPath, array $definition): array
    {
        $source = $definition['source'] ?? [];
        $type = strtolower($source['type'] ?? 'csv');
        $content = file_get_contents($inputPath);

        return match ($type) {
            'csv' => (new CsvReader())->read($content, $source),
            'xml' => (new XmlReader())->read($content, $source),
            default => throw new MappingException(
                "Nepodporovaný typ vstupu '{$type}' — podporované sú 'csv' a 'xml'."
            ),
        };
    }
}
