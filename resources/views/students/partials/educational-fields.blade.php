@php
    use App\Enums\EducationalLevel;

    $selectedLevel = old('educational_level', $educationalLevel ?? '');
    $selectedYear = old('year', $year ?? '');
    $yearOptionsByLevel = config('patron.year_options', []);
    $user = auth()->user();
    if ($user && method_exists($user, 'isBandAdmin') && $user->isBandAdmin()) {
        $filtered = [];
        foreach ($yearOptionsByLevel as $level => $years) {
            $kept = array_values(array_filter(
                $years,
                fn ($y) => $user->canAccessGradeLevel((string) $y)
            ));
            if ($kept !== []) {
                $filtered[$level] = $kept;
            }
        }
        $yearOptionsByLevel = $filtered;
    }
    $levelOptions = \App\Enums\EducationalLevel::options();
    if ($user && method_exists($user, 'isBandAdmin') && $user->isBandAdmin()) {
        $levelOptions = array_filter(
            $levelOptions,
            fn ($label, $value) => isset($yearOptionsByLevel[$value]),
            ARRAY_FILTER_USE_BOTH
        );
    }
    $schoolSetup = $schoolSetup ?? ($sectionsByGrade ?? null ? [
        'sectionsByGrade' => $sectionsByGrade ?? [],
        'sectionsByGradeStrand' => [],
        'strands' => config('patron.shs_strands', []),
        'seniorHighGrades' => config('patron.senior_high_grades', ['Grade 11', 'Grade 12']),
    ] : \App\Support\SchoolSetupOptions::registrationData());
    $courseValue = old('course', $course ?? '');
    $sectionValue = old('section', $section ?? '');
    $sexValue = old('sex', $sex ?? '');
    $isCollege = $selectedLevel === EducationalLevel::College->value;
    $isSenior = $selectedLevel === EducationalLevel::HighSchoolSenior->value;
@endphp

