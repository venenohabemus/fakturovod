<!DOCTYPE html>
<html lang="sk">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1a202c; line-height: 1.5;">
    <h2 style="margin: 0 0 0.5rem;">Faktúra {{ $invoice->number ?? $invoice->external_id }} potrebuje zásah</h2>

    <p style="margin: 0 0 1rem;">
        Stav: <strong>{{ $invoice->status->label() }}</strong>
        ({{ $invoice->updated_at->format('d.m.Y H:i') }})
    </p>

    @if ($invoice->error_message !== null)
        <p style="margin: 0 0 1rem; padding: 0.75rem; background: #fff5f5; border-left: 4px solid #e53e3e;">
            {{ $invoice->error_message }}
        </p>
    @endif

    @if ($errors !== [])
        <p style="margin: 0 0 0.25rem;"><strong>Zistené chyby:</strong></p>
        <ul style="margin: 0 0 1rem;">
            @foreach ($errors as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <p style="margin: 0 0 1rem;">
        Opravte zdrojové dáta alebo mapovanie a spustite „Skúsiť znova" vo fronte chýb:
        <br>
        <a href="{{ route('errors.index') }}">{{ route('errors.index') }}</a>
        ·
        <a href="{{ route('invoices.show', $invoice) }}">detail faktúry</a>
    </p>

    <p style="margin: 0; color: #718096; font-size: 0.85rem;">Faktúrovod — automatický alert.</p>
</body>
</html>
