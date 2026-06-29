@extends('layouts.sec')

@section('title', 'Students')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/students/students.css') }}">
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
<div class="data-page mt-3">
    <div class="card">
        <div class="card-header text-center">
            <h4>Registered Students</h4>
        </div>
        <div class="card-body">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            @if(session('rfid_import_report'))
                @php $rfidReport = session('rfid_import_report'); @endphp
                @if(!empty($rfidReport['not_found']) || !empty($rfidReport['conflicts']))
                    <div class="alert alert-warning text-start">
                        <strong>RFID import details</strong>
                        @if(!empty($rfidReport['not_found']))
                            <p class="mb-1 mt-2 small fw-semibold">Not found</p>
                            <ul class="small mb-2">
                                @foreach($rfidReport['not_found'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(!empty($rfidReport['conflicts']))
                            <p class="mb-1 small fw-semibold">Conflicts</p>
                            <ul class="small mb-0">
                                @foreach($rfidReport['conflicts'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            @endif

            @php use App\Enums\EducationalLevel; @endphp
            <form action="{{ route('students.index') }}" method="GET" class="row g-2 mb-3">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Search name, ID, course…" value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <select name="educational_level" class="form-select form-select-sm">
                        <option value="">All levels</option>
                        @foreach (EducationalLevel::options() as $value => $label)
                            <option value="{{ $value }}" {{ request('educational_level') == $value ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="program_id" class="form-select form-select-sm">
                        <option value="">All Courses</option>
                        @foreach ($programs as $program)
                            <option value="{{ $program->program_code }}"
                                {{ request('program_id') == $program->program_code ? 'selected' : '' }}>
                                {{ $program->program_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="year" class="form-select form-select-sm">
                        <option value="">All Years</option>
                        @foreach(\App\Support\PatronOptions::allYearOptions() as $y)
                            <option value="{{ $y }}" {{ request('year') == $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100 btn-search-filter">Filter</button>
                </div>
            </form>

            <div class="mb-3 text-center data-tabs">
                <a href="{{ route('students.index') }}" class="btn btn-outline-primary btn-sm active">Students</a>
                <a href="{{ route('employees.index') }}" class="btn btn-outline-primary btn-sm">Employees</a>
            </div>

            @include('partials.patron-data-toolbar', [
                'registerRoute' => auth()->user()?->can('isAdmin') ? route('students.create') : null,
                'registerLabel' => '+ Register Student',
                'pendingUrl' => route('pending.index', ['tab' => 'students']),
                'importTemplateRoute' => 'students.import.template',
                'importRoute' => 'students.import',
                'rfidTemplateRoute' => 'students.rfid.template',
                'rfidImportRoute' => 'students.rfid.import',
                'exportRoute' => route('students.export', request()->query()),
                'downloadIdsRoute' => route('students.bulk.ids', request()->query()),
            ])

            <div class="table-responsive">
                <table class="table table-bordered table-hover text-center align-middle patron-list-table">
                    <thead>
                        <tr>
                            <th scope="col">Profile</th>
                            <th scope="col">Student ID</th>
                            <th scope="col">Last Name</th>
                            <th scope="col">First Name</th>
                            <th scope="col">Level</th>
                            <th scope="col">Course</th>
                            <th scope="col">Year</th>
                            <th scope="col">Actions</th>
                            <th scope="col">Generate ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($students as $student)
                            <tr>
                                <td>
                                    @if($student->profile_picture)
                                        <img src="{{ asset($student->profile_picture) }}" alt="Profile" class="profile-img">
                                    @else
                                        <span>No Image</span>
                                    @endif
                                </td>
                                <td>{{ $student->student_id ?? '—' }}</td>
                                <td>{{ $student->lastname }}</td>
                                <td>{{ $student->firstname }}</td>
                                <td>{{ $student->educational_level?->label() ?? '—' }}</td>
                                <td>{{ $student->course }}</td>
                                <td>{{ $student->year }}</td>
                                <td>
                                    @can('isAdmin')
                                        <div class="dropdown">
                                            <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">Options</button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="{{ route('students.edit', $student->id) }}">Edit</a></li>
                                                <li>
                                                    <form action="{{ route('students.destroy', $student->id) }}" method="POST" onsubmit="return confirm('Delete this student?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="dropdown-item" type="submit">Delete</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endcan
                                </td>
                                <td>
                                    @can('isAdmin')
                                        <div class="dropdown">
                                            <button class="btn btn-success btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">Generate</button>
                                            <ul class="dropdown-menu">
                                                @php
                                                    $idLevel = $student->educational_level?->label() ?? 'College';
                                                @endphp
                                                <li><span class="dropdown-item-text small text-muted">{{ $idLevel }} ID</span></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="{{ route('idcard.front', $student->id) }}" target="_blank">Front</a></li>
                                                <li><a class="dropdown-item" href="{{ route('idcard.back', $student->id) }}" target="_blank">Back</a></li>
                                                <li><a class="dropdown-item" href="{{ route('idcard.download', $student->id) }}">Download ZIP</a></li>
                                            </ul>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9">No students found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-center mt-3">
                {{ $students->withQueryString()->links('pagination::bootstrap-5') }}
            </div>
        </div>
    </div>
</div>
@endsection