<div class="col-md-6">
    <label for="educational_level" class="form-label">Educational level <span class="text-danger">*</span></label>
    <select name="educational_level" id="educational_level"
            class="form-select @error('educational_level') is-invalid @enderror" required>
        <option value="">Select level…</option>
        @foreach ($levelOptions as $value => $label)
            <option value="{{ $value }}" {{ $selectedLevel === $value ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    @error('educational_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="col-md-6" id="patron-course-college" @if(! $isCollege) hidden @endif>
    <label for="course" class="form-label">Course / program <span class="text-danger course-required-mark">*</span></label>
    @if($programs->isNotEmpty())
        <select id="course" class="form-select @error('course') is-invalid @enderror"
                @if($isCollege) name="course" required @endif>
            <option value="">Select program…</option>
            @foreach ($programs as $program)
                <option value="{{ $program->program_code }}"
                    {{ $courseValue == $program->program_code ? 'selected' : '' }}>
                    {{ $program->program_name }}
                </option>
            @endforeach
            @if($courseValue && ! $programs->contains('program_code', $courseValue))
                <option value="{{ $courseValue }}" selected>{{ $courseValue }} (current)</option>
            @endif
        </select>
    @else
        <input type="text" id="course" class="form-control @error('course') is-invalid @enderror"
               value="{{ $courseValue }}" placeholder="Course or program"
               @if($isCollege) name="course" required @endif>
    @endif
    @error('course')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="col-md-6" id="patron-strand-fields" @if(! $isSenior) hidden @endif>
    <label for="strand" class="form-label">Strand <span class="text-danger strand-required-mark">*</span></label>
    <select id="strand" class="form-select @error('course') is-invalid @enderror"
            data-initial="{{ $courseValue }}"
            @if($isSenior) name="course" required @endif>
        <option value="">Select strand…</option>
        @foreach ($schoolSetup['strands'] ?? [] as $strand)
            <option value="{{ $strand }}" @selected($courseValue === $strand)>{{ $strand }}</option>
        @endforeach
        @if($courseValue && ! in_array($courseValue, $schoolSetup['strands'] ?? [], true))
            <option value="{{ $courseValue }}" selected>{{ $courseValue }} (current)</option>
        @endif
    </select>
    @error('course')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="col-md-6">
    <label for="year" class="form-label">Year / grade level <span class="text-danger">*</span></label>
    <select name="year" id="year" class="form-select @error('year') is-invalid @enderror" required
            data-selected="{{ $selectedYear }}">
        <option value="">Select year…</option>
    </select>
    @error('year')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="col-md-6" id="patron-homeroom-fields" @if($isCollege) hidden @endif>
    <label for="section" class="form-label">Homeroom section</label>
    <select name="section" id="section" class="form-select @error('section') is-invalid @enderror"
            data-initial="{{ $sectionValue }}"
            @if($isCollege) disabled @endif>
        <option value="">Select grade first…</option>
        @if($sectionValue)
            <option value="{{ $sectionValue }}" selected>{{ $sectionValue }}</option>
        @endif
    </select>
    @error('section')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="col-md-6" id="patron-sex-fields" @if($isCollege) hidden @endif>
    <label for="sex" class="form-label">Sex</label>
    <select name="sex" id="sex" class="form-select @error('sex') is-invalid @enderror"
            @if($isCollege) disabled @endif>
        <option value="">—</option>
        <option value="male" @selected($sexValue === 'male')>Male</option>
        <option value="female" @selected($sexValue === 'female')>Female</option>
    </select>
    @error('sex')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

@push('scripts')
<script>
(function () {
    const levelSelect = document.getElementById('educational_level');
    const yearSelect = document.getElementById('year');
    const collegeCourse = document.getElementById('patron-course-college');
    const strandFields = document.getElementById('patron-strand-fields');
    const courseSelect = document.getElementById('course');
    const strandSelect = document.getElementById('strand');
    const homeroomFields = document.getElementById('patron-homeroom-fields');
    const sexFields = document.getElementById('patron-sex-fields');
    const sectionSelect = document.getElementById('section');
    const sexSelect = document.getElementById('sex');
    const yearOptionsByLevel = @json($yearOptionsByLevel);
    const schoolSetup = @json($schoolSetup);

    if (!levelSelect || !yearSelect) return;

    function isSeniorHighGrade(grade) {
        return (schoolSetup.seniorHighGrades || []).includes(grade);
    }

    function populateYears(level, keepValue) {
        const options = yearOptionsByLevel[level] || [];
        const current = keepValue ?? yearSelect.dataset.selected ?? yearSelect.value;
        yearSelect.innerHTML = '<option value="">Select year…</option>';
        let found = false;
        options.forEach(function (y) {
            const opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            if (current && current === y) {
                opt.selected = true;
                found = true;
            }
            yearSelect.appendChild(opt);
        });
        if (current && !found) {
            const extra = document.createElement('option');
            extra.value = current;
            extra.textContent = current + ' (current)';
            extra.selected = true;
            yearSelect.appendChild(extra);
        }
        populateSections();
    }

    function populateSections() {
        if (!sectionSelect) return;

        const grade = yearSelect.value;
        const level = levelSelect.value;
        const strand = strandSelect?.value || '';
        const current = sectionSelect.dataset.initial || sectionSelect.value || '';
        let sections = [];

        if (grade && isSeniorHighGrade(grade) && level === 'high_school_senior') {
            const byStrand = schoolSetup.sectionsByGradeStrand?.[grade] || {};
            sections = strand ? (byStrand[strand] || []) : [];
        } else if (grade) {
            sections = schoolSetup.sectionsByGrade?.[grade] || [];
        }

        sectionSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        if (!grade) {
            placeholder.textContent = 'Select grade first…';
        } else if (isSeniorHighGrade(grade) && level === 'high_school_senior' && !strand) {
            placeholder.textContent = 'Select strand first…';
        } else {
            placeholder.textContent = sections.length ? 'Select section…' : 'No sections configured';
        }
        sectionSelect.appendChild(placeholder);

        let found = false;
        sections.forEach(function (name) {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            if (current && current === name) {
                opt.selected = true;
                found = true;
            }
            sectionSelect.appendChild(opt);
        });

        if (current && !found) {
            const extra = document.createElement('option');
            extra.value = current;
            extra.textContent = current + ' (current)';
            extra.selected = true;
            sectionSelect.appendChild(extra);
        }

        sectionSelect.dataset.initial = '';
    }

    function toggleCourseFields(level) {
        const isCollege = level === 'college';
        const isSenior = level === 'high_school_senior';

        if (collegeCourse) collegeCourse.hidden = !isCollege;
        if (strandFields) strandFields.hidden = !isSenior;

        if (courseSelect) {
            courseSelect.required = isCollege;
            courseSelect.disabled = !isCollege;
            if (isCollege) {
                courseSelect.setAttribute('name', 'course');
            } else {
                courseSelect.removeAttribute('name');
            }
        }

        if (strandSelect) {
            strandSelect.required = isSenior;
            strandSelect.disabled = !isSenior;
            if (isSenior) {
                strandSelect.setAttribute('name', 'course');
            } else {
                strandSelect.removeAttribute('name');
            }
        }

        if (homeroomFields) homeroomFields.hidden = isCollege;
        if (sexFields) sexFields.hidden = isCollege;

        if (sectionSelect) {
            sectionSelect.disabled = isCollege;
            if (isCollege) {
                sectionSelect.removeAttribute('name');
            } else {
                sectionSelect.setAttribute('name', 'section');
            }
        }

        if (sexSelect) {
            sexSelect.disabled = isCollege;
            if (isCollege) {
                sexSelect.removeAttribute('name');
            } else {
                sexSelect.setAttribute('name', 'sex');
            }
        }

        populateSections();
    }

    levelSelect.addEventListener('change', function () {
        populateYears(this.value, '');
        toggleCourseFields(this.value);
    });

    yearSelect.addEventListener('change', populateSections);
    strandSelect?.addEventListener('change', populateSections);

    populateYears(levelSelect.value, yearSelect.dataset.selected);
    toggleCourseFields(levelSelect.value);
})();
</script>
@endpush
