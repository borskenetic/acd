@extends('layouts.zen')

@section('page_title', 'Reports')
@section('page_subtitle', 'Usage analytics and trends — filter by date, course, campus, or event')

@section('content')
@php
    $query = request()->query();
@endphp

<form action="{{ route('zendy.reports') }}" method="GET" class="card-surface" style="margin-bottom: 20px;">
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
        <a href="{{ route('zendy.reports') }}" class="btn-app btn-outline-app">Reset</a>
        <a href="{{ route('zendy.reports.export', $query) }}" class="btn-app btn-outline-app">Download CSV</a>
    </div>
</form>

<div class="card-grid">
    <div class="stat-card">
        <div class="stat-label">Total Launches</div>
        <div class="stat-value">{{ number_format($totalLaunches) }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Unique Users</div>
        <div class="stat-value">{{ number_format($uniqueUsers) }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Estimated Returns</div>
        <div class="stat-value">{{ number_format($estimatedReturns) }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg. Time Away</div>
        <div class="stat-value" style="font-size: 1.4rem;">
            @if($avgDuration)
                {{ gmdate('H:i:s', (int) $avgDuration) }}
            @else
                —
            @endif
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 20px;">
    <div class="chart-card">
        <h4>Launches by Course</h4>
        <canvas id="courseChart" height="200"></canvas>
    </div>
    <div class="chart-card">
        <h4>Launches by Campus</h4>
        <canvas id="campusChart" height="200"></canvas>
    </div>
    <div class="chart-card">
        <h4>By Event Type</h4>
        <canvas id="actionChart" height="200"></canvas>
    </div>
</div>

<div class="chart-card">
    <h4>Launches Over Time</h4>
    <canvas id="timeChart" height="100"></canvas>
</div>
@endsection

@section('footer')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const chartColors = ['#2563eb', '#7c3aed', '#16a34a', '#d97706', '#dc2626', '#0891b2'];

    new Chart(document.getElementById('courseChart'), {
        type: 'bar',
        data: {
            labels: @json($submissionsByCourse->pluck('course')),
            datasets: [{
                data: @json($submissionsByCourse->pluck('total')),
                backgroundColor: chartColors,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    new Chart(document.getElementById('campusChart'), {
        type: 'doughnut',
        data: {
            labels: @json($submissionsByCampus->pluck('campus')),
            datasets: [{ data: @json($submissionsByCampus->pluck('total')), backgroundColor: chartColors }]
        },
        options: { responsive: true }
    });

    new Chart(document.getElementById('actionChart'), {
        type: 'bar',
        data: {
            labels: @json($submissionsByAction->pluck('action')),
            datasets: [{
                data: @json($submissionsByAction->pluck('total')),
                backgroundColor: chartColors,
                borderRadius: 6,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    new Chart(document.getElementById('timeChart'), {
        type: 'line',
        data: {
            labels: @json($submissionsOverTime->pluck('date')),
            datasets: [{
                label: 'Launches',
                data: @json($submissionsOverTime->pluck('total')),
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.12)',
                fill: true,
                tension: 0.35,
                pointRadius: 3,
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
</script>
@endsection
