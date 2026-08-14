@extends('layouts.app')

@section('title', 'SMS Logs')

@push('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/data-pages.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/attendance_logs/logs.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/sms/logs.css') }}">
@endpush

@section('content')
@php
    $query = request()->query();
    $hasFilters = collect($query)->except('page')->filter()->isNotEmpty();
    $tz = config('app.timezone', 'Asia/Manila');
    $today = now($tz)->toDateString();
    $weekStart = now($tz)->startOfWeek()->toDateString();
    $monthStart = now($tz)->startOfMonth()->toDateString();
    $currentStatus = (string) request('status', '');
    if ($currentStatus === 'sent') {
        $currentStatus = 'success';
    }

    $filterUrl = function (array $merge = [], array $except = []) use ($query) {
        $params = collect($query)->except(array_merge(['page'], $except))->merge($merge)->filter(fn ($v) => $v !== null && $v !== '')->all();

        return route('sms.logs', $params);
    };

    $isDatePreset = fn (string $preset) => match ($preset) {
        'today' => request('from') === $today && request('to') === $today,
        'week' => request('from') === $weekStart && request('to') === $today,
        'month' => request('from') === $monthStart && request('to') === $today,
        'all' => ! request('from') && ! request('to'),
        default => false,
    };
@endphp

<div class="data-page attendance-logs-page sms-log-page">
    <header class="al-header">
        <div class="al-header__text">
            <h1 class="al-title">SMS Logs</h1>
            <p class="al-subtitle">SMS delivery history for blasts and scan notifications.</p>
        </div>
        <div class="al-header__actions">
            <a href="{{ route('sms.page') }}" class="al-btn al-btn--primary">SMS Blast</a>
            <a href="{{ route('sms.scanMessage') }}" class="al-btn al-btn--ghost">Gate templates</a>
        </div>
    </header>

    <div class="al-stats">
        <div class="al-stat-card">
            <span class="al-stat-card__label">Matching</span>
            <strong class="al-stat-card__value">{{ number_format($stats['matching']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--in">
            <span class="al-stat-card__label">Sent</span>
            <strong class="al-stat-card__value">{{ number_format($stats['sent']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--out">
            <span class="al-stat-card__label">Failed / skipped</span>
            <strong class="al-stat-card__value">{{ number_format($stats['failed']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--today">
            <span class="al-stat-card__label">Today</span>
            <strong class="al-stat-card__value">{{ number_format($stats['today']) }}</strong>
        </div>
    </div>

    <section class="al-controls" aria-label="Filter SMS logs">
        <form method="GET" class="al-controls__form">
            <div class="al-search-row">
                <label class="al-search" for="smsSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="smsSearch" name="search" value="{{ request('search') }}"
                           placeholder="Number, contact, student, or message..." autocomplete="off">
                </label>
                <button type="submit" class="al-btn al-btn--primary al-btn--search">Search</button>
            </div>

            <div class="al-control-row">
                <div class="al-control-group">
                    <span class="al-control-group__label">Period</span>
                    <div class="al-pills" role="group" aria-label="Date period">
                        <a href="{{ $filterUrl(['from' => $today, 'to' => $today]) }}"
                           class="al-pill {{ $isDatePreset('today') ? 'is-active' : '' }}">Today</a>
                        <a href="{{ $filterUrl(['from' => $weekStart, 'to' => $today]) }}"
                           class="al-pill {{ $isDatePreset('week') ? 'is-active' : '' }}">This week</a>
                        <a href="{{ $filterUrl(['from' => $monthStart, 'to' => $today]) }}"
                           class="al-pill {{ $isDatePreset('month') ? 'is-active' : '' }}">This month</a>
                        <a href="{{ $filterUrl([], ['from', 'to']) }}"
                           class="al-pill {{ $isDatePreset('all') ? 'is-active' : '' }}">All time</a>
                    </div>
                </div>

                <div class="al-control-group">
                    <span class="al-control-group__label">Status</span>
                    <div class="al-pills" role="group" aria-label="Delivery status">
                        <a href="{{ $filterUrl([], ['status']) }}"
                           class="al-pill {{ $currentStatus === '' ? 'is-active' : '' }}">All</a>
                        <a href="{{ $filterUrl(['status' => 'success']) }}"
                           class="al-pill al-pill--sent {{ $currentStatus === 'success' ? 'is-active' : '' }}">Sent</a>
                        <a href="{{ $filterUrl(['status' => 'failed']) }}"
                           class="al-pill al-pill--failed {{ $currentStatus === 'failed' ? 'is-active' : '' }}">Failed</a>
                        <a href="{{ $filterUrl(['status' => 'skipped']) }}"
                           class="al-pill al-pill--skipped {{ $currentStatus === 'skipped' ? 'is-active' : '' }}">Skipped</a>
                    </div>
                </div>
            </div>

            <details class="al-more-filters" {{ request()->hasAny(['from', 'to', 'type']) ? 'open' : '' }}>
                <summary>More filters</summary>
                <div class="al-more-filters__grid">
                    <div class="al-field">
                        <label for="smsFrom">From</label>
                        <input type="date" id="smsFrom" name="from" value="{{ request('from') }}">
                    </div>
                    <div class="al-field">
                        <label for="smsTo">To</label>
                        <input type="date" id="smsTo" name="to" value="{{ request('to') }}">
                    </div>
                    <div class="al-field">
                        <label for="smsType">Source</label>
                        <select id="smsType" name="type">
                            <option value="">All sources</option>
                            @foreach($typeOptions as $type)
                                <option value="{{ $type }}" @selected(request('type') === $type)>
                                    {{ $typeLabels[$type] ?? $type }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="al-field al-field--actions">
                        <button type="submit" class="al-btn al-btn--primary">Apply</button>
                        @if($hasFilters)
                            <a href="{{ route('sms.logs') }}" class="al-btn al-btn--ghost">Clear</a>
                        @endif
                    </div>
                </div>
            </details>

            @if($currentStatus !== '')
                <input type="hidden" name="status" value="{{ $currentStatus }}">
            @endif
        </form>
    </section>

    <section class="al-table-card">
        <div class="al-table-wrap">
            <table class="al-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>To</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php $at = $log->created_at?->timezone($tz); @endphp
                        <tr>
                            <td data-label="When">
                                @if($at)
                                    <div class="al-time">
                                        <span class="al-time__date">{{ $at->format('M j, Y') }}</span>
                                        <span class="al-time__clock">{{ $at->format('g:i:s A') }}</span>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="To">
                                <div class="al-user">
                                    <strong>{{ $log->to_number ?: '—' }}</strong>
                                    @if($log->recipient_label)
                                        <span class="al-muted" style="font-style:normal;display:block;margin-top:0.15rem;">
                                            {{ $log->recipient_label }}
                                        </span>
                                    @endif
                                    @if($log->student)
                                        <span class="al-meta">
                                            Student: {{ trim($log->student->firstname.' '.$log->student->lastname) }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td data-label="Type">
                                <code class="al-code">{{ $log->typeLabel() }}</code>
                                @if($log->user)
                                    <div class="al-meta">
                                        By {{ trim(($log->user->fname ?? '').' '.($log->user->lname ?? '')) ?: $log->user->email }}
                                    </div>
                                @endif
                            </td>
                            <td data-label="Message">
                                <div class="al-summary" style="font-weight:500;white-space:pre-wrap;">{{ $log->message }}</div>
                                @if($log->error)
                                    <div class="al-meta" style="color:#b91c1c;margin-top:0.35rem;">
                                        {{ $log->error }}
                                    </div>
                                @endif
                            </td>
                            <td data-label="Status">
                                @if($log->status === 'success')
                                    <span class="al-status al-status--in">Sent</span>
                                @elseif($log->status === 'skipped')
                                    <span class="al-status al-status--muted">Skipped</span>
                                @else
                                    <span class="al-status al-status--out">Failed</span>
                                @endif
                                @if($log->http_status)
                                    <div class="al-meta">HTTP {{ $log->http_status }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="al-empty">
                                    No SMS attempts match these filters.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="al-table-card__foot">
                {{ $logs->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </section>
</div>
@endsection
