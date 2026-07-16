<?php

namespace App\Services\Pipeline;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Mapping\InvoiceMapper;
use App\Services\Mapping\MappingException;
use App\Services\Mapping\Readers\CsvReader;
use App\Services\Mapping\Readers\XmlReader;
use App\Services\Mapping\RecordFactory;
use App\Services\Postar\PostarAdapterInterface;
use App\Services\Postar\PostarException;
use App\Services\Ubl\UblInvoiceBuilder;
use App\Services\Ubl\XsdValidator;

/**
 * Outbound pipeline over the invoice state machine:
 *
 *   ingest (received) → map (mapped) → build+validate (validated)
 *   → queue (queued) → send (sent) → poll (delivered|rejected)
 *
 * One bad invoice never blocks the rest of the file: each group is
 * isolated and failures land in `failed` with a Slovak error message.
 * Runs synchronously for now; each step is shaped so it can later be
 * wrapped in a queued job.
 */
class InvoicePipeline
{
    public function __construct(
        private readonly InvoiceMapper $mapper,
        private readonly UblInvoiceBuilder $builder,
        private readonly PostarAdapterInterface $postar,
    ) {
    }

    /**
     * Ingests a source export: one invoice row per group, idempotent on
     * (direction, external_id) — re-ingesting the same file is a no-op.
     *
     * @return array{created: list<Invoice>, duplicates: list<string>}
     */
    public function ingest(string $content, array $definition): array
    {
        $records = $this->readSource($content, $definition);
        $groups = $this->mapper->groupRecords($definition, $records);
        if ($groups === []) {
            throw new MappingException('Vstupný súbor neobsahuje žiadne záznamy.');
        }

        $created = [];
        $duplicates = [];
        foreach ($groups as $groupKey => $group) {
            // No group_by → the whole file is one invoice; hash keeps the id stable.
            $externalId = $groupKey !== '' ? (string) $groupKey : 'subor-'.hash('sha256', $content);

            if (Invoice::where('direction', 'outbound')->where('external_id', $externalId)->exists()) {
                $duplicates[] = $externalId;
                continue;
            }

            $created[] = Invoice::receive([
                'direction' => 'outbound',
                'external_id' => $externalId,
                'source_payload' => array_map(fn ($record) => $record->export(), $group),
                'mapping_definition' => $definition,
            ]);
        }

        return ['created' => $created, 'duplicates' => $duplicates];
    }

    /**
     * Drives one invoice through all remaining outbound steps. Returns the
     * refreshed invoice; failures are recorded on it, not thrown.
     */
    public function process(Invoice $invoice): Invoice
    {
        while (in_array($invoice->status->value, InvoiceStatus::pending(), true)) {
            match ($invoice->status) {
                InvoiceStatus::Received => $this->stepMap($invoice),
                InvoiceStatus::Mapped => $this->stepValidate($invoice),
                InvoiceStatus::Validated => $invoice->transitionTo(
                    InvoiceStatus::Queued,
                    'Faktúra zaradená na odoslanie.'
                ),
                InvoiceStatus::Queued => $this->stepSend($invoice),
                default => null,
            };

            if ($invoice->status === InvoiceStatus::Failed) {
                break;
            }
        }

        return $invoice;
    }

    /**
     * Polls the poštár for the delivery outcome of a sent invoice.
     */
    public function refreshStatus(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Sent || $invoice->postar_document_id === null) {
            return $invoice;
        }

        try {
            $status = $this->postar->getStatus($invoice->postar_document_id);
        } catch (PostarException $exception) {
            // Transient — keep `sent`, only record the attempt.
            $invoice->events()->create([
                'from_status' => $invoice->status->value,
                'to_status' => $invoice->status->value,
                'message' => 'Stav sa nepodarilo zistiť: '.$exception->getMessage(),
            ]);

            return $invoice;
        }

        $provider = strtolower($status->status);

        if ($provider === 'delivered') {
            return $invoice->transitionTo(
                InvoiceStatus::Delivered,
                'Faktúra doručená odberateľovi.'
                .($status->deliveredAt !== null ? " ({$status->deliveredAt})" : '')
            );
        }

