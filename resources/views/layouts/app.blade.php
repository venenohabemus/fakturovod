<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Faktúrovod')</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>
<header class="topbar">
    <span class="brand">Faktúrovod</span>
    <nav>
        <a href="{{ route('invoices.index') }}" class="{{ request()->routeIs('invoices.*') ? 'active' : '' }}">Faktúry</a>
        <a href="{{ route('errors.index') }}" class="{{ request()->routeIs('errors.*') ? 'active' : '' }}">
            Fronta chýb
            @if ($errorCount > 0)
                <span class="badge-count">{{ $errorCount }}</span>
            @endif
        </a>
        <a href="{{ route('mappings.index') }}" class="{{ request()->routeIs('mappings.*') ? 'active' : '' }}">Mapovania</a>
        <a href="{{ route('usage.index') }}" class="{{ request()->routeIs('usage.*') ? 'active' : '' }}">Spotreba</a>
    </nav>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Odhlásiť ({{ auth()->user()->name }})</button>
    </form>
</header>

<main class="container">
    @if (session('status'))
        <div class="flash flash-ok">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="flash flash-error">{{ session('error') }}</div>
    @endif

    @yield('content')
</main>
</body>
</html>
