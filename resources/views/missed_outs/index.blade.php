@extends('layouts.app')

@section('title', 'Missed Outs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/attendance_logs/logs.css') }}">
@endpush

@section('content')
@php
    $query = request()->query();
    $hasFilters = collect($query)->except('page')->filter()->isNotEmpty();
    $tz = config('app.timezone', 'Asia/Manila');
    $today = now($tz)->toDateString();
    $weekStart = now($tz)->startOfWeek()->toDateString();
    $monthStart = now($tz)->startOfMonth()->toDateString();

    $filterUrl = function (array $merge = [], array $except = []) use ($query) {
        $params = collect($query)->except(array_merge(['page'], $except))->merge($merge)->filter(fn ($v) => $v !== null && $v !== '')->all();

        return route('missed_outs.index', $params);
    };

    $isDatePreset = fn (string $preset) => match ($preset) {
        'today' => request('from') === $today && request('to') === $today,
        'week' => request('from') === $weekStart && request('to') === $today,
        'month' => request('from') === $monthStart && request('to') === $today,
        'all' => request('period') === 'all' && ! request('from') && ! request('to'),
        default => false,
    };
@endphp

<div class="data-page attendance-logs-page">
    <header class="al-header">
        <div class="al-header__text">
            <h1 class="al-title">Missed Outs</h1>
            <p class="al-subtitle">
                Students who scanned <strong>IN</strong> but did not scan <strong>OUT</strong> — closed automatically at end of day.
            </p>
        </div>
        <div class="al-header__actions">
            <div class="dropdown">
                <button class="al-btn al-btn--ghost dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end al-export-menu">
                    <li><a class="dropdown-item" href="{{ route('missed_outs.export.excel', $query) }}">Download Excel</a></li>
                    <li><a class="dropdown-item" href="{{ route('missed_outs.export.csv', $query) }}">Download CSV</a></li>
                </ul>
            </div>
        </div>
    </header>

    <div class="al-stats">
        <div class="al-stat-card">
            <span class="al-stat-card__label">Matching records</span>
            <strong class="al-stat-card__value">{{ number_format($summary['total']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--out">
            <span class="al-stat-card__label">Unique students</span>
            <strong class="al-stat-card__value">{{ number_format($summary['unique_students']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--today">
            <span class="al-stat-card__label">Today</span>
            <strong class="al-stat-card__value">{{ number_format($summary['today']) }}</strong>
        </div>
    </div>

    <section class="al-controls" aria-label="Filter missed outs">
        <form method="GET" class="al-controls__form" id="moFilterForm">
            <div class="al-search-row">
                <label class="al-search" for="moSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="moSearch" name="search" value="{{ request('search') }}"
                           placeholder="Search by name or student ID…" autocomplete="off">
                </label>
                <button type="submit" class="al-btn al-btn--primary al-btn--search">Search</button>
            </div>

            <div class="al-control-row">
                <div class="al-control-group">
                    <span class="al-control-group__label">Period</span>
                    <div class="al-pills" role="group" aria-label="Date period">
                        <a href="{{ $filterUrl(['from' => $today, 'to' => $today], ['period']) }}"
                           class="al-pill {{ $isDatePreset('today') ? 'is-active' : '' }}">Today</a>
                        <a href="{{ $filterUrl(['from' => $weekStart, 'to' => $today], ['period']) }}"
                           class="al-pill {{ $isDatePreset('week') ? 'is-active' : '' }}">This week</a>
                        <a href="{{ $filterUrl(['from' => $monthStart, 'to' => $today], ['period']) }}"
                           class="al-pill {{ $isDatePreset('month') ? 'is-active' : '' }}">This month</a>
                        <a href="{{ $filterUrl(['period' => 'all'], ['from', 'to']) }}"
                           class="al-pill {{ $isDatePreset('all') ? 'is-active' : '' }}">All time</a>
                    </div>
                </div>
            </div>

            <details class="al-more-filters" {{ request()->hasAny(['from', 'to', 'year', 'homeroom_section']) ? 'open' : '' }}>
                <summary>More filters</summary>
                <div class="al-more-filters__grid">
                    <div class="al-field">
                        <label for="moFrom">From</label>
                        <input type="date" id="moFrom" name="from" value="{{ request('from') }}">
                    </div>
                    <div class="al-field">
                        <label for="moTo">To</label>
                        <input type="date" id="moTo" name="to" value="{{ request('to') }}">
                    </div>
                    <div class="al-field">
                        <label for="moYear">Grade</label>
                        <select id="moYear" name="year">
                            <option value="">All grades</option>
                            @foreach($yearOptions as $year)
                                <option value="{{ $year }}" @selected(request('year') === $year)>{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="al-field">
                        <label for="moSection">Section</label>
                        <select id="moSection" name="homeroom_section">
                            <option value="">All sections</option>
                            @foreach($homeroomSections as $section)
                                <option value="{{ $section }}" @selected(request('homeroom_section') === $section)>{{ $section }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="al-field al-field--actions">
                        <button type="submit" class="al-btn al-btn--primary">Apply</button>
                        @if($hasFilters)
                            <a href="{{ route('missed_outs.index') }}" class="al-btn al-btn--ghost">Clear all</a>
                        @endif
                    </div>
                </div>
            </details>

            @if(request('period') === 'all')
                <input type="hidden" name="period" value="all">
            @endif
        </form>

        @if($hasFilters)
            <div class="al-active-filters">
                <span class="al-active-filters__label">Active:</span>
                @if(request('search'))
                    <a href="{{ $filterUrl([], ['search']) }}" class="al-tag">Search: {{ request('search') }} <span aria-hidden="true">×</span></a>
                @endif
                @if(request('from') || request('to'))
                    <a href="{{ $filterUrl([], ['from', 'to']) }}" class="al-tag">
                        {{ request('from') ?: '…' }} → {{ request('to') ?: '…' }} <span aria-hidden="true">×</span>
                    </a>
                @endif
                @if(request('year'))
                    <a href="{{ $filterUrl([], ['year']) }}" class="al-tag">Grade: {{ request('year') }} <span aria-hidden="true">×</span></a>
                @endif
                @if(request('homeroom_section'))
                    <a href="{{ $filterUrl([], ['homeroom_section']) }}" class="al-tag">Section: {{ request('homeroom_section') }} <span aria-hidden="true">×</span></a>
                @endif
            </div>
        @endif
    </section>

    <section class="al-table-card">
        <div class="al-table-card__head">
            <div>
                <h2 class="al-table-card__title">Missed checkout records</h2>
                @if($logs->total() > 0)
                    <p class="al-table-card__meta">
                        Showing {{ number_format($logs->firstItem()) }}–{{ number_format($logs->lastItem()) }}
                        of {{ number_format($logs->total()) }}
                    </p>
                @endif
            </div>
        </div>

        <div class="al-table-wrap">
            <table class="al-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Grade</th>
                        <th>Section</th>
                        <th>Last IN</th>
                        <th>Auto OUT</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $student = $log->student;
                            $initials = $student
                                ? strtoupper(substr($student->firstname ?? '', 0, 1).substr($student->lastname ?? '', 0, 1))
                                : '?';
                            $outAt = $log->scanned_at?->timezone($tz);
                            $inAt = $log->last_in_at?->timezone($tz);
                        @endphp
                        <tr>
                            <td>
                                <div class="al-student">
                                    <span class="al-student__avatar" aria-hidden="true">{{ $initials }}</span>
                                    <div>
                                        @if($student)
                                            <div class="al-student__name">{{ $student->lastname }}, {{ $student->firstname }}</div>
                                            @if($student->student_id)
                                                <div class="al-student__meta">{{ $student->student_id }}</div>
                                            @endif
                                        @else
                                            <div class="al-student__name">Unknown student</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>{{ $student->year ?? '—' }}</td>
                            <td>{{ $student->section ?? '—' }}</td>
                            <td>
                                @if($inAt)
                                    <div>{{ $inAt->format('M j, Y') }}</div>
                                    <div class="al-student__meta">{{ $inAt->format('g:i A') }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if($outAt)
                                    <div>{{ $outAt->format('M j, Y') }}</div>
                                    <div class="al-student__meta">{{ $outAt->format('g:i A') }}</div>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="al-empty">No missed outs found for this filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="al-table-card__foot">
                {{ $logs->withQueryString()->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </section>
</div>
@endsection
