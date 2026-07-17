<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\ArchiveObject;
use App\Models\Invoice;
use App\Models\UsageMeter;
use App\Services\Archive\InvoiceArchiver;
use App\Services\Pipeline\InvoicePipeline;
use App\Services\Postar\PostarAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePostarAdapter;
use Tests\TestCase;

class InvoicePipelineTest extends TestCase
{
    use RefreshDatabase;

    private FakePostarAdapter $postar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postar = new FakePostarAdapter();
        $this->app->instance(PostarAdapterInterface::class, $this->postar);
        Storage::fake(InvoiceArchiver::DISK);
    }

    private function pipeline(): InvoicePipeline
    {
        return $this->app->make(InvoicePipeline::class);
    }

    private function definition(): array
    {
        return json_decode(
            file_get_contents(resource_path('samples/mapping-legacy-csv.json')),
            true
        );
    }

    private function sampleCsv(): string
    {
        return file_get_contents(resource_path('samples/legacy-export.csv'));
    }

    public function test_ingest_creates_one_invoice_per_group(): void
    {
        $result = $this->pipeline()->ingest($this->sampleCsv(), $this->definition());

        $this->assertCount(2, $result['created']);
        $this->assertSame([], $result['duplicates']);
        $this->assertSame(
            ['FA-2026-0101', 'FA-2026-0102'],
            Invoice::orderBy('id')->pluck('external_id')->all()
        );

        $first = $result['created'][0];
        $this->assertSame(InvoiceStatus::Received, $first->status);
        $this->assertCount(2, $first->source_payload, 'both source rows persisted');
        $this->assertNotNull($first->mapping_definition);
    }

    public function test_reingest_is_idempotent(): void
    {
        $this->pipeline()->ingest($this->sampleCsv(), $this->definition());
        $result = $this->pipeline()->ingest($this->sampleCsv(), $this->definition());

        $this->assertSame([], $result['created']);
        $this->assertCount(2, $result['duplicates']);
        $this->assertSame(2, Invoice::count());
    }

    public function test_happy_path_reaches_delivered_with_full_audit_trail(): void
    {
        $pipeline = $this->pipeline();
        $pipeline->ingest($this->sampleCsv(), $this->definition());

        foreach (Invoice::all() as $invoice) {
            $pipeline->process($invoice);
            $this->assertSame(InvoiceStatus::Sent, $invoice->status);
            $pipeline->refreshStatus($invoice);
            $this->assertSame(InvoiceStatus::Delivered, $invoice->status);
        }

        $this->assertCount(2, $this->postar->sent);

        $trail = Invoice::first()->events()->orderBy('id')->pluck('to_status')->all();
        $this->assertSame(
            // The double 'sent' is the archive note (sent → sent, no transition).
            ['received', 'mapped', 'validated', 'queued', 'sent', 'sent', 'delivered'],
            $trail
        );

        $first = Invoice::first();
        $this->assertSame('FA-2026-0101', $first->number);
        $this->assertSame('0245:0000000002', $first->receiver_peppol_id);
        $this->assertStringContainsString('<cbc:ID>FA-2026-0101</cbc:ID>', $first->ubl_xml);
        $this->assertSame(['business' => [], 'xsd' => []], $first->validation_report);

        // Sending metered the documents and filed the archive copies.
        $this->assertSame(
            2,
            (int) UsageMeter::where('metric', UsageMeter::DOCUMENTS_SENT)->value('count')
        );
        $this->assertSame(4, ArchiveObject::count(), '2 invoices × (UBL + source)');
        foreach (ArchiveObject::all() as $object) {
            Storage::disk(InvoiceArchiver::DISK)->assertExists($object->path);
        }
    }

    public function test_broken_invoice_fails_without_blocking_the_rest(): void
    {
        $csv = "cislo;vystavena;splatnost;odberatel;ico_odb;icdph_odb;ulica;mesto;psc;polozka;mj;mnozstvo;cena;dph\n"
            ."FA-X-1;99.07.2026;15.07.2026;Alfa;1;SK1;U;M;811;Tovar;ks;1;10,00;23\n"
            ."FA-X-2;01.07.2026;15.07.2026;Beta;22222222;SK2020222226;U;M;811;Tovar;ks;2;20,00;23\n";

        $pipeline = $this->pipeline();
        $pipeline->ingest($csv, $this->definition());

        foreach (Invoice::orderBy('id')->get() as $invoice) {
            $pipeline->process($invoice);
        }

        $broken = Invoice::where('external_id', 'FA-X-1')->first();
        $this->assertSame(InvoiceStatus::Failed, $broken->status);
        $this->assertStringContainsString('Chyba mapovania', $broken->error_message);
        $this->assertStringContainsString("nezodpovedá formátu dátumu 'd.m.Y'", $broken->error_message);

        $healthy = Invoice::where('external_id', 'FA-X-2')->first();
        $this->assertSame(InvoiceStatus::Sent, $healthy->status);
        $this->assertCount(1, $this->postar->sent);
    }

    public function test_credit_note_flows_through_pipeline_as_ubl_credit_note(): void
    {
        $pipeline = $this->pipeline();
        $pipeline->ingest(
            file_get_contents(resource_path('samples/legacy-export-dobropis.csv')),
            $this->definition()
        );

        $invoice = Invoice::first();
        $pipeline->process($invoice);

        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('credit_note', $invoice->canonical['type']);
        $this->assertStringContainsString('<CreditNote', $invoice->ubl_xml);
        $this->assertStringContainsString('<cbc:CreditNoteTypeCode>381</cbc:CreditNoteTypeCode>', $invoice->ubl_xml);
        $this->assertStringContainsString('FA-2026-0101', $invoice->ubl_xml, 'referencia na pôvodnú faktúru');
    }

    public function test_business_validation_failure_collects_all_errors(): void
    {
        // Maps cleanly, but: VAT id typo (not divisible by 11) + 20 % is not
        // a Slovak VAT rate. Both must surface in one pass.
        $csv = "cislo;vystavena;splatnost;odberatel;ico_odb;icdph_odb;ulica;mesto;psc;polozka;mj;mnozstvo;cena;dph\n"
            ."FA-B-1;01.07.2026;15.07.2026;Alfa;11111111;SK2020111116;U;M;811;Tovar;ks;1;10,00;20\n";

        $pipeline = $this->pipeline();
        $pipeline->ingest($csv, $this->definition());

        $invoice = Invoice::first();
        $pipeline->process($invoice);

        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        $this->assertStringContainsString('Biznis validácia', $invoice->error_message);

        $errors = $invoice->validation_report['business'];
        $this->assertCount(2, $errors);
        $this->assertStringContainsString("IČ DPH odberateľa 'SK2020111116'", $errors[0]);
        $this->assertStringContainsString('20 % neplatí na Slovensku', $errors[1]);

        // Fixing the export and retrying gets the invoice through.
        $this->assertNull($invoice->ubl_xml);
    }

    public function test_postar_outage_marks_invoice_failed_and_retry_recovers(): void
    {
        $pipeline = $this->pipeline();
        $pipeline->ingest($this->sampleCsv(), $this->definition());

        $this->postar->failSend = true;
        $invoice = Invoice::first();
        $pipeline->process($invoice);

        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        $this->assertStringContainsString('Odoslanie zlyhalo', $invoice->error_message);

        $this->postar->failSend = false;
        $invoice->retry();
        $pipeline->process($invoice);

        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
    }

    public function test_postar_validation_failure_rejects_with_errors_in_report(): void
    {
        $pipeline = $this->pipeline();
        $pipeline->ingest($this->sampleCsv(), $this->definition());

        $invoice = Invoice::first();
        $pipeline->process($invoice);

        $this->postar->statusToReturn = 'validation_failed';
        $this->postar->validationErrors = ['Buyer electronic address MUST be provided [/Invoice/...]'];
        $pipeline->refreshStatus($invoice);

        $this->assertSame(InvoiceStatus::Rejected, $invoice->status);
        $this->assertSame(
            ['Buyer electronic address MUST be provided [/Invoice/...]'],
            $invoice->validation_report['postar']
        );
        $this->assertStringContainsString('Peppol validácii', $invoice->error_message);
    }

    public function test_still_in_transit_keeps_sent_status(): void
    {
        $pipeline = $this->pipeline();
        $pipeline->ingest($this->sampleCsv(), $this->definition());

        $invoice = Invoice::first();
        $pipeline->process($invoice);

        $this->postar->statusToReturn = 'sending';
        $pipeline->refreshStatus($invoice);

        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
    }
}
