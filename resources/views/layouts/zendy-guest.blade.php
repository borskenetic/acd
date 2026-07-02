<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign In') — Zendy Portal</title>
    <link rel="icon" type="image/png" href="{{ \App\Support\VersionedAsset::url('images/d.png') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/zendy-app.css') }}">
    @stack('styles')
</head>
<body>
    <div class="guest-page">
        <div class="guest-card">
            @yield('content')
        </div>
    </div>
    @stack('scripts')
</body>
</html>
