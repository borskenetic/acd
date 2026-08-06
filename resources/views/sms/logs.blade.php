@extends('layouts.app')

@section('title', 'SMS Logs')

@push('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/data-pages.css') }}">
@endpush

@section('content')
@php
    $query = request()->query();
    $hasFilters = collect($query)->except('page')->filter()->isNotEmpty();
    $tz = config('app.timezone', 'Asia/Manila');
@endphp

<div class="data-page activity-log-page sms-log-page">
    <header class="act-header">
        <div>
            <h1 class="act-title">SMS Logs</h1>
            <p class="act-subtitle">
                History of modem SMS attempts (blast, gate arrival/departure, consecutive alerts).
                Use this to verify messages are reaching the modem.
            </p>
        </div>
        <div class="act-header__actions" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <a href="{{ route('sms.page') }}" class="act-btn act-btn--ghost">SMS Blast</a>
            <a href="{{ route('sms.scanMessage') }}" class="act-btn act-btn--ghost">Gate templates</a>
        </div>
    </header>

    <section class="act-controls" style="margin-bottom:1rem;">
        <div class="act-filters" style="align-items:stretch;">
            <div class="act-field">
                <label>Today</label>
                <div style="font-size:1.25rem;font-weight:700;color:#0f172a;">{{ number_format($stats['today']) }}</div>
            </div>
            <div class="act-field">
                <label>Success (all time)</label>
                <div style="font-size:1.25rem;font-weight:700;color:#15803d;">{{ number_format($stats['success']) }}</div>
            </div>
            <div class="act-field">
                <label>Failed (all time)</label>
                <div style="font-size:1.25rem;font-weight:700;color:#b91c1c;">{{ number_format($stats['failed']) }}</div>
            </div>
            <div class="act-field">
                <label>Skipped (all time)</label>
                <div style="font-size:1.25rem;font-weight:700;color:#64748b;">{{ number_format($stats['skipped']) }}</div>
            </div>
        </div>
    </section>

    <section class="act-controls">
        <form method="GET" class="act-controls__form">
            <div class="act-search-row">
                <label class="act-search" for="smsSearch">
                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="smsSearch" name="search" value="{{ request('search') }}"
                           placeholder="Search number, message, recipient, or error..." autocomplete="off">
                </label>
                <button type="submit" class="act-btn act-btn--primary">Search</button>
            </div>

            <div class="act-filters">
                <div class="act-field">
                    <label for="smsStatus">Status</label>
                    <select id="smsStatus" name="status">
                        <option value="">All statuses</option>
                        <option value="success" @selected(request('status') === 'success')>Success</option>
                        <option value="failed" @selected(request('status') === 'failed')>Failed</option>
                        <option value="skipped" @selected(request('status') === 'skipped')>Skipped</option>
                    </select>
                </div>
                <div class="act-field">
                    <label for="smsType">Type</label>
                    <select id="smsType" name="type">
                        <option value="">All types</option>
                        @foreach($typeOptions as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>
                                {{ $typeLabels[$type] ?? $type }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="act-field">
                    <label for="smsFrom">From</label>
                    <input type="date" id="smsFrom" name="from" value="{{ request('from') }}">
                </div>
                <div class="act-field">
                    <label for="smsTo">To</label>
                    <input type="date" id="smsTo" name="to" value="{{ request('to') }}">
                </div>
                <div class="act-field act-field--actions">
                    <button type="submit" class="act-btn act-btn--primary">Apply</button>
                    @if($hasFilters)
                        <a href="{{ route('sms.logs') }}" class="act-btn act-btn--ghost">Clear</a>
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
                                    <div class="act-time">
                                        <span>{{ $at->format('M j, Y') }}</span>
                                        <span class="act-time__clock">{{ $at->format('g:i:s A') }}</span>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="To">
                                <div class="act-user">
                                    <strong>{{ $log->to_number ?: '—' }}</strong>
                                    @if($log->recipient_label)
                                        <span class="act-muted" style="font-style:normal;display:block;margin-top:0.15rem;">
                                            {{ $log->recipient_label }}
                                        </span>
                                    @endif
                                    @if($log->student)
                                        <span class="act-meta">
                                            Student: {{ trim($log->student->firstname.' '.$log->student->lastname) }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td data-label="Type">
                                <code class="act-code">{{ $log->typeLabel() }}</code>
                                @if($log->user)
                                    <div class="act-meta">
                                        By {{ trim(($log->user->fname ?? '').' '.($log->user->lname ?? '')) ?: $log->user->email }}
                                    </div>
                                @endif
                            </td>
                            <td data-label="Message">
                                <div class="act-summary" style="font-weight:500;white-space:pre-wrap;">{{ $log->message }}</div>
                                @if($log->error)
                                    <div class="act-meta" style="color:#b91c1c;margin-top:0.35rem;">
                                        {{ $log->error }}
                                    </div>
                                @endif
                            </td>
                            <td data-label="Status">
                                @if($log->status === 'success')
                                    <span class="act-status act-status--ok">Success</span>
                                @elseif($log->status === 'skipped')
                                    <span class="act-status" style="background:#f1f5f9;color:#475569;">Skipped</span>
                                @else
                                    <span class="act-status act-status--error">Failed</span>
                                @endif
                                @if($log->http_status)
                                    <div class="act-meta">HTTP {{ $log->http_status }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="act-empty">
                                    No SMS attempts logged yet. Sends from SMS Blast and gate notifications will appear here.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="act-pagination">
                {{ $logs->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </section>
</div>
@endsection
