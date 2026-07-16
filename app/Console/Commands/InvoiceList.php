<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class InvoiceList extends Command
{
    protected $signature = 'invoice:list {--events= : Zobraz audit udalosti faktúry s daným ID}';

    protected $description = 'List invoices in the pipeline (or the audit trail of one invoice)';

    public function handle(): int
    {
        if (($invoiceId = $this->option('events')) !== null) {
            return $this->showEvents($invoiceId);
        }

        $invoices = Invoice::orderBy('id')->get();
        if ($invoices->isEmpty()) {
            $this->info('Žiadne faktúry. Použi `php artisan invoice:ingest <export> <mapovanie>`.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Externé ID', 'Číslo', 'Stav', 'Príjemca', 'Chyba'],
            $invoices->map(fn (Invoice $invoice) => [
                $invoice->id,
                $invoice->external_id,
                $invoice->number ?? '—',
                $invoice->status->value,
                $invoice->receiver_peppol_id ?? '—',
                $invoice->error_message !== null
                    ? mb_strimwidth($invoice->error_message, 0, 60, '…')
                    : '',
            ])
        );

        return self::SUCCESS;
    }

    private function showEvents(string $invoiceId): int
    {
        $invoice = Invoice::with('events')->find($invoiceId);
        if ($invoice === null) {
            $this->error("Faktúra #{$invoiceId} neexistuje.");

            return self::FAILURE;
        }

        $this->info("Faktúra #{$invoice->id} ({$invoice->external_id}) — stav {$invoice->status->value}");
        $this->table(
            ['Kedy', 'Prechod', 'Správa'],
            $invoice->events->map(fn ($event) => [
                $event->created_at?->format('Y-m-d H:i:s'),
                ($event->from_status ?? '∅').' → '.$event->to_status,
                $event->message ?? '',
            ])
        );

        return self::SUCCESS;
    }
}
