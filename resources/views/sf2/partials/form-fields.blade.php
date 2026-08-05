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
                @php
                    $rosterData = $rosterData ?? ['sections_by_grade' => []];
                    $sectionsByGrade = $rosterData['sections_by_grade'] ?? [];
                    $oldGrade = old('grade_level', '');
                    $oldSection = old('section', '');
                    $oldGradeSections = ($oldGrade && isset($sectionsByGrade[$oldGrade]))
                        ? $sectionsByGrade[$oldGrade]
                        : [];
                @endphp
                <select name="section" id="sf2-section-select" class="form-select" required
                        data-initial="{{ $oldSection }}">
                    <option value="">{{ $oldGrade ? '— Select section —' : '— Select grade first —' }}</option>
                    @foreach($oldGradeSections as $secName)
                        <option value="{{ $secName }}" @selected($oldSection === $secName)>{{ $secName }}</option>
                    @endforeach
                    @if($oldSection && $oldGrade && ! in_array($oldSection, $oldGradeSections, true))
                        <option value="{{ $oldSection }}" selected>{{ $oldSection }} (current)</option>
                    @endif
                </select>
                <input type="text" id="sf2-section-manual" class="form-control mt-1 d-none" maxlength="64"
                       placeholder="Type section name" autocomplete="off"
                       value="{{ $oldSection }}">
                <p class="form-text small mb-0 mt-1" id="sf2-section-hint">
                    Pick a grade first. Sections come from student records and school setup.
                </p>
                {{-- Inline so production works even if cached public/js is stale or @stack scripts miss. --}}
                <script>
                (function () {
                    var map = @json($sectionsByGrade);
                    if (Array.isArray(map)) {
                        map = {};
                    }
                    window.SF2_SECTIONS_BY_GRADE = map;

                    var gradeSelect = document.getElementById('sf2-grade-select');
                    var sectionSelect = document.getElementById('sf2-section-select');
                    var manual = document.getElementById('sf2-section-manual');
                    if (!gradeSelect || !sectionSelect) {
                        return;
                    }

                    function useManual(on) {
                        if (!manual) {
                            return;
                        }
                        if (on) {
                            sectionSelect.classList.add('d-none');
                            sectionSelect.removeAttribute('name');
                            sectionSelect.required = false;
                            manual.classList.remove('d-none');
                            manual.setAttribute('name', 'section');
                            manual.required = true;
                        } else {
                            manual.classList.add('d-none');
                            manual.removeAttribute('name');
                            manual.required = false;
                            sectionSelect.classList.remove('d-none');
                            sectionSelect.setAttribute('name', 'section');
                            sectionSelect.required = true;
                        }
                    }

                    function fillSections(grade, selected) {
                        var sections = (grade && map[grade]) ? map[grade] : [];
                        var keep = selected || '';
                        sectionSelect.innerHTML = '';

                        var ph = document.createElement('option');
                        ph.value = '';
                        if (!grade) {
                            ph.textContent = '— Select grade first —';
                        } else if (sections.length) {
                            ph.textContent = '— Select section —';
                        } else {
                            ph.textContent = '— No sections for this grade —';
                        }
                        sectionSelect.appendChild(ph);

                        var found = false;
                        sections.forEach(function (name) {
                            var opt = document.createElement('option');
                            opt.value = name;
                            opt.textContent = name;
                            if (keep && keep === name) {
                                opt.selected = true;
                                found = true;
                            }
                            sectionSelect.appendChild(opt);
                        });

                        if (keep && !found && sections.length) {
                            var extra = document.createElement('option');
                            extra.value = keep;
                            extra.textContent = keep + ' (current)';
                            extra.selected = true;
                            sectionSelect.appendChild(extra);
                        }

                        // No catalog for this grade: free type so SF2 still usable online.
                        useManual(!!grade && sections.length === 0);
                        if (grade && sections.length === 0 && manual && keep) {
                            manual.value = keep;
                        }

                        var hint = document.getElementById('sf2-section-hint');
                        if (hint) {
                            if (!grade) {
                                hint.textContent = 'Pick a grade first. Sections come from student records and school setup.';
                            } else if (sections.length) {
                                hint.textContent = sections.length + ' section(s) for ' + grade + '.';
                            } else {
                                hint.textContent = 'No sections for ' + grade + ' in the database. Type the section name, or set sections on student records / School setup.';
                            }
                        }
                    }

                    gradeSelect.dataset.sf2SectionBound = '1';
                    gradeSelect.addEventListener('change', function () {
                        fillSections(this.value, '');
                    });
                    fillSections(gradeSelect.value, sectionSelect.getAttribute('data-initial') || '');
                })();
                </script>
            @endif
        </div>
        <div class="col-md-4">
            <label class="form-label">Teacher (printed name)</label>
            <input type="text" name="teacher_name" class="form-control" maxlength="255"
                   value="{{ old('teacher_name', $report->teacher_name ?? ($defaults['teacher_name'] ?? '')) }}">
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
                    Kinder–Grade 11 morning at 7:30, Grade 12 at 12:30, Grade 11 evening at 4:30; grace 5 minutes).
                    You can still adjust any day on the calendar before saving.
                @else
                    For each learner, use the <strong>calendar</strong> to click absent or tardy days; unmarked weekdays count as present.
                @endunless
            </p>
        </div>
    </div>
</div>
