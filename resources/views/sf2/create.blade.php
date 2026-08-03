@extends('layouts.app')

@section('title', 'Create SF2 Report')

@section('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/sf2-form.css') }}">
@endsection

@section('content')
<div class="mb-3">
    <a href="{{ route('sf2.index') }}" class="text-decoration-none small">&larr; Back to SF2 list</a>
    <h4 class="mt-2 mb-1">Create SF2 report</h4>
    <p class="text-muted small">Choose grade and section, load learners from attendance logs, review marks, then save and export the DepEd Excel.</p>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('sf2.store') }}" id="sf2-form">
    @csrf

    @include('sf2.partials.form-fields', ['defaults' => $defaults])

    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Learners</span>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="button" id="sf2-load-from-logs" class="btn btn-sm btn-success">Load from attendance logs</button>
                <input type="number" id="sf2-student-count" class="form-control form-control-sm" style="width:5rem" min="1" max="80" placeholder="#">
                <button type="button" id="sf2-generate-rows" class="btn btn-sm btn-outline-primary">Generate rows</button>
                <button type="button" id="sf2-add-student" class="btn btn-sm btn-primary">Add learner</button>
            </div>
        </div>
        <div class="card-body">
            <div id="sf2-students-container">
                @if(old('students'))
                    @foreach(old('students') as $i => $student)
                        @include('sf2.partials.student-row-static', ['index' => $i, 'student' => $student])
                    @endforeach
                @else
                    @include('sf2.partials.student-row-static', ['index' => 0, 'student' => ['sex' => 'male']])
                @endif
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Save report</button>
        <a href="{{ route('sf2.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

@include('sf2.partials.student-row-template')

<script>
    window.SF2_SECTIONS_BY_GRADE = @json($rosterData['sections_by_grade'] ?? new \stdClass());
    if (Array.isArray(window.SF2_SECTIONS_BY_GRADE)) {
        window.SF2_SECTIONS_BY_GRADE = {};
    }
    window.SF2_PREVIEW_URL = @json(route('sf2.preview'));

    // Claim the load button before external form.js so it does not also bind a click.
    (function () {
        var b = document.getElementById('sf2-load-from-logs');
        if (b) {
            b.dataset.sf2Bound = '1';
            b.dataset.sf2InlineBound = '1';
        }
    })();
</script>
<script src="{{ \App\Support\VersionedAsset::url('js/sf2-calendar.js') }}"></script>
<script src="{{ \App\Support\VersionedAsset::url('js/sf2-form.js') }}"></script>
<script>
(function () {
    var btn = document.getElementById('sf2-load-from-logs');
    if (!btn || btn.dataset.sf2ClickWired === '1') {
        return;
    }
    btn.dataset.sf2ClickWired = '1';

    function selectedSection() {
        if (window.Sf2Form && typeof window.Sf2Form.selectedSection === 'function') {
            return window.Sf2Form.selectedSection();
        }
        var manual = document.getElementById('sf2-section-manual');
        if (manual && manual.getAttribute('name') === 'section' && !manual.classList.contains('d-none')) {
            return String(manual.value || '').trim();
        }
        var sel = document.getElementById('sf2-section-select');
        if (sel) {
            return String(sel.value || '').trim();
        }
        var any = document.querySelector('[name="section"]');
        return any ? String(any.value || '').trim() : '';
    }

    async function loadFromLogs() {
        if (window.Sf2Form && typeof window.Sf2Form.loadFromLogs === 'function') {
            return window.Sf2Form.loadFromLogs();
        }

        var gradeEl = document.getElementById('sf2-grade-select');
        var grade = gradeEl ? String(gradeEl.value || '').trim() : '';
        var section = selectedSection();
        var monthEl = document.querySelector('[name="report_month"]');
        var yearEl = document.querySelector('[name="report_year"]');
        var month = monthEl ? monthEl.value : '';
        var year = yearEl ? yearEl.value : '';

        if (!grade || !section || !month || !year) {
            alert('Select grade level, section, report month, and year first.');
            return;
        }
        if (!window.SF2_PREVIEW_URL) {
            alert('SF2 preview URL is missing. Refresh the page.');
            return;
        }

        var url = new URL(window.SF2_PREVIEW_URL, window.location.origin);
        url.searchParams.set('grade_level', grade);
        url.searchParams.set('section', section);
        url.searchParams.set('report_month', month);
        url.searchParams.set('report_year', year);

        btn.disabled = true;
        btn.textContent = 'Loading…';

        try {
            var response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });
            var contentType = response.headers.get('content-type') || '';
            if (!response.ok) {
                throw new Error('Could not load attendance data (HTTP ' + response.status + ').');
            }
            if (contentType.indexOf('application/json') === -1) {
                throw new Error('Server did not return JSON (session expired?). Refresh and sign in again.');
            }
            var data = await response.json();
            if (data.warnings && data.warnings.length) {
                alert(data.warnings.join('\n'));
            }
            if (!data.students || !data.students.length) {
                alert('No learners loaded. Check that students have grade, section, and sex (male/female) set.');
                return;
            }
            if (window.Sf2Form && typeof window.Sf2Form.replaceAllStudents === 'function') {
                window.Sf2Form.replaceAllStudents(data.students);
            } else {
                alert('Loaded ' + data.students.length + ' learner(s), but public/js/sf2-form.js is missing so rows could not be filled.');
            }
        } catch (err) {
            console.error(err);
            alert(err.message || 'Failed to load from attendance logs.');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Load from attendance logs';
        }
    }

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        loadFromLogs();
    });
})();
</script>
@endsection
