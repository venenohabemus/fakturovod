<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Mapping;
use App\Services\Mapping\MappingException;
use App\Services\Pipeline\InvoicePipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('stav');

        $invoices = Invoice::orderByDesc('id')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->paginate(25)
            ->withQueryString();

        $counts = Invoice::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('invoices.index', [
            'invoices' => $invoices,
            'counts' => $counts,
            'activeStatus' => $status,
            'mappings' => Mapping::orderBy('name')->get(),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        return view('invoices.show', [
            'invoice' => $invoice->load('events'),
        ]);
    }

    public function retry(Invoice $invoice, InvoicePipeline $pipeline): RedirectResponse
    {
        if (!in_array($invoice->status, [InvoiceStatus::Failed, InvoiceStatus::Rejected], true)) {
            return back()->with('error', "Faktúru nemožno opakovať — je v stave {$invoice->status->label()}.");
        }

        $invoice->retry();
        $pipeline->process($invoice);
        $pipeline->refreshStatus($invoice);

        return back()->with(
            $invoice->status->severity() === 'error' ? 'error' : 'status',
            "Faktúra {$invoice->external_id} po opakovaní v stave: {$invoice->status->label()}."
            .($invoice->error_message !== null ? " {$invoice->error_message}" : '')
        );
    }

    public function processAll(InvoicePipeline $pipeline): RedirectResponse
    {
        $processed = 0;
        foreach (Invoice::whereIn('status', InvoiceStatus::pending())->orderBy('id')->get() as $invoice) {
            $pipeline->process($invoice);
            $processed++;
        }
        foreach (Invoice::where('status', InvoiceStatus::Sent->value)->orderBy('id')->get() as $invoice) {
            $pipeline->refreshStatus($invoice);
            $processed++;
        }

        return back()->with('status', "Spracovanie dokončené ({$processed} faktúr).");
    }

    public function upload(Request $request, InvoicePipeline $pipeline): RedirectResponse
    {
        $request->validate(
            [
                'export' => ['required', 'file', 'max:10240'],
                'mapping_id' => ['required', 'exists:mappings,id'],
            ],
            [
                'export.required' => 'Vyber súbor s exportom.',
                'mapping_id.required' => 'Vyber mapovanie.',
                'mapping_id.exists' => 'Zvolené mapovanie neexistuje.',
            ]
        );

        $mapping = Mapping::findOrFail($request->input('mapping_id'));
        $content = file_get_contents($request->file('export')->getRealPath());

        try {
            $result = $pipeline->ingest($content, $mapping->definition);
        } catch (MappingException $exception) {
            return back()->with('error', 'Import zlyhal: '.$exception->getMessage());
        }

        $failed = 0;
        foreach ($result['created'] as $invoice) {
            $pipeline->process($invoice);
            $pipeline->refreshStatus($invoice);
            if ($invoice->status->severity() === 'error') {
                $failed++;
            }
        }

        $message = sprintf(
            'Import hotový: %d nových faktúr, %d duplicít preskočených.',
            count($result['created']),
            count($result['duplicates'])
        );
        if ($failed > 0) {
            return redirect()->route('errors.index')->with(
                'error',
                $message." {$failed} z nich skončilo s chybou — tu je fronta chýb."
            );
        }

        return redirect()->route('invoices.index')->with('status', $message);
    }

    public function downloadUbl(Invoice $invoice): Response
    {
        abort_if($invoice->ubl_xml === null, 404, 'Faktúra zatiaľ nemá vygenerované UBL.');

        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice->number ?? $invoice->external_id);

        return response($invoice->ubl_xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'.xml"',
        ]);
    }
}
