@extends('layouts.sec')

@section('title', 'Employees')

@push('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/data-pages.css') }}">
@endpush

@section('content')
@php
    $query = request()->query();
    $exportUrl = route('employees.export', $query);
    $bulkIdsUrl = route('employees.bulk.ids', $query);
    $idCardsEnabled = config('patron.id_cards_enabled', false);
@endphp

<div class="data-page patron-list-page employees-page mt-3">
    <header class="sp-header">
        <div>
            <h1 class="sp-title">Employees</h1>
            <p class="sp-subtitle">{{ number_format($faculty->total()) }} registered — search, filter, and manage staff records.</p>
        </div>
        <div class="sp-header__actions">
            @can('isAdmin')
                <a href="{{ route('employees.create') }}" class="sp-btn sp-btn--primary">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Register
                </a>
                <a href="{{ route('pending.index', ['tab' => 'employees']) }}" class="sp-btn sp-btn--warn">Pending</a>

                <div class="dropdown">
                    <button class="sp-btn sp-btn--ghost dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Import
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end sp-menu">
                        <li><a class="dropdown-item" href="{{ route('employees.import.template') }}">Download template</a></li>
                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#epImportModal">Upload spreadsheet</button></li>
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
        <a href="{{ route('students.index') }}" class="sp-tab">Students</a>
        <a href="{{ route('employees.index') }}" class="sp-tab is-active">Employees</a>
    </nav>

    @if(session('success') || session('error'))
        <div class="sp-alerts">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
        </div>
    @endif

    <section class="sp-filters" aria-label="Filter employees">
        <form action="{{ route('employees.index') }}" method="GET">
            <div class="sp-search-row">
                <label class="sp-search" for="epSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="epSearch" name="search" value="{{ request('search') }}"
                           placeholder="Search name, employee ID, department…" autocomplete="off">
                </label>
                <button type="submit" class="sp-btn sp-btn--primary">Search</button>
                @if(collect($query)->except('page')->filter()->isNotEmpty())
                    <a href="{{ route('employees.index') }}" class="sp-btn sp-btn--ghost">Clear</a>
                @endif
            </div>
            <div class="sp-filter-grid">
                <div class="sp-field">
                    <label for="epDept">Department</label>
                    <select name="department" id="epDept" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All departments</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept }}" {{ request('department') == $dept ? 'selected' : '' }}>{{ $dept }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sp-field">
                    <label for="epPosition">Position</label>
                    <select name="position" id="epPosition" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All positions</option>
                        @foreach($positions as $pos)
                            <option value="{{ $pos }}" {{ request('position') == $pos ? 'selected' : '' }}>{{ $pos }}</option>
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
                        <th scope="col">Employee ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Department</th>
                        <th scope="col">Position</th>
                        @can('isAdmin')
                            <th scope="col" class="text-end">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse($faculty as $employee)
                        <tr>
                            <td>
                                @if($employee->formal_picture)
                                    <img src="{{ asset($employee->formal_picture) }}" alt="" class="sp-profile">
                                @else
                                    <span class="sp-profile--empty" aria-hidden="true">—</span>
                                @endif
                            </td>
                            <td><span class="sp-id-badge">{{ $employee->employee_id ?? $employee->qrcode ?? '—' }}</span></td>
                            <td class="text-start">
                                <strong>{{ $employee->lastname }}</strong>, {{ $employee->firstname }}
                            </td>
                            <td>{{ $employee->department ?: '—' }}</td>
                            <td>{{ $employee->position ?: '—' }}</td>
                            @can('isAdmin')
                                <td class="text-end">
                                    <div class="dropdown sp-row-menu">
                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                                data-bs-toggle="dropdown"
                                                data-bs-popper-config='{"strategy":"fixed","placement":"bottom-end"}'
                                                aria-expanded="false"
                                                aria-label="Actions for {{ $employee->firstname }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end sp-menu">
                                            <li><a class="dropdown-item" href="{{ route('employees.edit', $employee->id) }}">Edit employee</a></li>
                                            @if($idCardsEnabled)
                                                <li><hr class="dropdown-divider"></li>
                                                <li><h6 class="dropdown-header">ID card</h6></li>
                                                <li><a class="dropdown-item" href="{{ route('employees.idcard.front', $employee->id) }}" target="_blank" rel="noopener">Front</a></li>
                                                <li><a class="dropdown-item" href="{{ route('employees.idcard.back', $employee->id) }}" target="_blank" rel="noopener">Back</a></li>
                                                <li><a class="dropdown-item" href="{{ route('employees.idcard.download', $employee->id) }}">Download ZIP</a></li>
                                            @endif
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form action="{{ route('employees.destroy', $employee->id) }}" method="POST" onsubmit="return confirm('Delete this employee?');">
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
                            <td colspan="{{ auth()->user()?->can('isAdmin') ? 6 : 5 }}">
                                <div class="sp-empty">No employees match your filters.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($faculty->hasPages())
            <div class="sp-pagination">
                {{ $faculty->withQueryString()->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </section>
</div>

@can('isAdmin')
    <div class="modal fade sp-modal" id="epImportModal" tabindex="-1" aria-labelledby="epImportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('employees.import') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="epImportModalLabel">Import employees</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Upload Excel or CSV using the <a href="{{ route('employees.import.template') }}">import template</a> columns.</p>
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
@endcan
@endsection
