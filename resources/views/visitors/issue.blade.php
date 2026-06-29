@extends('layouts.app')

@section('title', 'Issue visitor pass')

@section('content')
<div class="container py-4" style="max-width: 640px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">Issue walk-in visitor pass</h4>
        <a href="{{ route('visitor_logs.index') }}" class="btn btn-outline-secondary btn-sm">Visitor logs</a>
    </div>

    <p class="text-muted small">For visitors without a PC or phone — staff creates the pass and prints or shows the QR at the gate.</p>

    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-body">
            <h5 class="card-title mb-2">Default guardhouse pass</h5>
            <p class="text-muted small mb-3">One reusable QR for walk-ins without a phone. Print it once, keep copies at the guardhouse, and hand a card to each visitor. Collect the card when they leave so the next scan is a fresh check-in.</p>
            <a href="{{ route('visitors.default-pass') }}" class="btn btn-primary">Open &amp; print default pass</a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('visitors.issue.store') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="firstname">First name</label>
                        <input type="text" id="firstname" name="firstname" class="form-control" value="{{ old('firstname') }}" required autofocus>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="lastname">Last name</label>
                        <input type="text" id="lastname" name="lastname" class="form-control" value="{{ old('lastname') }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="organization">Organization</label>
                        <input type="text" id="organization" name="organization" class="form-control" value="{{ old('organization') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="purpose">Purpose</label>
                        <input type="text" id="purpose" name="purpose" class="form-control" value="{{ old('purpose') }}">
                    </div>
                </div>
                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Create &amp; show QR pass</button>
                    <a href="{{ route('visitors.register') }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">Public registration link</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
