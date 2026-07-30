@extends('layouts.app')

@section('title', 'Attendance Logs')

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
    $currentClassification = strtoupper((string) (request('classification') ?: request('status', '')));
    $lateCutoffDisplay = \Carbon\Carbon::today($tz)
        ->setTimeFromTimeString($policy->loginTime())
        ->addMinutes($policy->tardyGraceMinutes())
        ->format('g:i A');
    $grade12LateCutoffDisplay = \Carbon\Carbon::today($tz)
        ->setTimeFromTimeString($policy->loginTime('Grade 12'))
        ->addMinutes($policy->tardyGraceMinutes())
        ->format('g:i A');

    $filterUrl = function (array $merge = [], array $except = []) use ($query) {
        $params = collect($query)->except(array_merge(['page'], $except))->merge($merge)->filter(fn ($v) => $v !== null && $v !== '')->all();

        return route('attendance_logs.index', $params);
    };

    $isDatePreset = fn (string $preset) => match ($preset) {
        'today' => request('from') === $today && request('to') === $today,
        'week' => request('from') === $weekStart && request('to') === $today,
        'month' => request('from') === $monthStart && request('to') === $today,
        'all' => ! request('from') && ! request('to'),
        default => false,
    };
@endphp

<div class="data-page attendance-logs-page">
    <header class="al-header">
        <div class="al-header__text">
            <h1 class="al-title">Attendance Logs</h1>
            <p class="al-subtitle">Gate terminal scan history — LATE applies only to each student’s first IN of the day (after {{ $lateCutoffDisplay }}; Grade 12 half-day after {{ $grade12LateCutoffDisplay }}). Afternoon returns stay IN.</p>
        </div>
        <div class="al-header__actions">
            <a href="{{ route('attendance.scan') }}" target="_blank" rel="noopener" class="al-btn al-btn--primary">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3H4a1 1 0 0 0-1 1v3"/><path d="M17 3h3a1 1 0 0 1 1 1v3"/><path d="M7 21H4a1 1 0 0 1-1-1v-3"/><path d="M17 21h3a1 1 0 0 0 1-1v-3"/><path d="M8 12h8"/></svg>
                Gate Terminal
            </a>
            <a href="{{ route('attendance_logs.reports.hub') }}" class="al-btn al-btn--ghost">Reports</a>
            <div class="dropdown">
                <button class="al-btn al-btn--ghost dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end al-export-menu">
                    <li><a class="dropdown-item" href="{{ route('attendance_logs.export.excel', $query) }}">Download Excel</a></li>
                    <li><a class="dropdown-item" href="{{ route('attendance_logs.export.pdf', $query) }}">Download PDF</a></li>
                </ul>
            </div>
        </div>
    </header>

    <div class="al-stats">
        <div class="al-stat-card">
            <span class="al-stat-card__label">Matching records</span>
            <strong class="al-stat-card__value">{{ number_format($summary['total']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--in">
            <span class="al-stat-card__label">On time</span>
            <strong class="al-stat-card__value">{{ number_format($summary['in']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--late">
            <span class="al-stat-card__label">Late</span>
            <strong class="al-stat-card__value">{{ number_format($summary['late']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--out">
            <span class="al-stat-card__label">Check-outs</span>
            <strong class="al-stat-card__value">{{ number_format($summary['out']) }}</strong>
        </div>
        <div class="al-stat-card al-stat-card--today">
            <span class="al-stat-card__label">Today</span>
            <strong class="al-stat-card__value">{{ number_format($summary['today']) }}</strong>
        </div>
    </div>

    <section class="al-controls" aria-label="Filter attendance logs">
        <form method="GET" class="al-controls__form" id="alFilterForm">
            <div class="al-search-row">
                <label class="al-search" for="alSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="alSearch" name="search" value="{{ request('search') }}"
                           placeholder="Search by name or student ID…" autocomplete="off">
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
                    <div class="al-pills" role="group" aria-label="Scan status">
                        <a href="{{ $filterUrl([], ['classification', 'status']) }}"
                           class="al-pill {{ $currentClassification === '' ? 'is-active' : '' }}">All</a>
                        <a href="{{ $filterUrl(['classification' => 'IN']) }}"
                           class="al-pill al-pill--in {{ $currentClassification === 'IN' ? 'is-active' : '' }}">On time</a>
                        <a href="{{ $filterUrl(['classification' => 'LATE']) }}"
                           class="al-pill al-pill--late {{ $currentClassification === 'LATE' ? 'is-active' : '' }}">Late</a>
                        <a href="{{ $filterUrl(['classification' => 'OUT']) }}"
                           class="al-pill al-pill--out {{ $currentClassification === 'OUT' ? 'is-active' : '' }}">OUT</a>
                    </div>
                </div>
            </div>

            <details class="al-more-filters" {{ request()->hasAny(['from', 'to', 'year', 'homeroom_section']) ? 'open' : '' }}>
                <summary>More filters</summary>
                <div class="al-more-filters__grid">
                    <div class="al-field">
                        <label for="alFrom">From</label>
                        <input type="date" id="alFrom" name="from" value="{{ request('from') }}">
                    </div>
                    <div class="al-field">
                        <label for="alTo">To</label>
                        <input type="date" id="alTo" name="to" value="{{ request('to') }}">
                    </div>
                    <div class="al-field">
                        <label for="alYear">Grade</label>
                        <select id="alYear" name="year">
                            <option value="">All grades</option>
                            @foreach($yearOptions as $year)
                                <option value="{{ $year }}" @selected(request('year') === $year)>{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="al-field">
                        <label for="alSection">Section</label>
                        <select id="alSection" name="homeroom_section">
                            <option value="">All sections</option>
                            @foreach($homeroomSections as $section)
                                <option value="{{ $section }}" @selected(request('homeroom_section') === $section)>{{ $section }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="al-field al-field--actions">
                        <button type="submit" class="al-btn al-btn--primary">Apply filters</button>
                        @if($hasFilters)
                            <a href="{{ route('attendance_logs.index') }}" class="al-btn al-btn--ghost">Clear all</a>
                        @endif
                    </div>
                </div>
            </details>

            @if($currentClassification !== '')
                <input type="hidden" name="classification" value="{{ $currentClassification }}">
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
                @if($currentClassification !== '')
                    <a href="{{ $filterUrl([], ['classification', 'status']) }}" class="al-tag">Status: {{ $currentClassification === 'IN' ? 'On time' : $currentClassification }} <span aria-hidden="true">×</span></a>
                @endif
            </div>
        @endif
    </section>

    <section class="al-table-card">
        <div class="al-table-card__head">
            <div>
                <h2 class="al-table-card__title">Scan records</h2>
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
                        <th>Status</th>
                        <th>Scanned</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $student = $log->student;
                            $classification = $policy->classifyLog($log) ?? strtoupper((string) $log->status);
                            $initials = $student
                                ? strtoupper(substr($student->firstname ?? '', 0, 1).substr($student->lastname ?? '', 0, 1))
                                : '?';
                            $scannedAt = $log->scanned_at?->timezone($tz);
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
                                            <span class="al-student__unknown">Unknown student</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td data-label="Grade">{{ $student?->year ?? '—' }}</td>
                            <td data-label="Section">{{ $student?->section ?? '—' }}</td>
                            <td data-label="Status">
                                @if($classification === 'IN')
                                    <span class="al-status al-status--in">IN</span>
                                @elseif($classification === 'LATE')
                                    <span class="al-status al-status--late">LATE</span>
                                @elseif($classification === 'OUT')
                                    <span class="al-status al-status--out">OUT</span>
                                @else
                                    <span class="al-status al-status--muted">{{ $classification ?: '—' }}</span>
                                @endif
                            </td>
                            <td data-label="Scanned">
                                @if($scannedAt)
                                    <div class="al-time">
                                        <span class="al-time__date">{{ $scannedAt->format('M j, Y') }}</span>
                                        <span class="al-time__clock">{{ $scannedAt->format('g:i A') }}</span>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="al-empty">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
                                    <p class="al-empty__title">No records found</p>
                                    <p class="al-empty__text">Try a different date range or clear your filters.</p>
                                    @if($hasFilters)
                                        <a href="{{ route('attendance_logs.index') }}" class="al-btn al-btn--ghost">Clear filters</a>
                                    @endif
                                </div>
                            </td>
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
