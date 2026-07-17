<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Archive\InvoiceArchiver;
use App\Services\Pipeline\InvoicePipeline;
use App\Services\Postar\PostarAdapterInterface;
use App\Services\Validation\SchematronUnavailableException;
use App\Services\Validation\SchematronValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePostarAdapter;
use Tests\TestCase;

class SchematronValidatorTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://localhost:8081';

    private function rejectReport(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1">
  <rep:scenarioMatched>
    <rep:validationStepResult id="val-sch.1" valid="false">
      <rep:message id="val-sch.1.1" level="error" code="BR-CO-25">[BR-CO-25]-In case the Amount due for payment (BT-115) is positive...</rep:message>
      <rep:message id="val-sch.1.2" level="warning" code="UBL-DT-01">Only a warning, must not fail the invoice.</rep:message>
    </rep:validationStepResult>
    <rep:validationStepResult id="val-sch.2" valid="false">
      <rep:message id="val-sch.2.1" level="error" code="PEPPOL-EN16931-R003">[PEPPOL-EN16931-R003]-A buyer reference or purchase order reference MUST be provided.</rep:message>
    </rep:validationStepResult>
  </rep:scenarioMatched>
  <rep:assessment><rep:reject/></rep:assessment>
</rep:report>
XML;
    }

    public function test_extracts_error_level_messages_from_reject_report(): void
    {
        config(['schematron.url' => self::URL]);
        Http::fake([self::URL => Http::response($this->rejectReport(), 406)]);

        $errors = (new SchematronValidator())->validate('<Invoice/>');

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('BR-CO-25', $errors[0]);
        $this->assertStringContainsString('PEPPOL-EN16931-R003', $errors[1]);
    }

    public function test_accepted_report_yields_no_errors(): void
    {
        config(['schematron.url' => self::URL]);
        Http::fake([self::URL => Http::response(
            '<rep:report xmlns:rep="http://www.xoev.de/de/validator/varl/1"><rep:assessment><rep:accept/></rep:assessment></rep:report>',
            200
        )]);

        $this->assertSame([], (new SchematronValidator())->validate('<Invoice/>'));
    }

    public function test_unreachable_sidecar_throws_unavailable(): void
    {
        config(['schematron.url' => self::URL]);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(SchematronUnavailableException::class);
        (new SchematronValidator())->validate('<Invoice/>');
    }

    public function test_unexpected_status_throws_unavailable(): void
    {
        config(['schematron.url' => self::URL]);
        Http::fake([self::URL => Http::response('oops', 500)]);

        $this->expectException(SchematronUnavailableException::class);
        (new SchematronValidator())->validate('<Invoice/>');
    }

    public function test_pipeline_fails_invoice_on_schematron_violation(): void
    {
        config(['schematron.url' => self::URL]);
        Http::fake([self::URL => Http::response($this->rejectReport(), 406)]);
        Storage::fake(InvoiceArchiver::DISK);
        $this->app->instance(PostarAdapterInterface::class, new FakePostarAdapter());

        $pipeline = $this->app->make(InvoicePipeline::class);
        $pipeline->ingest(
            file_get_contents(resource_path('samples/legacy-export.csv')),
            json_decode(file_get_contents(resource_path('samples/mapping-legacy-csv.json')), true)
        );

        $invoice = Invoice::first();
        $pipeline->process($invoice);

        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        $this->assertStringContainsString('Schematron validácia', $invoice->error_message);
        $this->assertCount(2, $invoice->validation_report['schematron']);
    }

    public function test_pipeline_continues_when_sidecar_is_down(): void
    {
        config(['schematron.url' => self::URL]);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        Storage::fake(InvoiceArchiver::DISK);
        $this->app->instance(PostarAdapterInterface::class, new FakePostarAdapter());

        $pipeline = $this->app->make(InvoicePipeline::class);
        $pipeline->ingest(
            file_get_contents(resource_path('samples/legacy-export.csv')),
            json_decode(file_get_contents(resource_path('samples/mapping-legacy-csv.json')), true)
        );

        $invoice = Invoice::first();
        $pipeline->process($invoice);

        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertTrue(
            $invoice->events()->where('message', 'like', 'Schematron preskočený%')->exists()
        );
    }
}
