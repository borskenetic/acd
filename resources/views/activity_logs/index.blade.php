@extends('layouts.app')

@section('title', 'Activity Log')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/activity_logs/index.css') }}">
@endpush

@section('content')
@php
    $query = request()->query();
    $hasFilters = collect($query)->except('page')->filter()->isNotEmpty();
    $tz = config('app.timezone', 'Asia/Manila');
@endphp

<div class="data-page activity-log-page">
    <header class="act-header">
        <div>
            <h1 class="act-title">Activity Log</h1>
            <p class="act-subtitle">Audit trail of staff and admin actions on the platform.</p>
        </div>
    </header>

    <section class="act-controls">
        <form method="GET" class="act-controls__form">
            <div class="act-search-row">
                <label class="act-search" for="actSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="actSearch" name="search" value="{{ request('search') }}"
                           placeholder="Search summary, user, or URL…" autocomplete="off">
                </label>
                <button type="submit" class="act-btn act-btn--primary">Search</button>
            </div>

            <div class="act-filters">
                <div class="act-field">
                    <label for="actUser">User</label>
                    <select id="actUser" name="user_id">
                        <option value="">All users</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>
                                {{ trim($user->fname.' '.$user->lname) ?: $user->email }} ({{ $user->role }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="act-field">
                    <label for="actRole">Role</label>
                    <select id="actRole" name="role">
                        <option value="">All roles</option>
                        @foreach(['admin', 'staff', 'faculty', 'student'] as $role)
                            <option value="{{ $role }}" @selected(request('role') === $role)>{{ ucfirst($role) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="act-field">
                    <label for="actAction">Action</label>
                    <select id="actAction" name="action">
                        <option value="">All actions</option>
                        @foreach($actionOptions as $action)
                            <option value="{{ $action }}" @selected(request('action') === $action)>
                                {{ $actionLabels[$action] ?? $action }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="act-field">
                    <label for="actFrom">From</label>
                    <input type="date" id="actFrom" name="from" value="{{ request('from') }}">
                </div>
                <div class="act-field">
                    <label for="actTo">To</label>
                    <input type="date" id="actTo" name="to" value="{{ request('to') }}">
                </div>
                <div class="act-field act-field--actions">
                    <button type="submit" class="act-btn act-btn--primary">Apply</button>
                    @if($hasFilters)
                        <a href="{{ route('activity_logs.index') }}" class="act-btn act-btn--ghost">Clear</a>
                    @endif
                </div>
            </div>
        </form>
    </section>

    <section class="act-table-card">
        <div class="act-table-wrap">
            <table class="act-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php $at = $log->created_at?->timezone($tz); @endphp
                        <tr>
                            <td data-label="When">
                                @if($at)
                                    <div class="act-time">
                                        <span>{{ $at->format('M j, Y') }}</span>
                                        <span class="act-time__clock">{{ $at->format('g:i:s A') }}</span>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="User">
                                @if($log->user_name)
                                    <div class="act-user">
                                        <strong>{{ $log->user_name }}</strong>
                                        @if($log->user_role)
                                            <span class="act-role act-role--{{ $log->user_role }}">{{ $log->user_role }}</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="act-muted">Guest / kiosk</span>
                                @endif
                            </td>
                            <td data-label="Action">
                                <code class="act-code">{{ $log->action }}</code>
                            </td>
                            <td data-label="Details">
                                <div class="act-summary">{{ $log->summary }}</div>
                                <div class="act-meta">{{ $log->method }} · {{ Str::limit($log->url, 80) }}</div>
                                @if($log->properties)
                                    <details class="act-details">
                                        <summary>Request data</summary>
                                        <pre>{{ json_encode($log->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td data-label="Status">
                                @if($log->status_code)
                                    <span class="act-status act-status--{{ $log->status_code >= 400 ? 'error' : 'ok' }}">
                                        {{ $log->status_code }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="act-empty">No activity recorded yet.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="act-pagination">{{ $logs->links() }}</div>
        @endif
    </section>
</div>
@endsection
