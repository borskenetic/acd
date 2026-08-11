@extends('layouts.sec')

@section('content')
@php
    $canEditK10 = $canEditK10 ?? true;
    $canEditShs = $canEditShs ?? true;
    $canEditAlerts = $canEditAlerts ?? true;
@endphp
<div class="container mt-4" style="max-width: 820px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h3 class="mb-0">Gate terminal SMS templates</h3>
            @if($scopeLabel ?? null)
                <p class="text-muted small mb-0">{{ $scopeLabel }}</p>
            @endif
        </div>
        <a href="{{ route('sms.page') }}" class="btn btn-outline-secondary btn-sm">Back to SMS blast</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="text-muted small">
        Messages are sent to the student&apos;s <strong>emergency contact number</strong>.
        <code>{name}</code> is the <strong>emergency contact / guardian name</strong> (not the student).
        Elementary/JHS get one SMS per real gate scan (morning IN, lunch OUT, afternoon IN, EOD OUT).
        Lunch/afternoon system autofills do not send SMS; missed EOD auto-OUT does notify the guardian.
        SHS/College still use arrival + departure (once each per day).
    </p>

    <form method="POST" action="{{ route('sms.scanMessage.update') }}">
        @csrf

        @if($canEditK10)
        <h5 class="mt-2 mb-2">Elementary / JHS sessions (K–10)</h5>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Morning IN</div>
            <div class="card-body">
                <textarea name="morning_in" class="form-control" rows="2" required>{{ old('morning_in', $morningIn) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Lunch / half-day OUT</div>
            <div class="card-body">
                <textarea name="lunch_out" class="form-control" rows="2" required>{{ old('lunch_out', $lunchOut) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Afternoon IN</div>
            <div class="card-body">
                <textarea name="afternoon_in" class="form-control" rows="2" required>{{ old('afternoon_in', $afternoonIn) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">End of day OUT</div>
            <div class="card-body">
                <textarea name="eod_out" class="form-control" rows="2" required>{{ old('eod_out', $eodOut) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Missed EOD OUT (automatic at 10:00 PM)</div>
            <div class="card-body">
                <textarea name="missed_eod" class="form-control" rows="2" required>{{ old('missed_eod', $missedEod) }}</textarea>
            </div>
        </div>
        @elseif($canEditShs)
        <div class="card mb-3 border-0 bg-light">
            <div class="card-body small text-muted">
                <strong>K–10 session templates</strong> are read-only for SHS Admin.
                Managed by K–10 Admin or superadmin / staff.
            </div>
        </div>
        @endif

        @if($canEditShs)
        <h5 class="mt-3 mb-2">SHS / College (arrival &amp; departure)</h5>

        <div class="card mb-3">
            <div class="card-header fw-semibold">First scan of the day (arrival)</div>
            <div class="card-body">
                <textarea name="arrival" class="form-control" rows="2" required>{{ old('arrival', $arrival) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Departure (once per day after logout time)</div>
            <div class="card-body">
                <textarea name="departure" class="form-control" rows="2" required>{{ old('departure', $departure) }}</textarea>
            </div>
        </div>
        @elseif($canEditK10)
        <div class="card mb-3 border-0 bg-light">
            <div class="card-body small text-muted">
                <strong>SHS arrival / departure templates</strong> are read-only for K–10 Admin.
                Managed by SHS Admin or superadmin / staff.
            </div>
        </div>
        @endif

        @if($canEditAlerts)
        <h5 class="mt-3 mb-2">Alerts (school-wide)</h5>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Consecutive lates alert</div>
            <div class="card-body">
                <textarea name="consecutive_late" class="form-control" rows="2" required>{{ old('consecutive_late', $consecutiveLate) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Consecutive absences alert</div>
            <div class="card-body">
                <textarea name="consecutive_absent" class="form-control" rows="2" required>{{ old('consecutive_absent', $consecutiveAbsent) }}</textarea>
                <p class="small text-muted mb-0 mt-2">Checked daily at 4:30 PM for SF2 grade levels (Kinder–Grade 12).</p>
            </div>
        </div>
        @else
        <div class="card mb-3 border-0 bg-light">
            <div class="card-body small text-muted">
                <strong>Consecutive late / absent alert wording</strong> is school-wide
                (superadmin / staff only). Current late: “{{ \Illuminate\Support\Str::limit($consecutiveLate, 80) }}”;
                absent: “{{ \Illuminate\Support\Str::limit($consecutiveAbsent, 80) }}”.
            </div>
        </div>
        @endif

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
