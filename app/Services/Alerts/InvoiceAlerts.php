<?php

namespace App\Services\Alerts;

use App\Mail\InvoiceAlertMail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "invoice needs a human" e-mail. No recipients configured
 * (ALERT_EMAIL empty) means alerting is off. An alert failure must never
 * break the pipeline — the invoice already carries its error state and
 * the error queue is the source of truth; the mail is only a nudge.
 */
class InvoiceAlerts
{
    public function invoiceNeedsAttention(Invoice $invoice): void
    {
        $recipients = config('alerts.recipients', []);
        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new InvoiceAlertMail($invoice));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
