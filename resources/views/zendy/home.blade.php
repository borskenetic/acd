@extends('layouts.zen')

@section('page_title', 'Dashboard')
@section('page_subtitle', 'Access scholarly resources through your institution')

@section('content')
<div class="card-grid">
    <div class="action-card primary">
        <div class="action-icon">↗</div>
        <h3>Go to Zendy</h3>
        <p>Browse journals, articles, and e-books with your institutional access.</p>
        <a href="{{ route('zendy.launch') }}" class="btn-app btn-primary-app">Launch Zendy</a>
    </div>

    @cannot('isAdmin')
    <div class="action-card">
        <div class="action-icon">🕐</div>
        <h3>My Activity</h3>
        <p>See when you last launched Zendy and your recent sessions.</p>
        <a href="{{ route('zendy.activity') }}" class="btn-app btn-outline-app">View activity</a>
    </div>
    @else
    <div class="action-card warning">
        <div class="action-icon">📊</div>
        <h3>Usage Reports</h3>
        <p>View activity across campus by course, campus, and date.</p>
        <a href="{{ route('zendy.reports') }}" class="btn-app btn-outline-app">View Reports</a>
    </div>
    @endcannot
</div>

<div class="card-surface">
    <h3 style="margin: 0 0 16px; font-size: 1.1rem;">Your profile</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
        <div>
            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Name</div>
            <div style="font-weight: 600;">{{ auth()->user()->fname }} {{ auth()->user()->lname }}</div>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Email</div>
            <div>{{ auth()->user()->email }}</div>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Role</div>
            <div><span class="badge-app">{{ \App\Models\User::roleOptions()[auth()->user()->role] ?? ucfirst(auth()->user()->role) }}</span></div>
        </div>
        @if(auth()->user()->campus)
        <div>
            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Campus</div>
            <div>{{ auth()->user()->campus }}</div>
        </div>
        @endif
        @if(auth()->user()->course)
        <div>
            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Course</div>
            <div>{{ auth()->user()->course }}</div>
        </div>
        @endif
        @if(auth()->user()->department)
        <div>
            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Department</div>
            <div>{{ auth()->user()->department }}</div>
        </div>
        @endif
    </div>
</div>
@endsection
