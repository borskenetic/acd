@extends('layouts.zen')

@section('page_title', 'Activity Logs')
@section('page_subtitle', 'Search and filter activity events')

@section('content')
<form action="{{ route('zendy.logs') }}" method="GET" class="card-surface" style="margin-bottom: 20px;">
    <div class="filter-bar">
        <input type="text" name="search_name" class="form-control-app" placeholder="Search name..." value="{{ request('search_name') }}" style="flex: 1; min-width: 140px;">
        <input type="text" name="search_course" class="form-control-app" placeholder="Course" value="{{ request('search_course') }}" style="flex: 1; min-width: 120px;">
        <input type="text" name="search_campus" class="form-control-app" placeholder="Campus" value="{{ request('search_campus') }}" style="flex: 1; min-width: 120px;">
        <input type="date" name="from_date" class="form-control-app" value="{{ request('from_date') }}">
        <input type="date" name="to_date" class="form-control-app" value="{{ request('to_date') }}">
        <select name="action" class="form-control-app">
            <option value="">All events</option>
            <option value="zendy_launch" {{ request('action') === 'zendy_launch' ? 'selected' : '' }}>Launch</option>
            <option value="go_to_zendy" {{ request('action') === 'go_to_zendy' ? 'selected' : '' }}>Direct link</option>
            <option value="zendy_return" {{ request('action') === 'zendy_return' ? 'selected' : '' }}>Return</option>
            <option value="zendy_tab_close" {{ request('action') === 'zendy_tab_close' ? 'selected' : '' }}>Tab closed</option>
            <option value="zendy_form_submission" {{ request('action') === 'zendy_form_submission' ? 'selected' : '' }}>Form</option>
            <option value="zendy_sso" {{ request('action') === 'zendy_sso' ? 'selected' : '' }}>Sign-on</option>
        </select>
        <button type="submit" class="btn-app btn-primary-app">Filter</button>
        <a href="{{ route('zendy.logs') }}" class="btn-app btn-outline-app">Reset</a>
    </div>
</form>

<div class="card-surface" style="padding: 0; overflow: hidden;">
    <div class="table-wrap" style="border: none; border-radius: 0;">
        <table class="table-app">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Actor</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Course</th>
                    <th>Department</th>
                    <th>Campus</th>
                    <th>Duration</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                <tr>
                    <td>{{ $log->id }}</td>
                    <td>
                        @php
                            $actorName = trim(implode(' ', array_filter([
                                optional($log->actor)->fname,
                                optional($log->actor)->lname
                            ])));
                        @endphp
                        {{ $actorName !== '' ? $actorName : ($log->email ?? '—') }}
                    </td>
                    <td>{{ $log->actor_role ?? optional($log->actor)->role ?? '—' }}</td>
                    <td><span class="badge-app">{{ $log->actionLabel() }}</span></td>
                    <td>{{ trim(($log->first_name ?? '') . ' ' . ($log->last_name ?? '')) ?: '—' }}</td>
                    <td>{{ $log->email ?? '—' }}</td>
                    <td>{{ $log->course ?? '—' }}</td>
                    <td>{{ $log->department ?? '—' }}</td>
                    <td>{{ $log->campus ?? '—' }}</td>
                    <td>{{ $log->durationLabel() ?? '—' }}</td>
                    <td style="white-space: nowrap;">{{ $log->created_at->format('M d, Y H:i') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="11" style="text-align: center; color: var(--text-muted); padding: 32px;">No activity found.</td>
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
