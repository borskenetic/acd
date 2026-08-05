@extends('layouts.sec')

@section('title', 'User Accounts')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
<div class="data-page accounts-page mt-3">
    <div class="card shadow-sm">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-3">
            <h4 class="mb-0">User Accounts</h4>
            <a href="{{ route('users.create') }}" class="btn btn-add btn-sm">+ Create Account</a>
        </div>

        <div class="card-body">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            <form method="GET" action="{{ route('users.index') }}" class="row g-2 mb-3">
                <div class="col-md-8">
                    <input type="search" name="search" class="form-control form-control-sm"
                           placeholder="Search name, email, or role…"
                           value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100 btn-search-filter">Search</button>
                </div>
                @if(request('search'))
                    <div class="col-md-2">
                        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm w-100">Clear</a>
                    </div>
                @endif
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle text-center accounts-list-table">
                    <thead>
                        <tr>
                            <th scope="col">First Name</th>
                            <th scope="col">Last Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Advisory</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td>{{ $user->fname }}</td>
                                <td>{{ $user->lname }}</td>
                                <td class="text-start">{{ $user->email }}</td>
                                <td>
                                    <span class="badge role-badge role-badge-{{ $user->role }}">
                                        {{ ucfirst($user->role) }}
                                    </span>
                                </td>
                                <td class="small">{{ $user->advisoryLabel() }}</td>
                                <td>
                                    <div class="d-flex flex-wrap justify-content-center gap-1">
                                        <a href="{{ route('users.edit', $user->id) }}" class="btn btn-warning btn-sm">Edit</a>
                                        <form action="{{ route('users.destroy', $user->id) }}" method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete this user account?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-muted py-4">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-center mt-3">
                {{ $users->links('pagination::bootstrap-5') }}
            </div>
        </div>
    </div>
</div>
@endsection
