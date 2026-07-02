@extends('layouts.zendy-guest')

@section('title', 'Sign In')

@section('content')
<div class="logo-wrap">
    <img src="{{ \App\Support\VersionedAsset::url('images/d.png') }}" alt="{{ config('app.name') }}">
</div>

<h1>Welcome back</h1>
<p class="subtitle">Sign in to access Zendy research resources</p>

@if(session('success'))
    <div class="alert-app alert-success-app">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert-app alert-danger-app">{{ session('error') }}</div>
@endif

<form method="POST" action="{{ route('zendy.login') }}">
    @csrf
    <div class="form-group-app">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
    </div>
    <div class="form-group-app">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; font-size: 0.88rem;">
        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
            <input type="checkbox" name="remember" id="remember">
            Remember me
        </label>
    </div>

    @error('email')
        <div class="alert-app alert-danger-app">{{ $message }}</div>
    @enderror

    <button type="submit" class="btn-app btn-primary-app" style="width: 100%; padding: 14px;">Sign in</button>
</form>

<div class="guest-links">
    Don't have an account? <a href="{{ route('zendy.register') }}">Register online</a>
    &nbsp;·&nbsp;
    <a href="{{ route('home') }}">Back to home</a>
</div>
@endsection
