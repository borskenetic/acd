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
        Messages are sent to the student&apos;s <strong>emergency contact number</strong> (parent/guardian), not the student&apos;s mobile.
        Arrival SMS is sent once on the first scan of the day; departure SMS once on the first scan at or after 4:00 PM.
    </p>

    <form method="POST" action="{{ route('sms.scanMessage.update') }}">
        @csrf

        <div class="card mb-3">
            <div class="card-header fw-semibold">First scan of the day (arrival)</div>
            <div class="card-body">
                <textarea name="arrival" class="form-control" rows="3" required>{{ $arrival }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">After 4:00 PM (departure — once per day)</div>
            <div class="card-body">
                <textarea name="departure" class="form-control" rows="3" required>{{ $departure }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">5 consecutive lates alert</div>
            <div class="card-body">
                <textarea name="consecutive_late" class="form-control" rows="3" required>{{ $consecutiveLate }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">3 consecutive absences alert</div>
            <div class="card-body">
                <textarea name="consecutive_absent" class="form-control" rows="3" required>{{ $consecutiveAbsent }}</textarea>
                <p class="small text-muted mb-0 mt-2">Checked daily at 4:30 PM for SF2 grade levels (Kinder–Grade 12).</p>
            </div>
        </div>

        <p class="small text-muted">
            Tags: <code>{name}</code> student name,
            <code>{status}</code> IN/OUT,
            <code>{time}</code> scan time,
            <code>{count}</code> consecutive days (alerts only)
        </p>

        <button type="submit" class="btn btn-primary">Save templates</button>
    </form>
</div>
@endsection
