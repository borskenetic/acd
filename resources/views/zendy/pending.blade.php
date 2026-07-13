@extends('layouts.zen')

@section('page_title', 'Pending Registrations')
@section('page_subtitle', 'Review and approve Zendy portal sign-up requests')

@section('content')
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
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pendingUsers as $user)
                    <tr>
                        <td>{{ $user->fname }} {{ $user->lname }}</td>
                        <td>{{ $user->email }}</td>
                        <td><span class="badge-app">{{ \App\Models\ZendyUser::roleOptions()[$user->role] ?? ucfirst($user->role) }}</span></td>
                        <td>{{ $user->campus ?? '—' }}</td>
                        <td>{{ \App\Models\ZendyUser::isStudentRole($user->role) ? ($user->course ?? '—') : '—' }}</td>
                        <td style="white-space: nowrap;">{{ $user->created_at->format('M d, Y') }}</td>
                        <td style="white-space: nowrap;">
                            <form action="{{ route('zendy.pending.approve', $user) }}" method="POST" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn-app btn-primary-app btn-sm-app">Approve</button>
                            </form>
                            <form action="{{ route('zendy.pending.reject', $user) }}" method="POST" style="display: inline;" onsubmit="return confirm('Reject this registration?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-app btn-danger-app btn-sm-app">Reject</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 32px;">No pending registrations</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding: 16px 20px;">
        {{ $pendingUsers->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
