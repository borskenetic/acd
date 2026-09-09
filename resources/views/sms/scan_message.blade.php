@extends('layouts.sec')

@section('content')
@php
    $canEditK10 = $canEditK10 ?? true;
    $canEditShs = $canEditShs ?? true;
    $canEditAlerts = $canEditAlerts ?? true;
    $scanSmsEnabled = $scanSmsEnabled ?? [];
    $enabledOld = function (string $event) use ($scanSmsEnabled): string {
        $default = ($scanSmsEnabled[$event] ?? true) ? '1' : '0';

        return (string) old($event.'_enabled', $default);
    };
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
        Half-day OUT and early lunch OUT (before that grade&apos;s lunch time) use their own templates.
        Lunch/afternoon system autofills do not send SMS; missed EOD auto-OUT does notify the guardian.
        SHS/College still use arrival + departure (once each per day).
        Use <strong>Auto SMS on</strong> on each card to enable or disable that automatic message.
    </p>

    <form method="POST" action="{{ route('sms.scanMessage.update') }}">
        @csrf

        @if($canEditK10)
        <h5 class="mt-2 mb-2">Elementary / JHS sessions (K–10)</h5>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Morning IN</span>
                <input type="hidden" name="morning_in_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="morningInEnabled"
                           name="morning_in_enabled" value="1"
                           @checked($enabledOld('morning_in') === '1')>
                    <label class="form-check-label" for="morningInEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="morning_in" class="form-control" rows="2" required>{{ old('morning_in', $morningIn) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Lunch OUT</span>
                <input type="hidden" name="lunch_out_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="lunchOutEnabled"
                           name="lunch_out_enabled" value="1"
                           @checked($enabledOld('lunch_out') === '1')>
                    <label class="form-check-label" for="lunchOutEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="lunch_out" class="form-control" rows="2" required>{{ old('lunch_out', $lunchOut) }}</textarea>
                <p class="small text-muted mb-0 mt-2">Used when the lunch OUT scan is at or after that grade&apos;s lunch OUT time (Grades 1–2: 11:00, Grade 3: 11:15, Grades 4–10: 12:00).</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Half-day OUT</span>
                <input type="hidden" name="half_day_out_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="halfDayOutEnabled"
                           name="half_day_out_enabled" value="1"
                           @checked($enabledOld('half_day_out') === '1')>
                    <label class="form-check-label" for="halfDayOutEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="half_day_out" class="form-control" rows="2" required>{{ old('half_day_out', $halfDayOut) }}</textarea>
                <p class="small text-muted mb-0 mt-2">Kinder every day; Grades 1–10 on Friday morning dismissal. Never uses the lunch/break wording.</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Early OUT (before lunch time)</span>
                <input type="hidden" name="early_out_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="earlyOutEnabled"
                           name="early_out_enabled" value="1"
                           @checked($enabledOld('early_out') === '1')>
                    <label class="form-check-label" for="earlyOutEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="early_out" class="form-control" rows="2" required>{{ old('early_out', $earlyOut) }}</textarea>
                <p class="small text-muted mb-0 mt-2">Used for a lunch OUT scan recorded before that grade&apos;s scheduled lunch OUT time (e.g. offline gate sync).</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Afternoon IN</span>
                <input type="hidden" name="afternoon_in_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="afternoonInEnabled"
                           name="afternoon_in_enabled" value="1"
                           @checked($enabledOld('afternoon_in') === '1')>
                    <label class="form-check-label" for="afternoonInEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="afternoon_in" class="form-control" rows="2" required>{{ old('afternoon_in', $afternoonIn) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>End of day OUT</span>
                <input type="hidden" name="eod_out_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="eodOutEnabled"
                           name="eod_out_enabled" value="1"
                           @checked($enabledOld('eod_out') === '1')>
                    <label class="form-check-label" for="eodOutEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="eod_out" class="form-control" rows="2" required>{{ old('eod_out', $eodOut) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Missed EOD OUT (automatic at 10:00 PM)</span>
                <input type="hidden" name="missed_eod_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="missedEodEnabled"
                           name="missed_eod_enabled" value="1"
                           @checked($enabledOld('missed_eod') === '1')>
                    <label class="form-check-label" for="missedEodEnabled">Auto SMS on</label>
                </div>
            </div>
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
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>First scan of the day (arrival)</span>
                <input type="hidden" name="arrival_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="arrivalEnabled"
                           name="arrival_enabled" value="1"
                           @checked($enabledOld('arrival') === '1')>
                    <label class="form-check-label" for="arrivalEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="arrival" class="form-control" rows="2" required>{{ old('arrival', $arrival) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Departure (once per day after logout time)</span>
                <input type="hidden" name="departure_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="departureEnabled"
                           name="departure_enabled" value="1"
                           @checked($enabledOld('departure') === '1')>
                    <label class="form-check-label" for="departureEnabled">Auto SMS on</label>
                </div>
            </div>
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
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Consecutive lates alert</span>
                <input type="hidden" name="consecutive_late_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="consecutiveLateEnabled"
                           name="consecutive_late_enabled" value="1"
                           @checked((string) old('consecutive_late_enabled', ($consecutiveLateEnabled ?? true) ? '1' : '0') === '1')>
                    <label class="form-check-label" for="consecutiveLateEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="consecutive_late" class="form-control" rows="2" required>{{ old('consecutive_late', $consecutiveLate) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Consecutive absences alert</span>
                <input type="hidden" name="consecutive_absent_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="consecutiveAbsentEnabled"
                           name="consecutive_absent_enabled" value="1"
                           @checked((string) old('consecutive_absent_enabled', ($consecutiveAbsentEnabled ?? true) ? '1' : '0') === '1')>
                    <label class="form-check-label" for="consecutiveAbsentEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="consecutive_absent" class="form-control" rows="2" required>{{ old('consecutive_absent', $consecutiveAbsent) }}</textarea>
                <p class="small text-muted mb-0 mt-2">Checked daily at 4:30 PM for SF2 grade levels (Kinder–Grade 12).</p>
            </div>
        </div>
        @else
        <div class="card mb-3 border-0 bg-light">
            <div class="card-body small text-muted">
                <strong>Consecutive late / absent alert wording</strong> is school-wide
                (superadmin / staff only). Late SMS {{ ($consecutiveLateEnabled ?? true) ? 'on' : 'off' }}:
                “{{ \Illuminate\Support\Str::limit($consecutiveLate, 80) }}”;
                absent SMS {{ ($consecutiveAbsentEnabled ?? true) ? 'on' : 'off' }}:
                “{{ \Illuminate\Support\Str::limit($consecutiveAbsent, 80) }}”.
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
