@extends('layouts.sec')

@section('title', 'Kiosks / Gate devices')

@section('content')
<div class="container py-4" style="max-width: 960px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h3 class="mb-1">Kiosks / Gate devices</h3>
            <p class="text-muted mb-0 small">
                Give each terminal a name (e.g. <em>Main Gate</em>, <em>SHS Lobby</em>). Pair the browser or offline app with that kiosk so attendance logs show where the scan happened.
            </p>
        </div>
        <a href="{{ route('attendance.scan') }}" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">Open QR terminal</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('issued_token'))
        @php
            $pairToken = session('issued_token');
            $pairName = session('issued_device_name');
            $qrPairUrl = route('attendance.scan', ['pair_token' => $pairToken, 'kiosk_name' => $pairName]);
            $facePairUrl = config('face.enabled')
                ? route('attendance.face', ['pair_token' => $pairToken, 'kiosk_name' => $pairName])
                : null;
        @endphp
        <div class="alert alert-warning">
            <strong>Copy this token now — it will not be shown again.</strong>
            <div class="mt-2">
                <code class="user-select-all">{{ $pairToken }}</code>
            </div>
            <p class="small mt-2 mb-2">
                Kiosk: <strong>{{ $pairName }}</strong>.
                Offline terminal: paste into <code>gate-terminal/config.json</code> as <code>device_token</code>.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-primary" href="{{ $qrPairUrl }}" target="_blank" rel="noopener">
                    Open QR kiosk as this name
                </a>
                @if($facePairUrl)
                    <a class="btn btn-sm btn-outline-primary" href="{{ $facePairUrl }}" target="_blank" rel="noopener">
                        Open face kiosk as this name
                    </a>
                @endif
            </div>
            <p class="small text-muted mb-0 mt-2">On that PC, open the link once so the browser saves the pairing (localStorage). Scans will then show this kiosk name on attendance logs.</p>
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
        <div class="card-header fw-semibold">Register new kiosk</div>
        <div class="card-body">
            <form method="POST" action="{{ route('gate_devices.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-8">
                    <label for="deviceName" class="form-label">Kiosk name</label>
                    <input type="text" name="name" id="deviceName" class="form-control" placeholder="Main gate · SHS lobby · Library entrance" required maxlength="255">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">Generate token</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-semibold">Registered kiosks</div>
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
                            <td>
                                <form method="POST" action="{{ route('gate_devices.update', $device) }}" class="d-flex gap-1 align-items-center">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="name" value="{{ $device->name }}" class="form-control form-control-sm" maxlength="255" required style="min-width: 10rem;">
                                    <input type="hidden" name="is_active" value="{{ $device->is_active ? 1 : 0 }}">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Rename</button>
                                </form>
                            </td>
                            <td>
                                @if($device->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $device->last_seen_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td class="small text-muted">{{ $device->last_sync_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="{{ route('gate_devices.update', $device) }}" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="name" value="{{ $device->name }}">
                                    <input type="hidden" name="is_active" value="{{ $device->is_active ? 0 : 1 }}">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        {{ $device->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('gate_devices.reissue', $device) }}" class="d-inline" onsubmit="return confirm('Generate a new token? The previous token will stop working on all terminals.');">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary">New token</button>
                                </form>
                                <form method="POST" action="{{ route('gate_devices.destroy', $device) }}" class="d-inline" onsubmit="return confirm('Remove this kiosk? Past log names are kept.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted text-center py-4">No kiosks registered yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header fw-semibold">How pairing works</div>
        <div class="card-body small">
            <ol class="mb-3">
                <li>Create a kiosk with a clear location name.</li>
                <li>Copy the one-time token, or click <strong>Open QR/face kiosk as this name</strong> on that PC.</li>
                <li>Every scan from that browser (or offline terminal using the token) stores the kiosk name on the attendance log.</li>
                <li>Use <strong>New token</strong> if a PC was lost or shared — then re-pair terminals with the new token.</li>
            </ol>
            <p class="mb-2">Offline gate terminal API (Bearer token):</p>
            <ul class="mb-0">
                <li><code>GET {{ url('/api/gate/health') }}</code></li>
                <li><code>GET {{ url('/api/gate/roster') }}?since=ISO8601</code></li>
                <li><code>POST {{ url('/api/gate/attendance') }}</code></li>
            </ul>
        </div>
    </div>
</div>
@endsection
