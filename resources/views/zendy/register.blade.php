@extends('layouts.zendy-guest')

@section('title', 'Register')

@section('content')
<div class="logo-wrap">
    <img src="{{ \App\Support\VersionedAsset::url('images/d.png') }}" alt="{{ config('app.name') }}">
</div>

<h1>Create an account</h1>
<p class="subtitle">Welcome! Create your account to access publications on the Zendy portal — pending admin approval.</p>

@if ($errors->any())
    <div class="alert-app alert-danger-app">
        <ul style="margin: 0; padding-left: 18px;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('zendy.register.store') }}">
    @csrf

    <div class="form-row-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
        <div class="form-group-app">
            <label for="firstname">First name <span aria-hidden="true">*</span></label>
            <input type="text" id="firstname" name="firstname" class="form-control-app" value="{{ old('firstname') }}" placeholder="First name" required>
        </div>
        <div class="form-group-app">
            <label for="lastname">Last name <span aria-hidden="true">*</span></label>
            <input type="text" id="lastname" name="lastname" class="form-control-app" value="{{ old('lastname') }}" placeholder="Last name" required>
        </div>
    </div>

    <div class="form-group-app">
        <label for="role">Role <span aria-hidden="true">*</span></label>
        <select id="role" name="role" class="form-control-app" required>
            <option value="" disabled {{ old('role') ? '' : 'selected' }}>Select role</option>
            @foreach (\App\Models\ZendyUser::registerableRoleOptions() as $value => $label)
                <option value="{{ $value }}" {{ old('role') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="form-group-app">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" class="form-control-app" value="{{ old('email') }}" required>
    </div>

    <div class="form-group-app">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="form-control-app" minlength="6" required>
        <p class="form-hint">At least 6 characters.</p>
    </div>

    <div class="form-row-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
        <div class="form-group-app">
            <label for="campus">Campus</label>
            <input type="text" id="campus" name="campus" class="form-control-app" value="{{ old('campus') }}">
        </div>
        <div class="form-group-app">
            <label for="department">Department</label>
            <input type="text" id="department" name="department" class="form-control-app" value="{{ old('department') }}">
        </div>
    </div>

    <div class="form-group-app" id="wrapCourse">
        <label for="course">Course</label>
        <input type="text" id="course" name="course" class="form-control-app" value="{{ old('course') }}" placeholder="e.g. BSIT">
        <p class="form-hint">Required for students.</p>
    </div>

    <button type="submit" class="btn-app btn-primary-app" style="width: 100%; padding: 14px; margin-top: 8px;">Submit registration</button>
</form>

<div class="guest-links">
    Already have an account? <a href="{{ route('zendy.login') }}">Sign in</a>
    &nbsp;·&nbsp;
    <a href="{{ route('home') }}">Back to home</a>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var roleSelect = document.getElementById('role');
    var wrapCourse = document.getElementById('wrapCourse');
    var courseInput = document.getElementById('course');
    var studentRoles = @json(\App\Models\ZendyUser::studentRoleKeys());

    function syncCourseField() {
        var isStudent = studentRoles.indexOf(roleSelect.value) !== -1;
        wrapCourse.style.display = isStudent ? '' : 'none';
        courseInput.required = isStudent;
        if (!isStudent) {
            courseInput.value = '';
        }
    }

    roleSelect.addEventListener('change', syncCourseField);
    syncCourseField();
})();
</script>
@endpush
