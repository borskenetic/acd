@extends('layouts.sec')

@section('title', 'Gate devices')

@section('content')
<div class="container py-4" style="max-width: 960px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h3 class="mb-1">Offline gate devices</h3>
            <p class="text-muted mb-0 small">Register gate PCs that run the local terminal app and sync attendance when online.</p>
        </div>
        <a href="{{ route('attendance.scan') }}" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">Online gate terminal</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('issued_token'))
        <div class="alert alert-warning">
            <strong>Copy this token now — it will not be shown again.</strong>
            <div class="mt-2">
                <code class="user-select-all">{{ session('issued_token') }}</code>
            </div>
            <p class="small mb-0 mt-2">Device: {{ session('issued_device_name') }}. Paste into <code>gate-terminal/config.json</code> as <code>device_token</code>.</p>
        </div>
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

    <div class="card mb-4">
        <div class="card-header fw-semibold">Register new device</div>
        <div class="card-body">
            <form method="POST" action="{{ route('gate_devices.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-8">
                    <label for="deviceName" class="form-label">Device name</label>
                    <input type="text" name="name" id="deviceName" class="form-control" placeholder="Main library gate" required maxlength="255">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">Generate token</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-semibold">Registered devices</div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Last seen</th>
                        <th>Last sync</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devices as $device)
                        <tr>
                            <td>{{ $device->name }}</td>
                            <td>
                                @if($device->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $device->last_seen_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td class="small text-muted">{{ $device->last_sync_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('gate_devices.update', $device) }}" class="d-inline-flex gap-1 align-items-center">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="name" value="{{ $device->name }}">
                                    <input type="hidden" name="is_active" value="{{ $device->is_active ? 0 : 1 }}">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        {{ $device->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('gate_devices.destroy', $device) }}" class="d-inline" onsubmit="return confirm('Remove this gate device?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted text-center py-4">No gate devices registered yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header fw-semibold">Sync API</div>
        <div class="card-body small">
            <p class="mb-2">Local gate terminal endpoints (Bearer token required):</p>
            <ul class="mb-0">
                <li><code>GET {{ url('/api/gate/health') }}</code></li>
                <li><code>GET {{ url('/api/gate/roster') }}?since=ISO8601</code></li>
                <li><code>POST {{ url('/api/gate/attendance') }}</code></li>
            </ul>
        </div>
    </div>
</div>
@endsection
