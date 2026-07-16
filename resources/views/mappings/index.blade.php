@extends('layouts.app')

@section('title', 'Mapovania — Faktúrovod')

@section('content')
    <h1 class="page-title">Mapovania</h1>

    <div class="card">
        <h2>Nové mapovanie</h2>
        <form method="POST" action="{{ route('mappings.store') }}" class="form-row">
            @csrf
            <div>
                <label for="name">Názov (napr. „klient-abc-csv")</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required>
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <button type="submit" class="btn">Vytvoriť</button>
            </div>
        </form>
    </div>

    <div class="card">
        @if ($mappings->isEmpty())
            <p class="muted">Zatiaľ žiadne mapovania.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Názov</th>
                        <th>Verzia</th>
                        <th>Typ zdroja</th>
                        <th>Upravené</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($mappings as $mapping)
                        <tr>
                            <td><a href="{{ route('mappings.edit', $mapping) }}">{{ $mapping->name }}</a></td>
                            <td>v{{ $mapping->version }}</td>
                            <td class="mono">{{ $mapping->definition['source']['type'] ?? '—' }}</td>
                            <td class="muted">{{ $mapping->updated_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
