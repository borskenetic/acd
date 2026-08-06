@php
    $linkActive = fn (array $patterns) => collect($patterns)->contains(fn ($p) => request()->routeIs($p)) ? 'active' : '';
    $dropActive = fn (array $patterns) => collect($patterns)->contains(fn ($p) => request()->routeIs($p)) ? 'active' : '';
@endphp

<div class="pantas-header d-flex align-items-center px-4 py-2 flex-wrap">
    <a href="{{ route('home') }}">
        <img src="{{ asset('images/pantasLogo.png') }}" alt="{{ config('app.name') }}" class="header-logo-img">
    </a>

    <button id="customMenuToggle" class="d-md-none toggle-btn" type="button" aria-label="Open menu">&#9776;</button>

    <div id="routeWrapper" class="ms-auto responsive-nav">
        <button id="customMenuClose" class="d-md-none close-btn" type="button" aria-label="Close menu">&times;</button>

        <a href="{{ route('home') }}" class="btn0 btn-sm {{ $linkActive(['home']) }}">Home</a>

        @auth('zendy')
            <a href="{{ route('zendy.home') }}" class="btn0 btn-sm {{ $linkActive(['zendy.*']) }}">Zendy Portal</a>
        @else
            <a href="{{ route('zendy.login') }}" class="btn0 btn-sm {{ $linkActive(['zendy.login', 'zendy.register']) }}">Zendy Portal</a>
        @endauth

        @auth('web')
            @can('isAdminOrStaff')
                <div class="nav-dropdown">
                    <button type="button" class="nav-dropdown-button {{ $dropActive(['attendance.scan', 'attendance.face', 'attendance.process', 'attendance.section', 'attendance.changeVideo', 'attendance.uploadVideo']) }}">
                        Attendance
                    </button>
                    <div class="nav-dropdown-content">
                        <a href="{{ route('attendance.scan') }}" target="_blank" rel="noopener" class="{{ $linkActive(['attendance.scan']) }}">Gate Terminal</a>
                        @if(config('face.enabled'))
                            <a href="{{ route('attendance.face') }}" target="_blank" rel="noopener" class="{{ $linkActive(['attendance.face']) }}">Face Gate Terminal</a>
                        @endif
                        <a href="{{ route('attendance.changeVideo') }}" class="{{ $linkActive(['attendance.changeVideo', 'attendance.uploadVideo']) }}">Manage Video</a>
                    </div>
                </div>

                <div class="nav-dropdown">
                    <button type="button" class="nav-dropdown-button {{ $dropActive(['sf2.*', 'attendance_logs.*', 'visitor_logs.*']) }}">
                        Reports
                    </button>
                    <div class="nav-dropdown-content">
                        <a href="{{ route('sf2.index') }}" class="{{ $linkActive(['sf2.*']) }}">School Form 2 (SF2)</a>
                        <a href="{{ route('attendance_logs.index') }}" class="{{ $linkActive(['attendance_logs.index', 'attendance_logs.export.*']) }}">Attendance Logs</a>
                        <a href="{{ route('attendance_logs.reports.hub') }}" class="{{ $linkActive(['attendance_logs.reports.*']) }}">Patron Reports</a>
                        <a href="{{ route('visitor_logs.index') }}" class="{{ $linkActive(['visitor_logs.*']) }}">Visitor Logs</a>
                    </div>
                </div>

                <div class="nav-dropdown">
                    <button type="button" class="nav-dropdown-button {{ $dropActive(['students.index', 'students.create', 'students.edit', 'students.report', 'employees.*', 'pending.index', 'pending.employees', 'students.pending']) }}">
                        Data
                    </button>
                    <div class="nav-dropdown-content">
                        <a href="{{ route('students.index') }}" class="{{ $linkActive(['students.index', 'students.create', 'students.edit', 'students.report']) }}">Students</a>
                        <a href="{{ route('employees.index') }}" class="{{ $linkActive(['employees.index', 'employees.create', 'employees.edit']) }}">Employees</a>
                    </div>
                </div>

                <div class="nav-dropdown">
                    <button type="button" class="nav-dropdown-button {{ $dropActive(['feedback.*', 'sms.*']) }}">
                        Communication
                    </button>
                    <div class="nav-dropdown-content">
                        <a href="{{ route('feedback.index') }}" class="{{ $linkActive(['feedback.index']) }}">Feedback</a>
                        <a href="{{ route('sms.page') }}" class="{{ $linkActive(['sms.page', 'sms.send']) }}">SMS blast</a>
                        <a href="{{ route('sms.logs') }}" class="{{ $linkActive(['sms.logs']) }}">SMS logs</a>
                        <a href="{{ route('sms.scanMessage') }}" class="{{ $linkActive(['sms.scanMessage', 'sms.scanMessage.update']) }}">Gate terminal message</a>
                    </div>
                </div>

                @can('isAdmin')
                    <div class="nav-dropdown">
                        <button type="button" class="nav-dropdown-button {{ $dropActive(['users.*', 'school-setup.*', 'prospectus.*']) }}">
                            Admin
                        </button>
                        <div class="nav-dropdown-content">
                            <a href="{{ route('school-setup.index') }}" class="{{ $linkActive(['school-setup.*', 'prospectus.*']) }}">School Setup</a>
                            <a href="{{ route('users.create') }}" class="{{ $linkActive(['users.create', 'users.store']) }}">Create Account</a>
                            <a href="{{ route('users.index') }}" class="{{ $linkActive(['users.index', 'users.edit']) }}">View Accounts</a>
                        </div>
                    </div>
                @endcan

                <form action="{{ route('logout') }}" method="POST" class="d-inline mb-0">
                    @csrf
                    <button type="submit" class="btn5">Logout</button>
                </form>
            @else
                <form action="{{ route('logout') }}" method="POST" class="d-inline mb-0">
                    @csrf
                    <button type="submit" class="btn5">Logout</button>
                </form>
            @endcan
        @else
            <a href="{{ route('patron.register') }}" class="btn2 btn-sm {{ $linkActive(['patron.register', 'pending.store', 'pendingEmployee.store']) }}">Register</a>
            <a href="{{ route('login') }}" class="btn5 btn-sm" style="text-decoration:none;display:inline-block;">Login</a>
        @endauth
    </div>
</div>

<script>
(function () {
    const toggleBtn = document.getElementById('customMenuToggle');
    const closeBtn = document.getElementById('customMenuClose');
    const routeWrapper = document.getElementById('routeWrapper');
    if (!toggleBtn || !routeWrapper) return;

    toggleBtn.addEventListener('click', () => routeWrapper.classList.add('open'));
    closeBtn?.addEventListener('click', () => routeWrapper.classList.remove('open'));
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) routeWrapper.classList.remove('open');
    });

    if (window.innerWidth < 769) {
        document.querySelectorAll('.pantas-header .nav-dropdown-button').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                btn.closest('.nav-dropdown')?.classList.toggle('open');
            });
        });
    }
})();
</script>
