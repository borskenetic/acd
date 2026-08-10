<template id="sf2-student-row-template">
    <div class="sf2-student-row card mb-3 border">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">Learner <span class="sf2-row-number">1</span></span>
                <button type="button" class="btn btn-sm btn-outline-danger sf2-remove-row">Remove</button>
            </div>
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label small">Sex</label>
                    <select class="form-select form-select-sm" data-field="sex" required>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Last name</label>
                    <input type="text" class="form-control form-control-sm" data-field="last_name" required maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">First name</label>
                    <input type="text" class="form-control form-control-sm" data-field="first_name" required maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Middle name</label>
                    <input type="text" class="form-control form-control-sm" data-field="middle_name" maxlength="100">
                </div>
                @include('sf2.partials.attendance-calendar', ['index' => null, 'absentDates' => [], 'tardyDates' => [], 'halfDayDates' => []])
                <div class="col-12">
                    <label class="form-label small">Remarks (dropout / transfer, etc.)</label>
                    <input type="text" class="form-control form-control-sm" data-field="remarks" maxlength="500">
                </div>
            </div>
        </div>
    </div>
</template>
