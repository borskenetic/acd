@php
    $report = $report ?? null;
    $defaults = $defaults ?? [];
    $monthNames = config('sf2.month_names', []);
@endphp

<div class="card mb-4">
    <div class="card-header fw-semibold">School &amp; class (SF2 header)</div>
    <div class="card-body row g-3">
        <div class="col-md-4">
            <label class="form-label">School ID</label>
            <input type="text" name="school_id" class="form-control" maxlength="50"
                   value="{{ old('school_id', $report->school_id ?? '') }}" placeholder="DepEd School ID">
        </div>
        <div class="col-md-8">
            <label class="form-label">Name of school <span class="text-danger">*</span></label>
            <input type="text" name="school_name" class="form-control" required maxlength="255"
                   value="{{ old('school_name', $report->school_name ?? $defaults['school_name'] ?? '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label">School year <span class="text-danger">*</span></label>
            <input type="text" name="school_year" class="form-control" required maxlength="16"
                   value="{{ old('school_year', $report->school_year ?? $defaults['school_year'] ?? '') }}"
                   placeholder="2025-2026">
        </div>
        <div class="col-md-4">
            <label class="form-label">Report month <span class="text-danger">*</span></label>
            <select name="report_month" class="form-select" required>
                @foreach($monthNames as $num => $label)
                    <option value="{{ $num }}" @selected((int) old('report_month', $report->report_month ?? $defaults['report_month'] ?? 0) === (int) $num)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Report year <span class="text-danger">*</span></label>
            <input type="number" name="report_year" class="form-control" required min="2000" max="2100"
                   value="{{ old('report_year', $report->report_year ?? $defaults['report_year'] ?? '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label">Grade level <span class="text-danger">*</span></label>
            <select name="grade_level" id="sf2-grade-select" class="form-select" required>
                <option value="">— Select —</option>
                @foreach($gradeLevels as $grade)
                    <option value="{{ $grade }}" @selected(old('grade_level', $report->grade_level ?? '') === $grade)>{{ $grade }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Section <span class="text-danger">*</span></label>
            @if($report)
                <input type="text" name="section" class="form-control" required maxlength="64"
                       value="{{ old('section', $report->section ?? '') }}" placeholder="e.g. St. Francis">
            @else
                <select name="section" id="sf2-section-select" class="form-select" required>
                    <option value="">— Select grade first —</option>
                    @if(old('section') && old('grade_level'))
                        <option value="{{ old('section') }}" selected>{{ old('section') }}</option>
                    @endif
                </select>
            @endif
        </div>
        <div class="col-md-4">
            <label class="form-label">Teacher (printed name)</label>
            <input type="text" name="teacher_name" class="form-control" maxlength="255"
                   value="{{ old('teacher_name', $report->teacher_name ?? '') }}">
        </div>
        <div class="col-md-6">
            <label class="form-label">School head (printed name)</label>
            <input type="text" name="school_head_name" class="form-control" maxlength="255"
                   value="{{ old('school_head_name', $report->school_head_name ?? '') }}">
        </div>
        <div class="col-12">
            <p class="small text-muted mb-0">
                School days are weekdays (Mon–Fri) in the selected month.
                @unless($report)
                    Use <strong>Load from attendance logs</strong> to fill the roster and marks from school-wide IN scans
                    (present = scanned IN; absent = no IN; tardy = first IN after expected login + grace —
                    Grade 12 half-day starts at 12:00 noon).
                    You can still adjust any day on the calendar before saving.
                @else
                    For each learner, use the <strong>calendar</strong> to click absent or tardy days; unmarked weekdays count as present.
                @endunless
            </p>
        </div>
    </div>
</div>
