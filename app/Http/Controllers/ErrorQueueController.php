<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\View\View;

class ErrorQueueController extends Controller
{
    /**
     * The most important screen of the product: every invoice that needs
     * a human, with every problem listed in Slovak.
     */
    public function index(): View
    {
        $invoices = Invoice::whereIn('status', InvoiceStatus::erroneous())
            ->orderByDesc('updated_at')
            ->get();

        return view('errors.index', ['invoices' => $invoices]);
    }
}
