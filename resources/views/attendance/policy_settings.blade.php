@extends('layouts.sec')

@section('content')
@php
    $tz = $policy->timezone();
    $loginDisplay = \Carbon\Carbon::today($tz)->setTimeFromTimeString($policy->loginTime())->format('g:i A');
    $logoutDisplay = \Carbon\Carbon::today($tz)->setTimeFromTimeString($policy->logoutTime())->format('g:i A');
    $lateCutoffDisplay = \Carbon\Carbon::today($tz)
        ->setTimeFromTimeString($policy->loginTime())
        ->addMinutes($policy->tardyGraceMinutes())
        ->format('g:i A');
    $grade12LoginDisplay = \Carbon\Carbon::today($tz)
        ->setTimeFromTimeString($policy->loginTime('Grade 12'))
        ->format('g:i A');
    $grade12LateCutoffDisplay = \Carbon\Carbon::today($tz)
        ->setTimeFromTimeString($policy->loginTime('Grade 12'))
        ->addMinutes($policy->tardyGraceMinutes())
        ->format('g:i A');
@endphp
<div class="container py-4" style="max-width: 820px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">Attendance policy</h3>
        <a href="{{ route('attendance_logs.index') }}" class="btn btn-outline-secondary btn-sm">
            View attendance logs
        </a>
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

    <form method="POST" action="{{ route('attendance.policy.settings.update') }}">
        @csrf

        <div class="card mb-4">
            <div class="card-header fw-semibold">Gate times</div>
            <div class="card-body">
                <p class="text-muted">
                    These times control when a student scan is marked <strong>LATE</strong> on attendance logs,
                    SF2 reports, and when departure SMS may be sent.
                    <strong>Grade 12</strong> is half-day: expected login is {{ $grade12LoginDisplay }}
                    (late after {{ $grade12LateCutoffDisplay }}), not the morning time below.
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
                            A check-in after <strong>{{ $lateCutoffDisplay }}</strong> is classified as LATE
                            (login + grace).
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
                    <strong>emergency contact</strong> using the templates under Communication → Gate Terminal Message.
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
