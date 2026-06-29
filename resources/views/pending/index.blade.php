@extends('layouts.sec')

@section('title', 'Pending Registrations')

@push('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/data-pages.css') }}">
    <style>.data-pending-panel.hidden { display: none; }</style>
@endpush

@section('content')
<div class="data-page mt-3">
    <div class="card">
        <div class="card-header text-center">
            <h4>Pending Registrations</h4>
        </div>
        <div class="card-body">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 data-pending-toggle">
                <div>
                    <button type="button" id="showStudents" class="btn btn-primary me-2">Students</button>
                    <button type="button" id="showEmployees" class="btn btn-outline-primary">Employees</button>
                </div>
                <div class="data-tabs">
                    <a href="{{ route('students.index') }}" class="btn btn-outline-primary btn-sm">Registered Students</a>
                    <a href="{{ route('employees.index') }}" class="btn btn-outline-primary btn-sm">Registered Employees</a>
                </div>
            </div>

            <form method="GET" action="{{ route('pending.index') }}" class="row g-2 mb-3">
                <input type="hidden" name="tab" id="pendingTab" value="{{ request('tab', 'students') }}">
                <div class="col-md-6">
                    <input type="text" name="search" value="{{ $search ?? request('search') }}" class="form-control form-control-sm" placeholder="Search pending records…">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100 btn-search-filter">Search</button>
                </div>
            </form>

            <div id="studentTable" class="data-pending-panel">
                <h5 class="mb-3" style="font-weight:700;">Pending Students</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover text-center align-middle">
                        <thead>
                            <tr>
                                <th>Profile</th>
                                <th>ID Number</th>
                                <th>Name</th>
                                <th>Level</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($pendingStudents as $p)
                                <tr>
                                    <td>
                                        @if($p->profile_picture)
                                            <img src="{{ asset($p->profile_picture) }}" width="80" alt="">
                                        @else
                                            No Image
                                        @endif
                                    </td>
                                    <td>{{ $p->student_id ?? '—' }}</td>
                                    <td>{{ $p->firstname }} {{ $p->lastname }}</td>
                                    <td>{{ $p->educational_level?->label() ?? '—' }}</td>
                                    <td>{{ $p->course }}</td>
                                    <td>{{ $p->year }}</td>
                                    <td>
                                        <form action="{{ route('students.approve', $p->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-success btn-sm">Approve</button>
                                        </form>
                                        <form action="{{ route('students.reject', $p->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-danger btn-sm">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7">No pending student registrations.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-center mt-3">
                    {{ $pendingStudents->appends(request()->query())->links('pagination::bootstrap-5') }}
                </div>
            </div>

            <div id="employeeTable" class="data-pending-panel hidden">
                <h5 class="mb-3" style="font-weight:700;">Pending Employees</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover text-center align-middle">
                        <thead>
                            <tr>
                                <th>Profile</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($pendingEmployees as $e)
                                <tr>
                                    <td>
                                        @if($e->formal_picture)
                                            <img src="{{ asset($e->formal_picture) }}" width="80" alt="">
                                        @else
                                            No Image
                                        @endif
                                    </td>
                                    <td>{{ $e->firstname }} {{ $e->lastname }}</td>
                                    <td>{{ $e->department }}</td>
                                    <td>{{ $e->position }}</td>
                                    <td>
                                        <form action="{{ route('employees.approve', $e->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-success btn-sm">Approve</button>
                                        </form>
                                        <form action="{{ route('employees.reject', $e->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-danger btn-sm">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5">No pending employee registrations.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-center mt-3">
                    {{ $pendingEmployees->appends(request()->query())->links('pagination::bootstrap-5') }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    const studentTable = document.getElementById('studentTable');
    const employeeTable = document.getElementById('employeeTable');
    const btnStudents = document.getElementById('showStudents');
    const btnEmployees = document.getElementById('showEmployees');
    const tabInput = document.getElementById('pendingTab');

    function showStudents() {
        studentTable.classList.remove('hidden');
        employeeTable.classList.add('hidden');
        btnStudents.className = 'btn btn-primary me-2';
        btnEmployees.className = 'btn btn-outline-primary';
        tabInput.value = 'students';
    }

    function showEmployees() {
        employeeTable.classList.remove('hidden');
        studentTable.classList.add('hidden');
        btnEmployees.className = 'btn btn-primary me-2';
        btnStudents.className = 'btn btn-outline-primary';
        tabInput.value = 'employees';
    }

    btnStudents.addEventListener('click', showStudents);
    btnEmployees.addEventListener('click', showEmployees);

    const tab = new URLSearchParams(window.location.search).get('tab') || 'students';
    if (tab === 'employees') showEmployees();
    else showStudents();
})();
</script>
@endsection
