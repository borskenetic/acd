@extends('layouts.sec')

@section('content')
<div class="container mt-4" style="max-width: 820px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">Gate terminal SMS templates</h3>
        <a href="{{ route('sms.page') }}" class="btn btn-outline-secondary btn-sm">Back to SMS blast</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <p class="text-muted small">
        Messages are sent to the student&apos;s <strong>emergency contact number</strong>.
        <code>{name}</code> is the <strong>emergency contact / guardian name</strong> (not the student).
        Elementary/JHS get one SMS per scan event (morning IN, lunch OUT, afternoon IN, EOD OUT).
        SHS/College still use arrival + departure (once each per day).
    </p>

    <form method="POST" action="{{ route('sms.scanMessage.update') }}">
        @csrf

        <h5 class="mt-2 mb-2">Elementary / JHS sessions</h5>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Morning IN</div>
            <div class="card-body">
                <textarea name="morning_in" class="form-control" rows="2" required>{{ $morningIn }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Lunch / half-day OUT</div>
            <div class="card-body">
                <textarea name="lunch_out" class="form-control" rows="2" required>{{ $lunchOut }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Afternoon IN</div>
            <div class="card-body">
                <textarea name="afternoon_in" class="form-control" rows="2" required>{{ $afternoonIn }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">End of day OUT</div>
            <div class="card-body">
                <textarea name="eod_out" class="form-control" rows="2" required>{{ $eodOut }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Missed EOD OUT (automatic at 10:00 PM)</div>
            <div class="card-body">
                <textarea name="missed_eod" class="form-control" rows="2" required>{{ $missedEod }}</textarea>
            </div>
        </div>

        <h5 class="mt-3 mb-2">SHS / College (legacy)</h5>

        <div class="card mb-3">
            <div class="card-header fw-semibold">First scan of the day (arrival)</div>
            <div class="card-body">
                <textarea name="arrival" class="form-control" rows="2" required>{{ $arrival }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Departure (once per day after logout time)</div>
            <div class="card-body">
                <textarea name="departure" class="form-control" rows="2" required>{{ $departure }}</textarea>
            </div>
        </div>

        <h5 class="mt-3 mb-2">Alerts</h5>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Consecutive lates alert</div>
            <div class="card-body">
                <textarea name="consecutive_late" class="form-control" rows="2" required>{{ $consecutiveLate }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Consecutive absences alert</div>
            <div class="card-body">
                <textarea name="consecutive_absent" class="form-control" rows="2" required>{{ $consecutiveAbsent }}</textarea>
                <p class="small text-muted mb-0 mt-2">Checked daily at 4:30 PM for SF2 grade levels (Kinder–Grade 12).</p>
            </div>
        </div>

        <p class="small text-muted">
            Tags: <code>{name}</code> guardian name,
            <code>{child}</code> student name,
            <code>{status}</code> IN/OUT,
            <code>{time}</code> scan time,
            <code>{count}</code> consecutive days (alerts only)
        </p>

        <button type="submit" class="btn btn-primary">Save templates</button>
    </form>
</div>
@endsection
