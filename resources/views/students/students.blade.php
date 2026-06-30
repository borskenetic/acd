@extends('layouts.sec')

@section('title', 'Students')

@push('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/data-pages.css') }}">
@endpush

@section('content')
@php use App\Enums\EducationalLevel; @endphp
@php
    $query = request()->query();
    $exportUrl = route('students.export', $query);
    $bulkIdsUrl = route('students.bulk.ids', $query);
    $idCardsEnabled = config('patron.id_cards_enabled', false);
@endphp

<div class="data-page patron-list-page students-page mt-3">
    <header class="sp-header">
        <div>
            <h1 class="sp-title">Students</h1>
            <p class="sp-subtitle">{{ number_format($students->total()) }} registered — search, filter, and manage records.</p>
        </div>
        <div class="sp-header__actions">
            @can('isAdmin')
                <a href="{{ route('students.create') }}" class="sp-btn sp-btn--primary">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Register
                </a>
                <a href="{{ route('pending.index', ['tab' => 'students']) }}" class="sp-btn sp-btn--warn">Pending</a>

                <div class="dropdown">
                    <button class="sp-btn sp-btn--ghost dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Import
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end sp-menu">
                        <li><a class="dropdown-item" href="{{ route('students.import.template') }}">Download template</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#spImportModal">Upload spreadsheet</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">RFID cards</h6></li>
                        <li><a class="dropdown-item" href="{{ route('students.rfid.template') }}">Download RFID template</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#spRfidModal">Upload RFID update</button></li>
                    </ul>
                </div>
            @endcan

            <div class="dropdown">
                <button class="sp-btn sp-btn--ghost dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end sp-menu">
                    <li><a class="dropdown-item" href="{{ $exportUrl }}">Export this page</a></li>
                    @if($idCardsEnabled)
                        @can('isAdmin')
                            <li><a class="dropdown-item" href="{{ $bulkIdsUrl }}">Download ID cards (ZIP)</a></li>
                        @endcan
                    @endif
                </ul>
            </div>
        </div>
    </header>

    <nav class="sp-tabs" aria-label="Patron type">
        <a href="{{ route('students.index') }}" class="sp-tab is-active">Students</a>
        <a href="{{ route('employees.index') }}" class="sp-tab">Employees</a>
    </nav>

    @if(session('success') || session('error') || session('rfid_import_report'))
        <div class="sp-alerts">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            @if(session('rfid_import_report'))
                @php $rfidReport = session('rfid_import_report'); @endphp
                @if(!empty($rfidReport['not_found']) || !empty($rfidReport['conflicts']))
                    <div class="alert alert-warning text-start mb-0">
                        <strong>RFID import details</strong>
                        @if(!empty($rfidReport['not_found']))
                            <ul class="small mb-1 mt-2">
                                @foreach($rfidReport['not_found'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(!empty($rfidReport['conflicts']))
                            <ul class="small mb-0">
                                @foreach($rfidReport['conflicts'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    @endif

    <section class="sp-filters" aria-label="Filter students">
        <form action="{{ route('students.index') }}" method="GET">
            <div class="sp-search-row">
                <label class="sp-search" for="spSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="spSearch" name="search" value="{{ request('search') }}"
                           placeholder="Search name, student ID, course…" autocomplete="off">
                </label>
                <button type="submit" class="sp-btn sp-btn--primary">Search</button>
                @if(collect($query)->except('page')->filter()->isNotEmpty())
                    <a href="{{ route('students.index') }}" class="sp-btn sp-btn--ghost">Clear</a>
                @endif
            </div>
            <div class="sp-filter-grid">
                <div class="sp-field">
                    <label for="spLevel">Level</label>
                    <select name="educational_level" id="spLevel" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All levels</option>
                        @foreach (EducationalLevel::options() as $value => $label)
                            <option value="{{ $value }}" {{ request('educational_level') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sp-field">
                    <label for="spCourse">Course</label>
                    <select name="program_id" id="spCourse" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All courses</option>
                        @foreach ($programs as $program)
                            <option value="{{ $program->program_code }}" {{ request('program_id') == $program->program_code ? 'selected' : '' }}>
                                {{ $program->program_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="sp-field">
                    <label for="spYear">Year / grade</label>
                    <select name="year" id="spYear" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All years</option>
                        @foreach(\App\Support\PatronOptions::allYearOptions() as $y)
                            <option value="{{ $y }}" {{ request('year') == $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>
    </section>

    <section class="sp-table-card">
        <div class="table-responsive">
            <table class="table table-hover sp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col"></th>
                        <th scope="col">Student ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Level</th>
                        <th scope="col">Course</th>
                        <th scope="col">Year</th>
                        @can('isAdmin')
                            <th scope="col" class="text-end">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse($students as $student)
                        <tr>
                            <td>
                                @if($student->profile_picture)
                                    <img src="{{ asset($student->profile_picture) }}" alt="" class="sp-profile">
                                @else
                                    <span class="sp-profile--empty" aria-hidden="true">—</span>
                                @endif
                            </td>
                            <td><span class="sp-id-badge">{{ $student->student_id ?? '—' }}</span></td>
                            <td class="text-start">
                                <strong>{{ $student->lastname }}</strong>, {{ $student->firstname }}
                            </td>
                            <td>
                                @if($student->educational_level)
                                    <span class="sp-level-pill">{{ $student->educational_level->label() }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $student->course ?: '—' }}</td>
                            <td>{{ $student->year ?: '—' }}</td>
                            @can('isAdmin')
                                <td class="text-end">
                                    <div class="dropdown sp-row-menu">
                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                                data-bs-toggle="dropdown"
                                                data-bs-popper-config='{"strategy":"fixed","placement":"bottom-end"}'
                                                aria-expanded="false"
                                                aria-label="Actions for {{ $student->firstname }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end sp-menu">
                                            <li><a class="dropdown-item" href="{{ route('students.edit', $student->id) }}">Edit student</a></li>
                                            @if($idCardsEnabled)
                                                <li><hr class="dropdown-divider"></li>
                                                <li><h6 class="dropdown-header">ID card</h6></li>
                                                <li><a class="dropdown-item" href="{{ route('idcard.front', $student->id) }}" target="_blank" rel="noopener">Front</a></li>
                                                <li><a class="dropdown-item" href="{{ route('idcard.back', $student->id) }}" target="_blank" rel="noopener">Back</a></li>
                                                <li><a class="dropdown-item" href="{{ route('idcard.download', $student->id) }}">Download ZIP</a></li>
                                            @endif
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form action="{{ route('students.destroy', $student->id) }}" method="POST" onsubmit="return confirm('Delete this student?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="dropdown-item text-danger">Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ auth()->user()?->can('isAdmin') ? 7 : 6 }}">
                                <div class="sp-empty">No students match your filters.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($students->hasPages())
            <div class="sp-pagination">
                {{ $students->withQueryString()->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </section>
</div>

@can('isAdmin')
    <div class="modal fade sp-modal" id="spImportModal" tabindex="-1" aria-labelledby="spImportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('students.import') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="spImportModalLabel">Import students</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Upload Excel or CSV using the <a href="{{ route('students.import.template') }}">import template</a> columns.</p>
                        <div class="sp-file-pick">
                            <input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.xls,.csv" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Import</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade sp-modal" id="spRfidModal" tabindex="-1" aria-labelledby="spRfidModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('students.rfid.import') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="spRfidModalLabel">Update RFID cards</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Match by IDNum, RecordID, or QR code. Use the <a href="{{ route('students.rfid.template') }}">RFID template</a>. Blank RFID rows are skipped.</p>
                        <div class="sp-file-pick">
                            <input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.xls,.csv" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
@endsection
