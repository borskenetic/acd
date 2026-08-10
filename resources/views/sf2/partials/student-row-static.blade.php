@php
    $index = $index ?? 0;
    $student = $student ?? [];
    $absentDates = $student['absent_dates'] ?? [];
    $tardyDates = $student['tardy_dates'] ?? [];
    $halfDayDates = $student['half_day_dates'] ?? [];
@endphp
<div class="sf2-student-row card mb-3 border">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-semibold">Learner <span class="sf2-row-number">{{ $index + 1 }}</span></span>
            <button type="button" class="btn btn-sm btn-outline-danger sf2-remove-row">Remove</button>
        </div>
        <div class="row g-2">
            <div class="col-md-2">
                <label class="form-label small">Sex</label>
                <select name="students[{{ $index }}][sex]" class="form-select form-select-sm" required>
                    <option value="male" @selected(($student['sex'] ?? '') === 'male')>Male</option>
                    <option value="female" @selected(($student['sex'] ?? '') === 'female')>Female</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Last name</label>
                <input type="text" name="students[{{ $index }}][last_name]" class="form-control form-control-sm"
                       value="{{ $student['last_name'] ?? '' }}" required maxlength="100">
            </div>
            <div class="col-md-3">
                <label class="form-label small">First name</label>
                <input type="text" name="students[{{ $index }}][first_name]" class="form-control form-control-sm"
                       value="{{ $student['first_name'] ?? '' }}" required maxlength="100">
            </div>
            <div class="col-md-4">
                <label class="form-label small">Middle name</label>
                <input type="text" name="students[{{ $index }}][middle_name]" class="form-control form-control-sm"
                       value="{{ $student['middle_name'] ?? '' }}" maxlength="100">
            </div>
            @include('sf2.partials.attendance-calendar', [
                'index' => $index,
                'absentDates' => $absentDates,
                'tardyDates' => $tardyDates,
                'halfDayDates' => $halfDayDates,
            ])
            <div class="col-12">
                <label class="form-label small">Remarks</label>
                <input type="text" name="students[{{ $index }}][remarks]" class="form-control form-control-sm"
                       value="{{ $student['remarks'] ?? '' }}" maxlength="500">
            </div>
        </div>
    </div>
</div>
