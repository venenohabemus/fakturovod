<?php

namespace App\Services\Archive;

use App\Models\ArchiveObject;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes an immutable archive copy of a sent invoice to object storage:
 * the exact UBL XML handed to the poštár plus the raw source rows it was
 * built from. The DB keeps working copies; the archive is the evidence —
 * each object is recorded with its SHA-256 so at dispute time we can show
 * the stored bytes are exactly what left the system.
 */
class InvoiceArchiver
{
    public const DISK = 'archive';

    /**
     * Archives the invoice's UBL XML and source payload. Idempotent —
     * already-archived artifacts are skipped, so a status-refresh or retry
     * can never overwrite the stored evidence.
     *
     * @return list<ArchiveObject> newly created archive records
     */
    public function archive(Invoice $invoice): array
    {
        $created = [];

        if ($invoice->ubl_xml !== null) {
            $object = $this->store($invoice, ArchiveObject::TYPE_UBL, 'faktura.xml', $invoice->ubl_xml);
            if ($object !== null) {
                $created[] = $object;
            }
        }

        if ($invoice->source_payload !== null) {
            $json = json_encode(
                $invoice->source_payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $object = $this->store($invoice, ArchiveObject::TYPE_SOURCE, 'zdroj.json', $json);
            if ($object !== null) {
                $created[] = $object;
            }
        }

        if ($created !== []) {
            $invoice->events()->create([
                'from_status' => $invoice->status->value,
                'to_status' => $invoice->status->value,
                'message' => 'Faktúra archivovaná ('.implode(', ', array_map(
                    fn (ArchiveObject $object) => basename($object->path),
                    $created
                )).').',
            ]);
        }

        return $created;
    }

    private function store(Invoice $invoice, string $type, string $filename, string $content): ?ArchiveObject
    {
        if ($invoice->archiveObjects()->where('type', $type)->exists()) {
            return null;
        }

        $path = $this->path($invoice, $filename);
        Storage::disk(self::DISK)->put($path, $content);

        return $invoice->archiveObjects()->create([
            'type' => $type,
            'disk' => self::DISK,
            'path' => $path,
            'sha256' => hash('sha256', $content),
            'size_bytes' => strlen($content),
        ]);
    }

    /**
     * outbound/2026/07/000123-fa-2026-0101/faktura.xml — the invoice id
     * guarantees uniqueness, the slugged external id keeps it human-readable.
     */
    private function path(Invoice $invoice, string $filename): string
    {
        return sprintf(
            '%s/%s/%06d-%s/%s',
            // Fresh models may not have the DB column default materialized yet.
            $invoice->direction ?: 'outbound',
            $invoice->created_at->format('Y/m'),
            $invoice->id,
            Str::slug($invoice->external_id) ?: 'faktura',
            $filename
        );
    }
}
