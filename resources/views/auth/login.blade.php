<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prihlásenie — Faktúrovod</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <h1>Faktúrovod</h1>
        <p class="sub">Prihlásenie do dashboardu</p>

        <form method="POST" action="{{ route('login.attempt') }}">
            @csrf
            <div class="field">
                <label for="email">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
            </div>
            <div class="field">
                <label for="password">Heslo</label>
                <input id="password" type="password" name="password" required>
            </div>
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <button type="submit" class="btn">Prihlásiť sa</button>
        </form>
    </div>
</div>
</body>
</html>
