@extends('layouts.sec')

@section('content')
@php
    $tz = $policy->timezone();
    $loginDisplay = \Carbon\Carbon::today($tz)->setTimeFromTimeString($policy->permanentLoginTime())->format('g:i A');
    $logoutDisplay = \Carbon\Carbon::today($tz)->setTimeFromTimeString($policy->permanentLogoutTime())->format('g:i A');
    $lateCutoffDisplay = \Carbon\Carbon::today($tz)
        ->setTimeFromTimeString($policy->permanentLoginTime())
        ->addMinutes($policy->tardyGraceMinutes())
        ->format('g:i A');
    $shsLogin = \Carbon\Carbon::today($tz)->setTimeFromTimeString($values['shs_login_time'])->format('g:i A');
    $shsLogout = \Carbon\Carbon::today($tz)->setTimeFromTimeString($values['shs_logout_time'])->format('g:i A');
    $eveLogin = \Carbon\Carbon::today($tz)->setTimeFromTimeString($values['shs_evening_login_time'])->format('g:i A');
    $eveLogout = \Carbon\Carbon::today($tz)->setTimeFromTimeString($values['shs_evening_logout_time'])->format('g:i A');
    $activeTemp = $policy->activeTemporaryOverride();
@endphp
<div class="container py-4" style="max-width: 920px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">Attendance policy</h3>
        <div class="d-flex gap-2">
            <a href="{{ route('school_calendar.index') }}" class="btn btn-outline-secondary btn-sm">School calendar</a>
            <a href="{{ route('attendance_logs.index') }}" class="btn btn-outline-secondary btn-sm">Attendance logs</a>
        </div>
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

    @if($activeTemp)
        <div class="alert alert-warning">
            <strong>Temporary time change is active</strong>
            ({{ \Carbon\Carbon::parse($activeTemp['starts_on'])->format('M j') }}
            – {{ \Carbon\Carbon::parse($activeTemp['ends_on'])->format('M j, Y') }}):
            login {{ \Carbon\Carbon::today($tz)->setTimeFromTimeString($activeTemp['login_time'])->format('g:i A') }},
            logout {{ \Carbon\Carbon::today($tz)->setTimeFromTimeString($activeTemp['logout_time'])->format('g:i A') }}.
            Outside that window the saved permanent times apply automatically.
        </div>
    @endif

    <form method="POST" action="{{ route('attendance.policy.settings.update') }}">
        @csrf

        <div class="card mb-4">
            <div class="card-header fw-semibold">Gate times (Kinder – Grade 10 / general)</div>
            <div class="card-body">
                <p class="text-muted">
                    Controls when a student’s <strong>first IN of the day</strong> is marked
                    <strong>LATE</strong> on logs, SF2, and consecutive-late SMS.
                    Later same-day INs stay IN. Friday online classes auto-mark present system-wide.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="loginTime" class="form-label">Expected login time</label>
                        <input type="time" name="login_time" id="loginTime" class="form-control"
                               value="{{ old('login_time', $values['login_time']) }}" required>
                        <div class="form-text">Currently {{ $loginDisplay }}</div>
                    </div>
                    <div class="col-md-6">
                        <label for="logoutTime" class="form-label">Expected logout time</label>
                        <input type="time" name="logout_time" id="logoutTime" class="form-control"
                               value="{{ old('logout_time', $values['logout_time']) }}" required>
                        <div class="form-text">Currently {{ $logoutDisplay }}</div>
                    </div>
                    <div class="col-md-6">
                        <label for="tardyGrace" class="form-label">Grace period before late (minutes)</label>
                        <input type="number" name="tardy_grace_minutes" id="tardyGrace" class="form-control"
                               min="0" max="120" value="{{ old('tardy_grace_minutes', $values['tardy_grace_minutes']) }}" required>
                        <div class="form-text">
                            Check-in after <strong>{{ $lateCutoffDisplay }}</strong> is LATE (login + grace).
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Senior high (Grade 11–12) day class</div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    Applies to SHS students who are <strong>not</strong> on evening sections
                    (Abigail / Dignity). Saved times below replace older Grade 12-only override.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="shsLogin" class="form-label">SHS login</label>
                        <input type="time" name="shs_login_time" id="shsLogin" class="form-control"
                               value="{{ old('shs_login_time', $values['shs_login_time']) }}" required>
                        <div class="form-text">Currently {{ $shsLogin }}</div>
                    </div>
                    <div class="col-md-6">
                        <label for="shsLogout" class="form-label">SHS logout</label>
                        <input type="time" name="shs_logout_time" id="shsLogout" class="form-control"
                               value="{{ old('shs_logout_time', $values['shs_logout_time']) }}" required>
                        <div class="form-text">Currently {{ $shsLogout }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Senior high evening</div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    Evening sections (name match: Abigail, Dignity, or *Evening). Priority over SHS day times.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="shsEveLogin" class="form-label">Evening login</label>
                        <input type="time" name="shs_evening_login_time" id="shsEveLogin" class="form-control"
                               value="{{ old('shs_evening_login_time', $values['shs_evening_login_time']) }}" required>
                        <div class="form-text">Currently {{ $eveLogin }}</div>
                    </div>
                    <div class="col-md-6">
                        <label for="shsEveLogout" class="form-label">Evening logout</label>
                        <input type="time" name="shs_evening_logout_time" id="shsEveLogout" class="form-control"
                               value="{{ old('shs_evening_logout_time', $values['shs_evening_logout_time']) }}" required>
                        <div class="form-text">Currently {{ $eveLogout }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Temporary time change</div>
            <div class="card-body">
                <p class="text-muted">
                    While active, these times override the permanent schedule you select below.
                    After the end date, the system automatically uses the permanent times again
                    (permanent fields are never overwritten by a temporary change).
                </p>
                <div class="form-check mb-3">
                    <input type="checkbox" name="temp_enabled" value="1" class="form-check-input" id="tempEnabled"
                           @checked(old('temp_enabled', $values['temp_enabled']))>
                    <label class="form-check-label" for="tempEnabled">Enable temporary time change</label>
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="tempLogin">Temp login</label>
                        <input type="time" name="temp_login_time" id="tempLogin" class="form-control"
                               value="{{ old('temp_login_time', $values['temp_login_time']) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tempLogout">Temp logout</label>
                        <input type="time" name="temp_logout_time" id="tempLogout" class="form-control"
                               value="{{ old('temp_logout_time', $values['temp_logout_time']) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tempStart">Starts on</label>
                        <input type="date" name="temp_starts_on" id="tempStart" class="form-control"
                               value="{{ old('temp_starts_on', $values['temp_starts_on']) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tempEnd">Ends on</label>
                        <input type="date" name="temp_ends_on" id="tempEnd" class="form-control"
                               value="{{ old('temp_ends_on', $values['temp_ends_on']) }}">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-check-inline">
                            <input type="checkbox" name="temp_apply_to_default" value="1" class="form-check-input" id="tempDef"
                                   @checked(old('temp_apply_to_default', $values['temp_apply_to_default']))>
                            <label class="form-check-label" for="tempDef">Apply to general (K–10)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" name="temp_apply_to_shs" value="1" class="form-check-input" id="tempShs"
                                   @checked(old('temp_apply_to_shs', $values['temp_apply_to_shs']))>
                            <label class="form-check-label" for="tempShs">Apply to SHS day</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" name="temp_apply_to_shs_evening" value="1" class="form-check-input" id="tempEve"
                                   @checked(old('temp_apply_to_shs_evening', $values['temp_apply_to_shs_evening']))>
                            <label class="form-check-label" for="tempEve">Apply to SHS evening</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">SMS alert thresholds</div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    When a student reaches these streaks, an SMS is sent to their
                    <strong>emergency contact</strong> using Communication → Gate Terminal Message.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="lateThreshold" class="form-label">Consecutive lates before warning</label>
                        <input type="number" name="consecutive_late_threshold" id="lateThreshold" class="form-control"
                               min="1" max="30"
                               value="{{ old('consecutive_late_threshold', $values['consecutive_late_threshold']) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label for="absentThreshold" class="form-label">Consecutive absences before warning</label>
                        <input type="number" name="consecutive_absent_threshold" id="absentThreshold" class="form-control"
                               min="1" max="30"
                               value="{{ old('consecutive_absent_threshold', $values['consecutive_absent_threshold']) }}" required>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save policy</button>
    </form>
</div>
@endsection
