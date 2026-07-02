<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="{{ \App\Support\Branding::stylesheetUrl() }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/navbar.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/app-fonts.css') }}">
    @stack('styles')
    @yield('styles')
    @stack('page-styles')
    @php
        $usesAdminShell = auth('web')->check()
            && auth('web')->user()->can('isAdminOrStaff')
            && ! request()->routeIs('home');
    @endphp
</head>
<body class="@yield('body_class') {{ $usesAdminShell ? 'admin-shell-body' : '' }}" style="background: var(--brand-page-bg, #f5f7fa);">
    @if($usesAdminShell)
        @include('layouts.partials.admin-sidebar')
    @else
        @include('layouts.partials.navbar')
    @endif

    <main class="{{ $usesAdminShell ? 'admin-main' : 'py-3' }}">
        @if($usesAdminShell)
        {{-- SidebarTrigger: desktop collapse/expand toggle --}}
        <div class="admin-sidebar-trigger-bar">
            <button class="admin-sidebar-trigger" id="sidebarCollapseBtn" type="button" aria-label="Toggle sidebar" title="Toggle sidebar">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M9 3v18"/>
                </svg>
            </button>
        </div>
        @endif
        @hasSection('banner')
            <div class="pantas-banner {{ $usesAdminShell ? 'pantas-banner--admin' : 'pantas-banner--public' }}">
                @yield('banner')
            </div>
        @endif
        <div class="container-fluid px-3 px-lg-4">
            @yield('content')
        </div>
    </main>

    @yield('footer')
    @stack('scripts')
    @yield('scripts')

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
