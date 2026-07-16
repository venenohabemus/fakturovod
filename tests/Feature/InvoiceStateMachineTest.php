<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InvoiceStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        return Invoice::receive([
            'external_id' => 'FA-1',
            'source_payload' => [],
            'mapping_definition' => [],
        ]);
    }

    public function test_receive_creates_invoice_with_initial_event(): void
    {
        $invoice = $this->invoice();

        $this->assertSame(InvoiceStatus::Received, $invoice->status);
        $this->assertCount(1, $invoice->events);
        $this->assertNull($invoice->events[0]->from_status);
        $this->assertSame('received', $invoice->events[0]->to_status);
    }

    public function test_valid_transition_appends_audit_event(): void
    {
        $invoice = $this->invoice();

        $invoice->transitionTo(InvoiceStatus::Mapped, 'Namapované.');

        $this->assertSame(InvoiceStatus::Mapped, $invoice->fresh()->status);
        $events = $invoice->events()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame('received', $events[1]->from_status);
        $this->assertSame('mapped', $events[1]->to_status);
        $this->assertSame('Namapované.', $events[1]->message);
    }

    public function test_invalid_transition_throws_and_changes_nothing(): void
    {
        $invoice = $this->invoice();

        try {
            $invoice->transitionTo(InvoiceStatus::Delivered);
            $this->fail('Očakávala sa RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('received → delivered', $exception->getMessage());
        }

        $this->assertSame(InvoiceStatus::Received, $invoice->fresh()->status);
        $this->assertCount(1, $invoice->events);
    }

    public function test_fail_records_slovak_error_message(): void
    {
        $invoice = $this->invoice();

        $invoice->fail('Chyba mapovania: chýba množstvo (riadok 2).', ['row' => 2]);

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Failed, $fresh->status);
        $this->assertSame('Chyba mapovania: chýba množstvo (riadok 2).', $fresh->error_message);

        $lastEvent = $invoice->events()->orderByDesc('id')->first();
        $this->assertSame('failed', $lastEvent->to_status);
        $this->assertSame(['row' => 2], $lastEvent->context);
    }

    public function test_retry_resets_failed_invoice_to_received(): void
    {
        $invoice = $this->invoice();
        $invoice->fail('Nejaká chyba.');

        $invoice->retry();

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Received, $fresh->status);
        $this->assertNull($fresh->error_message);
    }

    public function test_delivered_is_terminal(): void
    {
        $this->assertSame([], InvoiceStatus::Delivered->allowedTransitions());
    }
}
