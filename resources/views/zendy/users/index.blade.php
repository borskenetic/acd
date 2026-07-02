@extends('layouts.zen')

@section('page_title', 'Portal Users')
@section('page_subtitle', 'Accounts that can sign in to Zendy')

@section('content')
<div class="card-surface" style="margin-bottom: 20px;">
    <div class="page-toolbar">
        <a href="{{ route('zendy.users.create') }}" class="btn-app btn-primary-app btn-sm-app">+ Create user</a>
    </div>

    <form method="GET" action="{{ route('zendy.users.index') }}" class="filter-bar">
        <input type="text" name="search" class="form-control-app" placeholder="Search name or email..." value="{{ request('search') }}" style="flex: 1;">
        <select name="role" class="form-control-app">
            <option value="">All roles</option>
            @foreach($roles as $value => $label)
                <option value="{{ $value }}" {{ request('role') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-app btn-primary-app">Search</button>
        <a href="{{ route('zendy.users.index') }}" class="btn-app btn-outline-app">Reset</a>
    </form>
</div>

<div class="card-surface" style="padding: 0; overflow: hidden;">
    <div class="table-wrap" style="border: none;">
        <table class="table-app">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Campus</th>
                    <th>Course</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>{{ $user->fname }} {{ $user->lname }}</td>
                        <td>{{ $user->email }}</td>
                        <td><span class="badge-app">{{ $roles[$user->role] ?? ucfirst($user->role) }}</span></td>
                        <td>{{ $user->campus ?? '—' }}</td>
                        <td>{{ $user->course ?? '—' }}</td>
                        <td style="white-space: nowrap;">
                            <a href="{{ route('zendy.users.edit', $user) }}" class="btn-app btn-outline-app btn-sm-app">Edit</a>
                            @if($user->id !== auth()->id())
                            <form action="{{ route('zendy.users.destroy', $user) }}" method="POST" style="display: inline;" onsubmit="return confirm('Delete this user?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-app btn-danger-app btn-sm-app">Delete</button>
                            </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 32px;">No users found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding: 16px 20px; display: flex; justify-content: center;">
        {{ $users->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
