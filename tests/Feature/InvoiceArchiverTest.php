<?php

namespace Tests\Feature;

use App\Models\ArchiveObject;
use App\Models\Invoice;
use App\Services\Archive\InvoiceArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceArchiverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(InvoiceArchiver::DISK);
    }

    private function sentInvoice(): Invoice
    {
        return Invoice::receive([
            'external_id' => 'FA-2026-0500',
            'source_payload' => [['values' => ['cislo' => 'FA-2026-0500', 'polozka' => 'Tovar']]],
            'mapping_definition' => [],
            'ubl_xml' => '<Invoice><ID>FA-2026-0500</ID></Invoice>',
        ]);
    }

    public function test_archives_ubl_and_source_with_integrity_hash(): void
    {
        $invoice = $this->sentInvoice();

        $created = (new InvoiceArchiver())->archive($invoice);

        $this->assertCount(2, $created);

        $ubl = $invoice->archiveObjects()->where('type', ArchiveObject::TYPE_UBL)->first();
        $this->assertNotNull($ubl);
        Storage::disk(InvoiceArchiver::DISK)->assertExists($ubl->path);
        $this->assertStringContainsString('outbound/', $ubl->path);
        $this->assertStringContainsString('fa-2026-0500', $ubl->path);
        $this->assertStringEndsWith('faktura.xml', $ubl->path);

        $storedXml = Storage::disk(InvoiceArchiver::DISK)->get($ubl->path);
        $this->assertSame($invoice->ubl_xml, $storedXml);
        $this->assertSame(hash('sha256', $storedXml), $ubl->sha256);
        $this->assertSame(strlen($storedXml), (int) $ubl->size_bytes);

        $source = $invoice->archiveObjects()->where('type', ArchiveObject::TYPE_SOURCE)->first();
        $this->assertNotNull($source);
        $this->assertStringEndsWith('zdroj.json', $source->path);
        $this->assertSame(
            $invoice->source_payload,
            json_decode(Storage::disk(InvoiceArchiver::DISK)->get($source->path), true)
        );

        // The archiving itself is part of the invoice audit trail.
        $this->assertTrue(
            $invoice->events()->where('message', 'like', 'Faktúra archivovaná%')->exists()
        );
    }

    public function test_archiving_is_idempotent(): void
    {
        $invoice = $this->sentInvoice();
        $archiver = new InvoiceArchiver();

        $first = $archiver->archive($invoice);
        $second = $archiver->archive($invoice);

        $this->assertCount(2, $first);
        $this->assertSame([], $second);
        $this->assertSame(2, $invoice->archiveObjects()->count());
    }

    public function test_invoice_without_ubl_archives_only_source(): void
    {
        $invoice = Invoice::receive([
            'external_id' => 'FA-NO-UBL',
            'source_payload' => [['values' => []]],
            'mapping_definition' => [],
        ]);

        $created = (new InvoiceArchiver())->archive($invoice);

        $this->assertCount(1, $created);
        $this->assertSame(ArchiveObject::TYPE_SOURCE, $created[0]->type);
    }
}
