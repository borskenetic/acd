@extends('layouts.zen')

@section('page_title', 'Edit Portal User')
@section('page_subtitle', 'Update Zendy account details')

@section('content')
<div class="page-header-actions">
    <a href="{{ route('zendy.users.index') }}" class="btn-app btn-outline-app btn-sm-app">← Back to users</a>
</div>

<div class="card-surface form-card" style="max-width: 720px;">
    @if ($errors->any())
        <div class="alert-app alert-danger-app">
            <ul style="margin: 0; padding-left: 18px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('zendy.users.update', $user) }}" method="POST">
        @csrf
        @method('PUT')

        <div class="form-section">
            <h3 class="form-section-title">Account</h3>
            <div class="form-row-2">
                <div class="form-group-app">
                    <label for="fname">First name</label>
                    <input type="text" id="fname" name="fname" value="{{ old('fname', $user->fname) }}" class="form-control-app" required>
                </div>
                <div class="form-group-app">
                    <label for="lname">Last name</label>
                    <input type="text" id="lname" name="lname" value="{{ old('lname', $user->lname) }}" class="form-control-app" required>
                </div>
            </div>
            <div class="form-group-app">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" class="form-control-app" required>
            </div>
            <div class="form-group-app">
                <label for="password">New password</label>
                <input type="password" id="password" name="password" class="form-control-app" minlength="6">
                <p class="form-hint">Leave blank to keep current password.</p>
            </div>
        </div>

        <div class="form-section">
            <h3 class="form-section-title">Role & profile</h3>
            <div class="form-group-app">
                <label for="role">Role</label>
                <select id="role" name="role" class="form-control-app" required>
                    @foreach($roles as $value => $label)
                        <option value="{{ $value }}" {{ old('role', $user->role) === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-row-2">
                <div class="form-group-app">
                    <label for="campus">Campus</label>
                    <input type="text" id="campus" name="campus" value="{{ old('campus', $user->campus) }}" class="form-control-app">
                </div>
                <div class="form-group-app">
                    <label for="department">Department</label>
                    <input type="text" id="department" name="department" value="{{ old('department', $user->department) }}" class="form-control-app">
                </div>
            </div>
            <div class="form-group-app" id="courseField">
                <label for="course">Course</label>
                <input type="text" id="course" name="course" value="{{ old('course', $user->course) }}" class="form-control-app">
            </div>
        </div>

        <div class="form-actions">
            <a href="{{ route('zendy.users.index') }}" class="btn-app btn-outline-app">Cancel</a>
            <button type="submit" class="btn-app btn-primary-app">Save changes</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var roleSelect = document.getElementById('role');
    var courseField = document.getElementById('courseField');
    var courseInput = document.getElementById('course');
    function sync() {
        var isStudent = roleSelect.value === 'student';
        courseField.style.display = isStudent ? '' : 'none';
        courseInput.required = isStudent;
    }
    roleSelect.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
