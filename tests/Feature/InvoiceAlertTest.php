<?php

namespace Tests\Feature;

use App\Mail\InvoiceAlertMail;
use App\Models\Invoice;
use App\Services\Archive\InvoiceArchiver;
use App\Services\Pipeline\InvoicePipeline;
use App\Services\Postar\PostarAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePostarAdapter;
use Tests\TestCase;

class InvoiceAlertTest extends TestCase
{
    use RefreshDatabase;

    private FakePostarAdapter $postar;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake(InvoiceArchiver::DISK);
        $this->postar = new FakePostarAdapter();
        $this->app->instance(PostarAdapterInterface::class, $this->postar);
        config(['alerts.recipients' => ['alert@fakturovod.sk']]);
    }

    private function failingIngest(): Invoice
    {
        $csv = "cislo;vystavena;splatnost;odberatel;ico_odb;icdph_odb;ulica;mesto;psc;polozka;mj;mnozstvo;cena;dph\n"
            ."FA-A-1;01.07.2026;15.07.2026;Alfa;11111111;SK2020111116;U;M;811;Tovar;ks;1;10,00;23\n";

        $pipeline = $this->app->make(InvoicePipeline::class);
        $pipeline->ingest($csv, json_decode(
            file_get_contents(resource_path('samples/mapping-legacy-csv.json')),
            true
        ));

        $invoice = Invoice::first();
        $pipeline->process($invoice);

        return $invoice;
    }

    public function test_failed_invoice_sends_alert_with_errors(): void
    {
        $invoice = $this->failingIngest();

        Mail::assertSent(InvoiceAlertMail::class, function (InvoiceAlertMail $mail) use ($invoice) {
            return $mail->hasTo('alert@fakturovod.sk')
                && $mail->invoice->is($invoice)
                && str_contains($mail->envelope()->subject, 'FA-A-1');
        });
    }

    public function test_no_recipients_means_no_mail(): void
    {
        config(['alerts.recipients' => []]);

        $this->failingIngest();

        Mail::assertNothingSent();
    }

    public function test_rejected_invoice_sends_alert(): void
    {
        $csv = file_get_contents(resource_path('samples/legacy-export.csv'));
        $pipeline = $this->app->make(InvoicePipeline::class);
        $pipeline->ingest($csv, json_decode(
            file_get_contents(resource_path('samples/mapping-legacy-csv.json')),
            true
        ));

        $invoice = Invoice::first();
        $pipeline->process($invoice);
        Mail::assertNothingSent();

        $this->postar->statusToReturn = 'validation_failed';
        $this->postar->validationErrors = ['Peppol rule broken'];
        $pipeline->refreshStatus($invoice);

        Mail::assertSent(InvoiceAlertMail::class, 1);
    }

    public function test_alert_mail_renders_slovak_content(): void
    {
        $invoice = $this->failingIngest();

        $html = (new InvoiceAlertMail($invoice))->render();

        $this->assertStringContainsString('potrebuje zásah', $html);
        $this->assertStringContainsString('nie je platné slovenské IČ DPH', $html);
        $this->assertStringContainsString('/chyby', $html);
    }
}
