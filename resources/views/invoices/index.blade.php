@extends('layouts.app')

@section('title', 'Faktúry — Faktúrovod')

@section('content')
    <h1 class="page-title">Faktúry</h1>

    <div class="card">
        <h2>Import exportu</h2>
        <form method="POST" action="{{ route('invoices.upload') }}" enctype="multipart/form-data" class="form-row">
            @csrf
            <div>
                <label for="export">Súbor (CSV / XML)</label>
                <input id="export" type="file" name="export" required>
            </div>
            <div>
                <label for="mapping_id">Mapovanie</label>
                <select id="mapping_id" name="mapping_id" required>
                    @foreach ($mappings as $mapping)
                        <option value="{{ $mapping->id }}">{{ $mapping->name }} (v{{ $mapping->version }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button type="submit" class="btn">Importovať a spracovať</button>
            </div>
        </form>
        @error('export')
            <p class="field-error">{{ $message }}</p>
        @enderror
        @error('mapping_id')
            <p class="field-error">{{ $message }}</p>
        @enderror
        @if ($mappings->isEmpty())
            <p class="muted">Najprv vytvor <a href="{{ route('mappings.index') }}">mapovanie</a>.</p>
        @endif
    </div>

    <div class="chips">
        <a href="{{ route('invoices.index') }}" class="{{ $activeStatus === null ? 'active' : '' }}">
            Všetky ({{ $counts->sum() }})
        </a>
        @foreach (\App\Enums\InvoiceStatus::cases() as $status)
            @if (($counts[$status->value] ?? 0) > 0)
                <a href="{{ route('invoices.index', ['stav' => $status->value]) }}"
                   class="{{ $activeStatus === $status->value ? 'active' : '' }}">
                    {{ $status->label() }} ({{ $counts[$status->value] }})
                </a>
            @endif
        @endforeach
        <form method="POST" action="{{ route('invoices.process') }}" style="margin-left:auto">
            @csrf
            <button type="submit" class="btn btn-secondary btn-small">Spracovať čakajúce / obnoviť stavy</button>
        </form>
    </div>

    <div class="card">
        @if ($invoices->isEmpty())
            <p class="muted">Žiadne faktúry. Importuj export vyššie.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Číslo / externé ID</th>
                        <th>Príjemca (Peppol)</th>
                        <th>Stav</th>
                        <th>Chyba</th>
                        <th>Aktualizované</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->id }}</td>
                            <td>
                                <a href="{{ route('invoices.show', $invoice) }}">
                                    {{ $invoice->number ?? $invoice->external_id }}
                                </a>
                            </td>
                            <td class="mono">{{ $invoice->receiver_peppol_id ?? '—' }}</td>
                            <td>
                                <span class="badge badge-{{ $invoice->status->severity() }}">
                                    {{ $invoice->status->label() }}
                                </span>
                            </td>
                            <td class="muted">
                                {{ $invoice->error_message !== null ? \Illuminate\Support\Str::limit($invoice->error_message, 70) : '' }}
                            </td>
                            <td class="muted">{{ $invoice->updated_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination-wrap">
                {{ $invoices->links() }}
            </div>
        @endif
    </div>
@endsection
