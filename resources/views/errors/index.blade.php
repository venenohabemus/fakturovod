@extends('layouts.app')

@section('title', 'Fronta chýb — Faktúrovod')

@section('content')
    <h1 class="page-title">Fronta chýb</h1>

    @if ($invoices->isEmpty())
        <div class="card">
            <p style="margin:0;">✅ Žiadne chybné faktúry — všetko tečie, ako má.</p>
        </div>
    @else
        <p class="muted" style="margin-top:-0.5rem;">
            Faktúry, ktoré potrebujú zásah. Oprav zdrojové dáta alebo mapovanie a klikni „Skúsiť znova".
        </p>

        @foreach ($invoices as $invoice)
            <article class="error-item">
                <header>
                    <span class="title">
                        <a href="{{ route('invoices.show', $invoice) }}">
                            {{ $invoice->number ?? $invoice->external_id }}
                        </a>
                    </span>
                    <span class="badge badge-error">{{ $invoice->status->label() }}</span>
                    <span class="muted">{{ $invoice->updated_at->format('d.m.Y H:i') }}</span>
                </header>

                @php($report = $invoice->validation_report ?? [])
                @php($allErrors = array_merge($report['mapping'] ?? [], $report['business'] ?? [], $report['xsd'] ?? [], $report['postar'] ?? []))

                @if ($allErrors !== [])
                    <ul>
                        @foreach ($allErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @elseif ($invoice->error_message !== null)
                    <p>{{ $invoice->error_message }}</p>
                @endif

                <div class="actions">
                    <form method="POST" action="{{ route('invoices.retry', $invoice) }}">
                        @csrf
                        <button type="submit" class="btn btn-small">Skúsiť znova</button>
                    </form>
                    <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-secondary btn-small">Detail</a>
                </div>
            </article>
        @endforeach
    @endif
@endsection
