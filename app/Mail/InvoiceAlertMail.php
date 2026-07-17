<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "An invoice needs a human" — sent when one lands in the error queue.
 * Slovak, actionable, with every collected error and a link straight to
 * the error queue.
 */
class InvoiceAlertMail extends Mailable
{
    public function __construct(public readonly Invoice $invoice)
    {
    }

    public function envelope(): Envelope
    {
        $label = $this->invoice->number ?? $this->invoice->external_id;

        return new Envelope(
            subject: "⚠️ Faktúra {$label} potrebuje zásah ({$this->invoice->status->label()})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-alert',
            with: [
                'invoice' => $this->invoice,
                'errors' => $this->collectErrors(),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function collectErrors(): array
    {
        $report = $this->invoice->validation_report ?? [];

        return array_merge(
            $report['mapping'] ?? [],
            $report['business'] ?? [],
            $report['xsd'] ?? [],
            $report['postar'] ?? [],
        );
    }
}
