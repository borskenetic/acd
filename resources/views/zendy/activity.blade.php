@extends('layouts.zen')

@section('page_title', 'My Activity')
@section('page_subtitle', 'Your recent Zendy sessions')

@section('content')
<div class="card-surface" style="margin-bottom: 20px;">
    <form action="{{ route('zendy.activity') }}" method="GET" class="filter-bar">
        <input type="date" name="from_date" class="form-control-app" value="{{ request('from_date') }}">
        <input type="date" name="to_date" class="form-control-app" value="{{ request('to_date') }}">
        <select name="action" class="form-control-app">
            <option value="">All events</option>
            <option value="zendy_launch" {{ request('action') === 'zendy_launch' ? 'selected' : '' }}>Launch</option>
            <option value="go_to_zendy" {{ request('action') === 'go_to_zendy' ? 'selected' : '' }}>Direct link</option>
            <option value="zendy_return" {{ request('action') === 'zendy_return' ? 'selected' : '' }}>Return</option>
            <option value="zendy_tab_close" {{ request('action') === 'zendy_tab_close' ? 'selected' : '' }}>Tab closed</option>
        </select>
        <button type="submit" class="btn-app btn-primary-app">Filter</button>
        <a href="{{ route('zendy.activity') }}" class="btn-app btn-outline-app">Reset</a>
    </form>
</div>

<div class="card-surface" style="padding: 0; overflow: hidden;">
    <div class="table-wrap" style="border: none;">
        <table class="table-app">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date & time</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                <tr>
                    <td><span class="badge-app">{{ $log->actionLabel() }}</span></td>
                    <td style="white-space: nowrap;">{{ $log->created_at->format('M d, Y g:i A') }}</td>
                    <td>
                        @if($log->durationLabel())
                            {{ $log->durationLabel() }}
                        @else
                            <span style="color: var(--text-muted);">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 32px;">
                        No activity yet. <a href="{{ route('zendy.launch') }}">Launch Zendy</a> to get started.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding: 16px 20px; display: flex; justify-content: center;">
        {{ $logs->withQueryString()->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
