@extends('layouts.app')

@section('title', 'Mapovanie '.$mapping->name.' — Faktúrovod')

@section('content')
    <h1 class="page-title">Mapovanie „{{ $mapping->name }}" <span class="muted">v{{ $mapping->version }}</span></h1>

    <div class="card">
        <form method="POST" action="{{ route('mappings.update', $mapping) }}">
            @csrf
            @method('PUT')
            <label for="definition">Definícia (JSON) — uložením sa zvýši verzia</label>
            <textarea id="definition" name="definition" class="code" spellcheck="false">{{ old('definition', json_encode($mapping->definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}</textarea>
            @error('definition')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <p style="margin-top:1rem;">
                <button type="submit" class="btn">Uložiť</button>
                <a href="{{ route('mappings.index') }}" class="btn btn-secondary">Späť</a>
            </p>
        </form>
    </div>

    <div class="card">
        <h2>Ťahák</h2>
        <p class="muted" style="margin:0;">
            Pole = <span class="mono">"cieľ": "zdrojový_stĺpec"</span> alebo objekt s
            <span class="mono">from</span> / <span class="mono">const</span>,
            voliteľne <span class="mono">default</span>, <span class="mono">map</span> + <span class="mono">map_default</span>
            a <span class="mono">transform</span>
            (<span class="mono">{"type":"date","from_format":"d.m.Y"}</span>,
            <span class="mono">{"type":"decimal"}</span>).
            Zoskupenie riadkov do faktúr: <span class="mono">source.group_by</span>.
            Povinné polia faktúry: number, issue_date, currency, supplier/customer (name, country), lines.fields
            (name, quantity, unit_price, vat_rate). Peppol: buyer_reference a peppol_id strán.
        </p>
    </div>
@endsection
