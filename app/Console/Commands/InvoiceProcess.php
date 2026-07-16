<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Pipeline\InvoicePipeline;
use Illuminate\Console\Command;

class InvoiceProcess extends Command
{
    protected $signature = 'invoice:process
        {--retry= : ID faktúry v stave failed/rejected, ktorú treba spracovať znova}';

    protected $description = 'Process pending invoices through the outbound pipeline and refresh sent ones';

    public function handle(InvoicePipeline $pipeline): int
    {
        if (($retryId = $this->option('retry')) !== null) {
            $invoice = Invoice::find($retryId);
            if ($invoice === null) {
                $this->error("Faktúra #{$retryId} neexistuje.");

                return self::FAILURE;
            }
            if (!in_array($invoice->status, [InvoiceStatus::Failed, InvoiceStatus::Rejected], true)) {
                $this->error("Faktúru #{$retryId} nemožno opakovať — je v stave {$invoice->status->value}.");

                return self::FAILURE;
            }
            $invoice->retry();
            $this->info("Faktúra #{$invoice->id} zaradená na opakované spracovanie.");
        }

        $failures = 0;

        foreach (Invoice::whereIn('status', InvoiceStatus::pending())->orderBy('id')->get() as $invoice) {
            $pipeline->process($invoice);
            $this->reportOutcome($invoice);
            if ($invoice->status === InvoiceStatus::Failed) {
                $failures++;
            }
        }

        foreach (Invoice::where('status', InvoiceStatus::Sent->value)->orderBy('id')->get() as $invoice) {
            $pipeline->refreshStatus($invoice);
            $this->reportOutcome($invoice);
            if ($invoice->status === InvoiceStatus::Rejected) {
                $failures++;
            }
        }

        $this->newLine();
        $counts = Invoice::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->info('Stavy faktúr: '.($counts->isEmpty()
            ? 'žiadne faktúry'
            : $counts->map(fn ($n, $status) => "{$status}={$n}")->implode(', ')));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function reportOutcome(Invoice $invoice): void
    {
        $label = "#{$invoice->id} {$invoice->external_id}";

        match ($invoice->status) {
            InvoiceStatus::Failed => $this->error("✗ {$label}: {$invoice->error_message}"),
            InvoiceStatus::Rejected => $this->error("✗ {$label}: {$invoice->error_message}"),
            InvoiceStatus::Delivered => $this->info("✓ {$label}: doručená"),
            InvoiceStatus::Sent => $this->info("→ {$label}: odoslaná, čaká na doručenku"),
            default => $this->line("… {$label}: {$invoice->status->value}"),
        };
    }
}
