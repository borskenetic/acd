@extends('layouts.app')

@section('title', 'School Setup')

@push('styles')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
    <link rel="stylesheet" href="{{ asset('css/school_setup/index.css') }}">
@endpush

@section('content')
@php
    use Illuminate\Support\Str;

    $activeTab = request('tab', 'programs');
    $sectionListId = fn (string $grade, string $strand = '') => 'grade-sections-'.Str::slug($grade.($strand ? '-'.$strand : ''));
@endphp

<div class="data-page school-setup-page">
    <div class="mb-4">
        <h4 class="mb-1">School Setup</h4>
        <p class="text-muted small mb-0">
            College programs, K–10 homeroom sections, and senior high sections by strand.
        </p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'programs' ? 'active' : '' }}" data-bs-toggle="tab"
                    data-bs-target="#tab-programs" type="button">Programs &amp; courses</button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'sections' ? 'active' : '' }}" data-bs-toggle="tab"
                    data-bs-target="#tab-sections" type="button">Grade &amp; sections</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade {{ $activeTab === 'programs' ? 'show active' : '' }}" id="tab-programs">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">Add program</h6>
                    <form method="POST" action="{{ route('school-setup.programs.store') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-3">
                            <label class="form-label small">Code</label>
                            <input type="text" name="program_code" class="form-control form-control-sm"
                                   placeholder="e.g. BSCS" required maxlength="50">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small">Program name</label>
                            <input type="text" name="program_name" class="form-control form-control-sm"
                                   placeholder="Full program title" required maxlength="255">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100 ss-program-add-btn">Add program</button>
                        </div>
                    </form>
                </div>
            </div>

            @forelse($programs as $program)
                <div class="card shadow-sm mb-3" id="program-card-{{ $program->id }}">
                    <div class="card-header program-card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                        <span class="fw-semibold program-card-title" id="program-name-{{ $program->id }}">
                            {{ $program->program_code }} — {{ $program->program_name }}
                        </span>
                        <div class="d-flex gap-2 program-card-actions">
                            <button type="button" class="btn btn-sm btn-warning ss-toolbar-btn"
                                    data-action="edit-program"
                                    data-id="{{ $program->id }}"
                                    data-code="{{ $program->program_code }}"
                                    data-name="{{ $program->program_name }}">
                                Edit
                            </button>
                            <button type="button" class="btn btn-sm btn-danger ss-toolbar-btn"
                                    data-action="delete-program"
                                    data-id="{{ $program->id }}"
                                    data-code="{{ $program->program_code }}">
                                Delete
                            </button>
                            <button type="button" class="btn btn-sm btn-light ss-toolbar-btn ss-courses-btn"
                                    data-bs-toggle="collapse" data-bs-target="#program-body-{{ $program->id }}">
                                Courses
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="program-body-{{ $program->id }}">
                        <div class="card-body border-top">
                            <ul class="list-group list-group-flush mb-3 course-list" id="course-list-{{ $program->id }}">
                                @forelse($program->courses as $course)
                                    @include('school_setup.partials.course_item', ['course' => $course])
                                @empty
                                    <li class="list-group-item text-muted small course-empty">No courses yet.</li>
                                @endforelse
                            </ul>
                            <form method="POST" action="{{ route('school-setup.courses.store', $program) }}"
                                  class="add-course-form row g-2 align-items-end" data-program="{{ $program->id }}">
                                @csrf
                                <div class="col-md-3">
                                    <label class="form-label small">Course code</label>
                                    <input type="text" name="course_code" class="form-control form-control-sm" required maxlength="50">
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label small">Course name</label>
                                    <input type="text" name="course_name" class="form-control form-control-sm" required maxlength="255">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-success w-100 ss-course-add-btn">
                                        <span class="btn-text">Add course</span>
                                        <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card shadow-sm">
                    <div class="card-body text-muted text-center py-4">No programs yet. Add one above.</div>
                </div>
            @endforelse
        </div>

        <div class="tab-pane fade {{ $activeTab === 'sections' ? 'show active' : '' }}" id="tab-sections">
            <p class="small text-muted mb-3">
                Use <strong>+ Section</strong> on Kinder–Grade 10. For senior high, add strands first, then sections under each strand.
            </p>

            <h6 class="fw-semibold mb-2">Kinder – Grade 10</h6>
            <div class="row g-3 mb-4">
                @foreach($basicGrades as $grade)
                    @php $sections = $basicSections->get($grade, collect()); @endphp
                    <div class="col-md-6 col-lg-4 col-xl-3">
                        <div class="card shadow-sm h-100 grade-section-card">
                            <div class="card-header py-2 d-flex justify-content-between align-items-center gap-2">
                                <span class="fw-semibold small">{{ $grade }}</span>
                                <button type="button" class="btn btn-sm btn-primary ss-action-btn"
                                        data-action="add-section"
                                        data-grade="{{ $grade }}"
                                        data-strand="">
                                    + Section
                                </button>
                            </div>
                            <ul class="list-group list-group-flush grade-section-list"
                                id="{{ $sectionListId($grade) }}"
                                data-grade="{{ $grade }}" data-strand="">
                                @forelse($sections as $row)
                                    @include('school_setup.partials.grade_section_item', ['row' => $row])
                                @empty
                                    <li class="list-group-item text-muted small section-empty">No sections</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h6 class="fw-semibold mb-0">Senior high — Grades 11 &amp; 12</h6>
                <button type="button" class="btn btn-primary ss-action-btn ss-add-strand-main"
                        data-action="add-strand">
                    + Strand
                </button>
            </div>

            <div class="row g-3">
                @foreach($seniorHighGrades as $grade)
                    <div class="col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header py-2 fw-semibold">{{ $grade }}</div>
                            <div class="card-body p-0 senior-grade-body" id="senior-body-{{ Str::slug($grade) }}">
                                @forelse($strandRecords as $strandRecord)
                                    @php
                                        $key = $grade.'|'.$strandRecord->name;
                                        $sections = $seniorSections->get($key, collect());
                                    @endphp
                                    @include('school_setup.partials.strand_block', [
                                        'grade' => $grade,
                                        'strand' => $strandRecord->name,
                                        'strandId' => $strandRecord->id,
                                        'sections' => $sections,
                                    ])
                                @empty
                                    <div class="p-3 text-muted small strand-empty-msg">No strands yet. Click <strong>+ Strand</strong> above.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@include('school_setup.partials.modals')
@endsection

@push('scripts')
    <script>
        window.SCHOOL_SETUP = {
            csrf: @json(csrf_token()),
            seniorHighGrades: @json($seniorHighGrades),
            shsStrands: @json($shsStrands),
            urls: {
                course: @json(url('/school-setup/courses')),
                program: @json(url('/school-setup/programs')),
                gradeSection: @json(url('/school-setup/sections')),
                sectionsStore: @json(route('school-setup.sections.store')),
                strandsStore: @json(route('school-setup.strands.store')),
                strands: @json(url('/school-setup/strands')),
            },
        };
    </script>
    <script src="{{ \App\Support\VersionedAsset::url('js/school-setup.js') }}" defer></script>
@endpush
