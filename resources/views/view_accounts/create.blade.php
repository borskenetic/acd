@extends('layouts.sec')

@section('title', 'Create User Account')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
<div class="data-page accounts-page mt-3">
    <div class="card shadow-sm">
        <div class="card-header text-center py-3">
            <h4 class="mb-0">Create User Account</h4>
        </div>

        <div class="card-body p-4">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('users.store') }}" method="POST" class="mx-auto" style="max-width: 520px;">
                @csrf
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">First name</label>
                        <input type="text" name="fname" class="form-control" value="{{ old('fname') }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last name</label>
                        <input type="text" name="lname" class="form-control" value="{{ old('lname') }}" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                            <option value="staff" {{ old('role', 'staff') === 'staff' ? 'selected' : '' }}>Staff</option>
                            <option value="faculty" {{ old('role') === 'faculty' ? 'selected' : '' }}>Faculty</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 d-flex flex-wrap gap-2 justify-content-center">
                    <button type="submit" class="btn btn-add">Create Account</button>
                    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">View Users</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