        if (in_array($provider, ['rejected', 'validation_failed'], true)) {
            $message = $provider === 'validation_failed'
                ? 'Poštár odmietol faktúru pri Peppol validácii.'
                : 'Odberateľ/poštár faktúru odmietol.';
            $invoice->update([
                'validation_report' => array_merge(
                    $invoice->validation_report ?? [],
                    ['postar' => $status->validationErrors]
                ),
                'error_message' => $message,
            ]);

            return $invoice->transitionTo(InvoiceStatus::Rejected, $message, [
                'errors' => $status->validationErrors,
            ]);
        }

        return $invoice; // still in transit
    }

    private function stepMap(Invoice $invoice): void
    {
        try {
            $group = array_map(
                fn (array $export) => RecordFactory::fromExport($export),
                $invoice->source_payload ?? []
            );
            $canonical = $this->mapper->mapGroup($invoice->mapping_definition ?? [], $group);

            $invoice->update([
                'canonical' => $canonical,
                'number' => $canonical['number'],
                'receiver_peppol_id' => $canonical['customer']['peppol_id'] ?? null,
            ]);
            $invoice->transitionTo(InvoiceStatus::Mapped, 'Dáta úspešne namapované na kanonický model.');
        } catch (MappingException $exception) {
            $invoice->fail('Chyba mapovania: '.$exception->getMessage());
        }
    }

    private function stepValidate(Invoice $invoice): void
    {
        try {
            $xml = $this->builder->build($invoice->canonical);
        } catch (\InvalidArgumentException $exception) {
            $invoice->fail('Faktúru sa nepodarilo zostaviť: '.$exception->getMessage());

            return;
        }

        $validator = new XsdValidator(
            resource_path('schemas/ubl-2.1/maindoc/UBL-Invoice-2.1.xsd')
        );
        $errors = $validator->validate($xml);

        $invoice->update([
            'ubl_xml' => $xml,
            'validation_report' => ['xsd' => $errors],
        ]);

        if ($errors !== []) {
            $invoice->fail(
                'XSD validácia zlyhala ('.count($errors).' chýb).',
                ['errors' => $errors]
            );

            return;
        }

        $invoice->transitionTo(InvoiceStatus::Validated, 'XSD validácia prešla.');
    }

    private function stepSend(Invoice $invoice): void
    {
        if (empty($invoice->receiver_peppol_id)) {
            $invoice->fail('Chýba Peppol ID príjemcu (customer.peppol_id v mapovaní).');

            return;
        }

        // Stable per invoice content — a retry of the same UBL cannot duplicate.
        $idempotencyKey = 'faktura-'.$invoice->external_id.'-'.hash('sha256', $invoice->ubl_xml);

        try {
            $result = $this->postar->sendInvoice(
                $invoice->ubl_xml,
                $invoice->receiver_peppol_id,
                $idempotencyKey
            );
        } catch (PostarException $exception) {
            $invoice->fail('Odoslanie zlyhalo: '.$exception->getMessage(), [
                'provider_code' => $exception->providerCode,
                'retryable' => $exception->retryable,
            ]);

            return;
        }

        $invoice->update(['postar_document_id' => $result->documentId]);
        $invoice->transitionTo(InvoiceStatus::Sent, 'Odovzdané poštárovi na doručenie.', [
            'document_id' => $result->documentId,
            'status' => $result->status,
        ]);
    }

    /**
     * @return list<\App\Services\Mapping\Record>
     */
    private function readSource(string $content, array $definition): array
    {
        $source = $definition['source'] ?? [];
        $type = strtolower($source['type'] ?? 'csv');

        return match ($type) {
            'csv' => (new CsvReader())->read($content, $source),
            'xml' => (new XmlReader())->read($content, $source),
            default => throw new MappingException(
                "Nepodporovaný typ vstupu '{$type}' — podporované sú 'csv' a 'xml'."
            ),
        };
    }
}
