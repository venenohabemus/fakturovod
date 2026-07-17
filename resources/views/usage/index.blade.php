@extends('layouts.app')

@section('title', 'Spotreba — Faktúrovod')

@section('content')
    <h1 class="page-title">Spotreba</h1>

    <p class="muted" style="margin-top:-0.5rem;">
        Počty dokumentov per mesiac — podklad pre fakturáciu tierov a kontrolu API nákladov poštára.
    </p>

    @if ($meters->isEmpty())
        <div class="card">
            <p style="margin:0;">Zatiaľ žiadne namerané dokumenty.</p>
        </div>
    @else
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Mesiac</th>
                        <th>Metrika</th>
                        <th style="text-align:right;">Počet</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($meters as $period => $periodMeters)
                        @foreach ($periodMeters as $meter)
                            <tr>
                                <td class="mono">{{ $period }}</td>
                                <td>{{ ['documents_sent' => 'Odoslané dokumenty', 'documents_received' => 'Prijaté dokumenty'][$meter->metric] ?? $meter->metric }}</td>
                                <td class="mono" style="text-align:right;">{{ $meter->count }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card">
        <h2>Archív</h2>
        <p style="margin:0;">
            Uložených objektov: <strong>{{ $archive['objects'] }}</strong>
            ({{ number_format($archive['bytes'] / 1024, 1, ',', ' ') }} kB)
        </p>
    </div>
@endsection
