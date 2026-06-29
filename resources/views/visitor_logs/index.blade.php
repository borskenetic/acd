@extends('layouts.app')

@section('title', 'Visitor Logs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/visitor_logs/logs.css') }}">
@endpush

@section('content')
@php
    $query = request()->query();
    $hasFilters = collect($query)->except('page')->filter()->isNotEmpty();
    $tz = config('app.timezone', 'Asia/Manila');
    $today = now($tz)->toDateString();
    $weekStart = now($tz)->startOfWeek()->toDateString();
    $monthStart = now($tz)->startOfMonth()->toDateString();
    $currentStatus = strtoupper((string) request('status'));

    $filterUrl = function (array $merge = [], array $except = []) use ($query) {
        $params = collect($query)->except(array_merge(['page'], $except))->merge($merge)->filter(fn ($v) => $v !== null && $v !== '')->all();

        return route('visitor_logs.index', $params);
    };

    $isDatePreset = fn (string $preset) => match ($preset) {
        'today' => request('from') === $today && request('to') === $today,
        'week' => request('from') === $weekStart && request('to') === $today,
        'month' => request('from') === $monthStart && request('to') === $today,
        'all' => ! request('from') && ! request('to'),
        default => false,
    };
@endphp

<div class="data-page visitor-logs-page">
    <header class="vl-header">
        <div class="vl-header__text">
            <h1 class="vl-title">Visitor Logs</h1>
            <p class="vl-subtitle">Gate terminal check-ins and check-outs for registered visitors.</p>
        </div>
        <div class="vl-header__actions">
            <a href="{{ route('visitors.issue.create') }}" class="vl-btn vl-btn--primary">Issue walk-in pass</a>
            <a href="{{ route('visitors.register') }}" target="_blank" rel="noopener" class="vl-btn vl-btn--ghost">Visitor registration</a>
            <a href="{{ route('attendance.scan') }}" target="_blank" rel="noopener" class="vl-btn vl-btn--ghost">Gate Terminal</a>
        </div>
    </header>

    <div class="vl-stats">
        <div class="vl-stat-card">
            <span class="vl-stat-card__label">Matching</span>
            <strong class="vl-stat-card__value">{{ number_format($summary['total']) }}</strong>
        </div>
        <div class="vl-stat-card vl-stat-card--in">
            <span class="vl-stat-card__label">Check-ins</span>
            <strong class="vl-stat-card__value">{{ number_format($summary['in']) }}</strong>
        </div>
        <div class="vl-stat-card vl-stat-card--out">
            <span class="vl-stat-card__label">Check-outs</span>
            <strong class="vl-stat-card__value">{{ number_format($summary['out']) }}</strong>
        </div>
        <div class="vl-stat-card vl-stat-card--today">
            <span class="vl-stat-card__label">Today</span>
            <strong class="vl-stat-card__value">{{ number_format($summary['today']) }}</strong>
        </div>
    </div>

    <section class="vl-controls">
        <form method="GET" class="vl-controls__form">
            <div class="vl-search-row">
                <label class="vl-search" for="vlSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="vlSearch" name="search" value="{{ request('search') }}"
                           placeholder="Name, organization, or QR code…" autocomplete="off">
                </label>
                <button type="submit" class="vl-btn vl-btn--primary">Search</button>
            </div>

            <div class="vl-control-row">
                <div class="vl-control-group">
                    <span class="vl-control-group__label">Period</span>
                    <div class="vl-pills">
                        <a href="{{ $filterUrl(['from' => $today, 'to' => $today]) }}" class="vl-pill {{ $isDatePreset('today') ? 'is-active' : '' }}">Today</a>
                        <a href="{{ $filterUrl(['from' => $weekStart, 'to' => $today]) }}" class="vl-pill {{ $isDatePreset('week') ? 'is-active' : '' }}">This week</a>
                        <a href="{{ $filterUrl(['from' => $monthStart, 'to' => $today]) }}" class="vl-pill {{ $isDatePreset('month') ? 'is-active' : '' }}">This month</a>
                        <a href="{{ $filterUrl([], ['from', 'to']) }}" class="vl-pill {{ $isDatePreset('all') ? 'is-active' : '' }}">All time</a>
                    </div>
                </div>
                <div class="vl-control-group">
                    <span class="vl-control-group__label">Status</span>
                    <div class="vl-pills">
                        <a href="{{ $filterUrl([], ['status']) }}" class="vl-pill {{ $currentStatus === '' ? 'is-active' : '' }}">All</a>
                        <a href="{{ $filterUrl(['status' => 'IN']) }}" class="vl-pill vl-pill--in {{ $currentStatus === 'IN' ? 'is-active' : '' }}">IN</a>
                        <a href="{{ $filterUrl(['status' => 'OUT']) }}" class="vl-pill vl-pill--out {{ $currentStatus === 'OUT' ? 'is-active' : '' }}">OUT</a>
                    </div>
                </div>
            </div>

            <details class="vl-more-filters" {{ request()->hasAny(['from', 'to']) ? 'open' : '' }}>
                <summary>Custom date range</summary>
                <div class="vl-more-filters__grid">
                    <div class="vl-field">
                        <label for="vlFrom">From</label>
                        <input type="date" id="vlFrom" name="from" value="{{ request('from') }}">
                    </div>
                    <div class="vl-field">
                        <label for="vlTo">To</label>
                        <input type="date" id="vlTo" name="to" value="{{ request('to') }}">
                    </div>
                    <div class="vl-field vl-field--actions">
                        <button type="submit" class="vl-btn vl-btn--primary">Apply</button>
                        @if($hasFilters)
                            <a href="{{ route('visitor_logs.index') }}" class="vl-btn vl-btn--ghost">Clear all</a>
                        @endif
                    </div>
                </div>
            </details>

            @if($currentStatus !== '')
                <input type="hidden" name="status" value="{{ $currentStatus }}">
            @endif
        </form>
    </section>

    <section class="vl-table-card">
        <div class="vl-table-card__head">
            <h2 class="vl-table-card__title">Visitor scans</h2>
            @if($logs->total() > 0)
                <p class="vl-table-card__meta">
                    Showing {{ number_format($logs->firstItem()) }}–{{ number_format($logs->lastItem()) }}
                    of {{ number_format($logs->total()) }}
                </p>
            @endif
        </div>

        <div class="vl-table-wrap">
            <table class="vl-table">
                <thead>
                    <tr>
                        <th>Visitor</th>
                        <th>Organization</th>
                        <th>QR code</th>
                        <th>Status</th>
                        <th>Scanned</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $visitor = $log->visitor;
                            $status = strtoupper((string) $log->status);
                            $scannedAt = $log->scanned_at?->timezone($tz);
                        @endphp
                        <tr>
                            <td>
                                @if($visitor)
                                    <div class="vl-visitor-name">{{ $visitor->lastname }}, {{ $visitor->firstname }}</div>
                                    @if($visitor->purpose)
                                        <div class="vl-visitor-meta">{{ $visitor->purpose }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">Unknown</span>
                                @endif
                            </td>
                            <td data-label="Organization">{{ $visitor?->organization ?? '—' }}</td>
                            <td data-label="QR"><code class="vl-code">{{ $visitor?->qrcode ?? '—' }}</code></td>
                            <td data-label="Status">
                                @if($status === 'IN')
                                    <span class="vl-status vl-status--in">IN</span>
                                @else
                                    <span class="vl-status vl-status--out">OUT</span>
                                @endif
                            </td>
                            <td data-label="Scanned">
                                @if($scannedAt)
                                    <div class="vl-time">
                                        <span class="vl-time__date">{{ $scannedAt->format('M j, Y') }}</span>
                                        <span class="vl-time__clock">{{ $scannedAt->format('g:i A') }}</span>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="vl-empty">No visitor logs match your filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="vl-table-card__foot">
                {{ $logs->withQueryString()->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </section>
</div>
@endsection
