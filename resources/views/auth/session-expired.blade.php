<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Session expired — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ \App\Support\Branding::stylesheetUrl() }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/auth/auth.css') }}">
</head>
<body class="sx-page">
    <header class="sx-top">
        <div>
            <p class="sx-school">{{ config('app.name') }}</p>
            <p class="sx-kicker">Powered by Pantas</p>
        </div>
        <a href="{{ route('home') }}" class="sx-home-chip">← Home</a>
    </header>

    <main class="sx-main">
        <div class="sx-card">
            <img src="{{ asset('images/pantasLogo.png') }}" alt="{{ config('app.name') }}" class="sx-logo">
            <h1 class="sx-title">Your session expired</h1>
            <p class="sx-copy">
                This page was left open too long, so your sign-in timed out. Nothing was saved from that last action. Sign in again to continue.
            </p>
            <a href="{{ route('login') }}" class="auth-btn auth-btn--primary sx-btn">Sign in again</a>
            <a href="{{ route('home') }}" class="auth-btn auth-btn--outline sx-btn">Go to home</a>
        </div>
    </main>
</body>
</html>
