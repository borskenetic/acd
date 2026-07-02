@extends('layouts.sec')

@section('title', 'Edit User')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
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

            <form action="{{ route('users.update', $user->id) }}" method="POST" class="mx-auto" style="max-width: 520px;">
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
                    <label for="role" class="form-label">Role</label>
                    <select name="role" id="role" class="form-select" required>
                        <option value="admin" {{ $user->role === 'admin' ? 'selected' : '' }}>Admin</option>
                        <option value="staff" {{ $user->role === 'staff' ? 'selected' : '' }}>Staff</option>
                        <option value="faculty" {{ $user->role === 'faculty' ? 'selected' : '' }}>Faculty</option>
                        @if($user->role === 'student')
                            <option value="student" selected>Student (legacy)</option>
                        @endif
                    </select>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-between mt-4">
                    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Back to users</a>
                    <button type="submit" class="btn btn-add">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
