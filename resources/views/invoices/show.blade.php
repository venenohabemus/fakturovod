@extends('layouts.app')

@section('title', 'Faktúra '.($invoice->number ?? $invoice->external_id).' — Faktúrovod')

@section('content')
    <h1 class="page-title">
        Faktúra {{ $invoice->number ?? $invoice->external_id }}
        <span class="badge badge-{{ $invoice->status->severity() }}">{{ $invoice->status->label() }}</span>
    </h1>

    <div class="card">
        <dl class="detail-grid">
            <div><dt>Externé ID</dt><dd class="mono">{{ $invoice->external_id }}</dd></div>
            <div><dt>Číslo faktúry</dt><dd>{{ $invoice->number ?? '—' }}</dd></div>
            <div><dt>Príjemca (Peppol)</dt><dd class="mono">{{ $invoice->receiver_peppol_id ?? '—' }}</dd></div>
            <div><dt>ID u poštára</dt><dd class="mono">{{ $invoice->postar_document_id ?? '—' }}</dd></div>
            <div><dt>Prijatá</dt><dd>{{ $invoice->created_at->format('d.m.Y H:i:s') }}</dd></div>
            <div><dt>Aktualizovaná</dt><dd>{{ $invoice->updated_at->format('d.m.Y H:i:s') }}</dd></div>
        </dl>

        <p style="margin-top:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
            @if (in_array($invoice->status, [\App\Enums\InvoiceStatus::Failed, \App\Enums\InvoiceStatus::Rejected], true))
                <form method="POST" action="{{ route('invoices.retry', $invoice) }}">
                    @csrf
                    <button type="submit" class="btn">Skúsiť znova</button>
                </form>
            @endif
            @if ($invoice->ubl_xml !== null)
                <a href="{{ route('invoices.ubl', $invoice) }}" class="btn btn-secondary">Stiahnuť UBL XML</a>
            @endif
        </p>
    </div>

    @php($report = $invoice->validation_report ?? [])
    @php($reportedErrors = array_merge($report['mapping'] ?? [], $report['business'] ?? [], $report['xsd'] ?? [], $report['schematron'] ?? [], $report['postar'] ?? []))

    @if ($invoice->error_message !== null || $reportedErrors !== [])
        <div class="card">
            <h2>Chyby a validácia</h2>
            @if ($invoice->error_message !== null)
                <div class="flash flash-error">{{ $invoice->error_message }}</div>
            @endif

            @foreach (['mapping' => 'Chyby mapovania', 'business' => 'Biznis kontroly (SK)', 'xsd' => 'XSD validácia', 'schematron' => 'Schematron (EN 16931 / Peppol)', 'postar' => 'Validácia poštára (Peppol)'] as $section => $heading)
                @if (!empty($invoice->validation_report[$section]))
                    <h3 style="font-size:0.95rem; margin:0.75rem 0 0.25rem;">{{ $heading }}</h3>
                    <ul>
                        @foreach ($invoice->validation_report[$section] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </div>
    @endif

    @if ($invoice->canonical !== null)
        <div class="card">
            <h2>Údaje faktúry (kanonický model)</h2>
            <dl class="detail-grid">
                <div><dt>Vystavená</dt><dd>{{ $invoice->canonical['issue_date'] ?? '—' }}</dd></div>
                <div><dt>Splatná</dt><dd>{{ $invoice->canonical['due_date'] ?? '—' }}</dd></div>
                <div><dt>Mena</dt><dd>{{ $invoice->canonical['currency'] ?? '—' }}</dd></div>
                <div><dt>Dodávateľ</dt><dd>{{ $invoice->canonical['supplier']['name'] ?? '—' }}</dd></div>
                <div><dt>Odberateľ</dt><dd>{{ $invoice->canonical['customer']['name'] ?? '—' }}</dd></div>
            </dl>

            @if (!empty($invoice->canonical['lines']))
                <div class="table-wrap" style="margin-top:1rem;">
                    <table>
                        <thead>
                        <tr>
                            <th>Položka</th>
                            <th>Množstvo</th>
                            <th>Jednotka</th>
                            <th>Cena bez DPH</th>
                            <th>DPH %</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($invoice->canonical['lines'] as $line)
                            <tr>
                                <td>{{ $line['name'] ?? '—' }}</td>
                                <td>{{ $line['quantity'] ?? '—' }}</td>
                                <td>{{ $line['unit'] ?? 'C62' }}</td>
                                <td>{{ $line['unit_price'] ?? '—' }}</td>
                                <td>{{ $line['vat_rate'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>Audit — história spracovania</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Kedy</th>
                    <th>Prechod</th>
                    <th>Správa</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($invoice->events->sortBy('id') as $event)
                    <tr>
                        <td class="muted">{{ $event->created_at?->format('d.m.Y H:i:s') }}</td>
                        <td class="mono">{{ $event->from_status ?? '∅' }} → {{ $event->to_status }}</td>
                        <td>{{ $event->message }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($invoice->ubl_xml !== null)
        <div class="card">
            <h2>UBL 2.1 XML</h2>
            <pre class="xml">{{ $invoice->ubl_xml }}</pre>
        </div>
    @endif
@endsection
