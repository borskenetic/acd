@extends('layouts.sec')

@section('title', 'Edit User')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
@php
    $initial = old('advisories');
    if ($initial === null) {
        $initial = $user->advisories->map(fn ($a) => [
            'year' => $a->year,
            'section' => $a->section,
            'access_level' => $a->access_level,
        ])->all();
        if ($initial === [] && $user->advisory_year && $user->advisory_section) {
            $initial = [[
                'year' => $user->advisory_year,
                'section' => $user->advisory_section,
                'access_level' => 'adviser',
            ]];
        }
        if ($initial === []) {
            $initial = [['year' => '', 'section' => '', 'access_level' => 'adviser']];
        }
    }
@endphp
<div class="data-page accounts-page mt-3">
    <div class="card shadow-sm">
        <div class="card-header text-center py-3">
            <h4 class="mb-0">Edit User</h4>
        </div>

        <div class="card-body p-4">
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('users.update', $user->id) }}" method="POST" class="mx-auto" style="max-width: 640px;">
                @csrf
                @method('PUT')

                <div class="mb-3">
                    <label for="fname" class="form-label">First name</label>
                    <input type="text" name="fname" id="fname" class="form-control"
                           value="{{ old('fname', $user->fname) }}" required>
                </div>
                <div class="mb-3">
                    <label for="lname" class="form-label">Last name</label>
                    <input type="text" name="lname" id="lname" class="form-control"
                           value="{{ old('lname', $user->lname) }}" required>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control"
                           value="{{ old('email', $user->email) }}" required>
                </div>
                <div class="mb-3">
                    <label for="roleSelect" class="form-label">Role</label>
                    <select name="role" id="roleSelect" class="form-select" required>
                        <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>Admin</option>
                        <option value="staff" {{ old('role', $user->role) === 'staff' ? 'selected' : '' }}>Staff</option>
                        <option value="faculty" {{ old('role', $user->role) === 'faculty' ? 'selected' : '' }}>Faculty</option>
                        @if($user->role === 'student')
                            <option value="student" selected>Student (legacy)</option>
                        @endif
                    </select>
                </div>

                <div class="mb-3 faculty-advisory-fields border rounded p-3 bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <strong>Class assignments</strong>
                            <p class="small text-muted mb-0">Required for faculty. Multiple classes supported.</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addAdvisoryRow">+ Add class</button>
                    </div>
                    <div id="advisoryRows" class="d-flex flex-column gap-2"></div>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-between mt-4">
                    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Back to users</a>
                    <button type="submit" class="btn btn-add">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

@include('view_accounts.partials.advisory-rows-script', [
    'initialRows' => $initial,
])
@endsection
