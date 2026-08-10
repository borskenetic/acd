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
            @if(!empty($advisoryNotice))
                <p class="small text-muted mb-0">{{ $advisoryNotice }}</p>
            @endif
            <p class="small text-muted mb-0 mt-1">
                <span class="sp-streak-swatch sp-streak-swatch--late"></span>
                {{ $lateThreshold ?? 5 }}+ consecutive lates
                <span class="sp-streak-swatch sp-streak-swatch--absent ms-2"></span>
                {{ $absentThreshold ?? 3 }}+ consecutive absences
                <span class="text-muted">(absent highlight takes priority)</span>
            </p>
        </div>
        <div class="sp-header__actions">
            @can('manageStudents')
                <a href="{{ route('students.create') }}" class="sp-btn sp-btn--primary">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Register
                </a>
                <a href="{{ route('pending.index', ['tab' => 'students']) }}" class="sp-btn sp-btn--warn">Pending</a>
            @endcan
            @can('isAdmin')
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
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Gender (sex)</h6></li>
                        <li><a class="dropdown-item" href="{{ route('students.sex.template') }}">Download gender template</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#spSexModal">Upload gender update</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Contacts / emergency</h6></li>
                        <li><a class="dropdown-item" href="{{ route('students.contact.template') }}">Download contact template</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#spContactModal">Upload contact update</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">ACD records</h6></li>
                        <li><a class="dropdown-item" href="{{ route('students.records.template') }}">Download records template</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#spRecordsModal">Upload records update</button></li>
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
        @can('isAdminOrStaff')
            <a href="{{ route('employees.index') }}" class="sp-tab">Employees</a>
        @endcan
    </nav>

    @if(session('success') || session('error') || session('rfid_import_report') || session('sex_import_report') || session('contact_import_report') || session('records_import_report') || $errors->any())
        <div class="sp-alerts">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger text-start mb-0">
                    <strong>Import failed</strong>
                    <ul class="small mb-0 mt-2">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if(session('rfid_import_report'))
                @php $rfidReport = session('rfid_import_report'); @endphp
                @if(!empty($rfidReport['not_found']) || !empty($rfidReport['ambiguous']) || !empty($rfidReport['conflicts']) || !empty($rfidReport['out_of_scope']))
                    <div class="alert alert-warning text-start mb-0">
                        <strong>RFID import details</strong>
                        @if(!empty($rfidReport['out_of_scope']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($rfidReport['out_of_scope'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($rfidReport['out_of_scope']) > 30)
                                    <li>… and {{ count($rfidReport['out_of_scope']) - 30 }} more outside grade access</li>
                                @endif
                            </ul>
                        @endif
                        @if(!empty($rfidReport['not_found']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($rfidReport['not_found'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($rfidReport['not_found']) > 30)
                                    <li>… and {{ count($rfidReport['not_found']) - 30 }} more not found</li>
                                @endif
                            </ul>
                        @endif
                        @if(!empty($rfidReport['ambiguous']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($rfidReport['ambiguous'], 0, 20) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($rfidReport['ambiguous']) > 20)
                                    <li>… and {{ count($rfidReport['ambiguous']) - 20 }} more ambiguous</li>
                                @endif
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
            @if(session('sex_import_report'))
                @php $sexReport = session('sex_import_report'); @endphp
                @if(!empty($sexReport['not_found']) || !empty($sexReport['ambiguous']) || !empty($sexReport['invalid']) || !empty($sexReport['out_of_scope']))
                    <div class="alert alert-warning text-start mb-0">
                        <strong>Gender update details</strong>
                        @if(!empty($sexReport['out_of_scope']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($sexReport['out_of_scope'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(!empty($sexReport['invalid']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($sexReport['invalid'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(!empty($sexReport['not_found']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($sexReport['not_found'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($sexReport['not_found']) > 30)
                                    <li>… and {{ count($sexReport['not_found']) - 30 }} more not found</li>
                                @endif
                            </ul>
                        @endif
                        @if(!empty($sexReport['ambiguous']))
                            <ul class="small mb-0">
                                @foreach(array_slice($sexReport['ambiguous'], 0, 20) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            @endif
            @if(session('contact_import_report'))
                @php $contactReport = session('contact_import_report'); @endphp
                @if(!empty($contactReport['not_found']) || !empty($contactReport['ambiguous']) || !empty($contactReport['no_contact_data']) || !empty($contactReport['out_of_scope']))
                    <div class="alert alert-warning text-start mb-0">
                        <strong>Contact update details</strong>
                        @if(!empty($contactReport['out_of_scope']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($contactReport['out_of_scope'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(!empty($contactReport['not_found']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($contactReport['not_found'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($contactReport['not_found']) > 30)
                                    <li>… and {{ count($contactReport['not_found']) - 30 }} more not found</li>
                                @endif
                            </ul>
                        @endif
                        @if(!empty($contactReport['ambiguous']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($contactReport['ambiguous'], 0, 20) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($contactReport['ambiguous']) > 20)
                                    <li>… and {{ count($contactReport['ambiguous']) - 20 }} more ambiguous</li>
                                @endif
                            </ul>
                        @endif
                        @if(!empty($contactReport['no_contact_data']))
                            <ul class="small mb-0">
                                @foreach(array_slice($contactReport['no_contact_data'], 0, 15) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($contactReport['no_contact_data']) > 15)
                                    <li>… and {{ count($contactReport['no_contact_data']) - 15 }} more with no contact fields</li>
                                @endif
                            </ul>
                        @endif
                    </div>
                @endif
            @endif
            @if(session('records_import_report'))
                @php $recordsReport = session('records_import_report'); @endphp
                @if(!empty($recordsReport['not_found']) || !empty($recordsReport['ambiguous']) || !empty($recordsReport['conflicts']) || !empty($recordsReport['no_data']) || !empty($recordsReport['out_of_scope']))
                    <div class="alert alert-warning text-start mb-0">
                        <strong>Records update details</strong>
                        @if(!empty($recordsReport['out_of_scope']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($recordsReport['out_of_scope'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(!empty($recordsReport['conflicts']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($recordsReport['conflicts'], 0, 20) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($recordsReport['conflicts']) > 20)
                                    <li>… and {{ count($recordsReport['conflicts']) - 20 }} more conflicts</li>
                                @endif
                            </ul>
                        @endif
                        @if(!empty($recordsReport['not_found']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($recordsReport['not_found'], 0, 30) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($recordsReport['not_found']) > 30)
                                    <li>… and {{ count($recordsReport['not_found']) - 30 }} more not found</li>
                                @endif
                            </ul>
                        @endif
                        @if(!empty($recordsReport['ambiguous']))
                            <ul class="small mb-1 mt-2">
                                @foreach(array_slice($recordsReport['ambiguous'], 0, 20) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($recordsReport['ambiguous']) > 20)
                                    <li>… and {{ count($recordsReport['ambiguous']) - 20 }} more ambiguous</li>
                                @endif
                            </ul>
                        @endif
                        @if(!empty($recordsReport['no_data']))
                            <ul class="small mb-0">
                                @foreach(array_slice($recordsReport['no_data'], 0, 15) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                                @if(count($recordsReport['no_data']) > 15)
                                    <li>… and {{ count($recordsReport['no_data']) - 15 }} more with nothing to update</li>
                                @endif
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
                        @foreach(\App\Support\AdvisoryScope::yearOptions(auth()->user()) as $y)
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
                        @can('manageStudents')
                            <th scope="col" class="text-end">Actions</th>
                        @else
                            @can('isFaculty')
                                {{-- subject teachers can still open view-only path via logs; no row actions --}}
                            @endcan
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse($students as $student)
                        @php
                            $streak = $streakCounts[$student->id] ?? ['consecutive_late' => 0, 'consecutive_absent' => 0];
                            $lateCount = (int) ($streak['consecutive_late'] ?? 0);
                            $absentCount = (int) ($streak['consecutive_absent'] ?? 0);
                            $isAbsentAlert = $absentCount >= (int) ($absentThreshold ?? 3);
                            $isLateAlert = ! $isAbsentAlert && $lateCount >= (int) ($lateThreshold ?? 5);
                            $rowClass = $isAbsentAlert ? 'sp-row--absent-alert' : ($isLateAlert ? 'sp-row--late-alert' : '');
                        @endphp
                        <tr class="{{ $rowClass }}"
                            @if($isAbsentAlert || $isLateAlert)
                                title="{{ $isAbsentAlert ? $absentCount.' consecutive absences' : $lateCount.' consecutive lates' }}"
                            @endif>
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
                                @if($isAbsentAlert)
                                    <span class="sp-streak-badge sp-streak-badge--absent">{{ $absentCount }} absences</span>
                                @elseif($isLateAlert)
                                    <span class="sp-streak-badge sp-streak-badge--late">{{ $lateCount }} lates</span>
                                @endif
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
                            @can('manageStudents')
                                <td class="text-end">
                                    @if(\App\Support\AdvisoryScope::canMutateStudent($student))
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
                                                @can('isAdmin')
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><h6 class="dropdown-header">ID card</h6></li>
                                                    <li><a class="dropdown-item" href="{{ route('idcard.front', $student->id) }}" target="_blank" rel="noopener">Front</a></li>
                                                    <li><a class="dropdown-item" href="{{ route('idcard.back', $student->id) }}" target="_blank" rel="noopener">Back</a></li>
                                                    <li><a class="dropdown-item" href="{{ route('idcard.download', $student->id) }}">Download ZIP</a></li>
                                                @endcan
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
                                    @else
                                        <span class="text-muted small">View only</span>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ auth()->user()?->can('manageStudents') ? 7 : 6 }}">
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
                        <p class="text-muted small">Columns: <code>Name</code>, <code>ID Number</code>, <code>LRN</code> (optional), <code>RFID</code>, <code>Year</code> (e.g. Kinder, Grade 1–10), <code>Section</code>. <a href="{{ route('students.import.template') }}">Download template</a>.</p>
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
                        <p class="text-muted small mb-2">
                            Updates RFID on existing students only. Blank RFID rows are skipped.
                        </p>
                        <p class="text-muted small">
                            Match order: <strong>ID Number</strong> → LRN → RecordID → QR → <strong>Name</strong> (+ Year/Section if needed).
                            Name format: <code>LASTNAME, Given Names</code>. Use the <a href="{{ route('students.rfid.template') }}">RFID template</a>.
                        </p>
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

    <div class="modal fade sp-modal" id="spSexModal" tabindex="-1" aria-labelledby="spSexModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('students.sex.import') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="spSexModalLabel">Update student gender</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-2">
                            Updates <code>sex</code> on existing students only. Does not create or delete records.
                        </p>
                        <p class="text-muted small">
                            Match order: <strong>ID Number</strong> → LRN → RFID → Name (+ Year/Section if needed).
                            Gender values: <code>Male</code>/<code>Female</code> (or male/female).
                            Your gendered roster CSV works as-is.
                        </p>
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

    <div class="modal fade sp-modal" id="spContactModal" tabindex="-1" aria-labelledby="spContactModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('students.contact.import') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="spContactModalLabel">Update contacts &amp; emergency info</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-2">
                            Updates mobile number and emergency fields on existing students only. Blank fields are left unchanged.
                        </p>
                        <p class="text-muted small mb-2">
                            Columns: <code>Student Mobile Number</code>, <code>Emergency Person to be Contacted</code>,
                            <code>Relationship</code>, <code>Emergency Contact Number</code>, <code>Emergency Person Address</code>.
                        </p>
                        <p class="text-muted small">
                            Match order: <strong>ID Number</strong> → LRN → RFID → Name (+ Year/Section).
                            Multi-sheet section rosters (e.g. Grade 7–10 class sheets) work as-is.
                            <a href="{{ route('students.contact.template') }}">Download template</a>.
                        </p>
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

    <div class="modal fade sp-modal" id="spRecordsModal" tabindex="-1" aria-labelledby="spRecordsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('students.records.import') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="spRecordsModalLabel">Update ACD records fields</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-2">
                            Updates existing students only. Does not create records.
                            Applies: <code>RecordID</code>, <code>CourseStrand</code>,
                            <code>GuardianName</code> → emergency person,
                            <code>GuardianAddress</code> → emergency address,
                            <code>GuardianContact</code> → emergency number.
                            All other columns are ignored.
                        </p>
                        <p class="text-muted small mb-2">
                            When <code>RecordID</code> changes, <code>profile_picture</code> is set to
                            <code>images/profile_pictures/{RecordID}.jpg</code>.
                        </p>
                        <p class="text-muted small">
                            Match order: <strong>IDNum</strong> → LRN → existing RecordID → LastName + FirstName.
                            Your full ACD data records CSV works as-is.
                            <a href="{{ route('students.records.template') }}">Download template</a>.
                        </p>
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

    @if($errors->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const modal = document.getElementById('spImportModal');
                    if (modal) {
                        bootstrap.Modal.getOrCreateInstance(modal).show();
                    }
                });
            </script>
        @endpush
    @endif
@endcan
@endsection
