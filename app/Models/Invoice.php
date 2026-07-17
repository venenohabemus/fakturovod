<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Invoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'source_payload' => 'array',
            'mapping_definition' => 'array',
            'canonical' => 'array',
            'validation_report' => 'array',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(InvoiceEvent::class);
    }

    public function archiveObjects(): HasMany
    {
        return $this->hasMany(ArchiveObject::class);
    }

    /**
     * Creates a freshly ingested invoice and records the initial event.
     */
    public static function receive(array $attributes, ?string $message = null): self
    {
        return DB::transaction(function () use ($attributes, $message) {
            $invoice = self::create($attributes + ['status' => InvoiceStatus::Received]);
            $invoice->events()->create([
                'from_status' => null,
                'to_status' => InvoiceStatus::Received->value,
                'message' => $message ?? 'Faktúra prijatá na spracovanie.',
            ]);

            return $invoice;
        });
    }

    /**
     * Moves the invoice to a new status, enforcing the state machine and
     * appending an audit event — the only allowed way to change status.
     */
    public function transitionTo(InvoiceStatus $target, ?string $message = null, array $context = []): self
    {
        $current = $this->status;

        if (!$current->canTransitionTo($target)) {
            throw new RuntimeException(
                "Neplatný prechod stavu faktúry #{$this->id}: {$current->value} → {$target->value}."
            );
        }

        return DB::transaction(function () use ($current, $target, $message, $context) {
            $this->update(['status' => $target]);
            $this->events()->create([
                'from_status' => $current->value,
                'to_status' => $target->value,
                'message' => $message,
                'context' => $context === [] ? null : $context,
            ]);

            return $this;
        });
    }

    /**
     * Marks the invoice failed with a user-facing (Slovak) error message
     * and nudges the humans — every failure means the error queue grew.
     */
    public function fail(string $message, array $context = []): self
    {
        $this->update(['error_message' => $message]);
        $this->transitionTo(InvoiceStatus::Failed, $message, $context);

        app(\App\Services\Alerts\InvoiceAlerts::class)->invoiceNeedsAttention($this);

        return $this;
    }

    /**
     * Resets a failed/rejected invoice for another processing attempt.
     */
    public function retry(): self
    {
        $this->update(['error_message' => null]);

        return $this->transitionTo(InvoiceStatus::Received, 'Opakované spracovanie faktúry.');
    }
}
