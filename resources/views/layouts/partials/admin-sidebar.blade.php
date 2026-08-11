@php
    $isActive = fn (array $patterns) => collect($patterns)->contains(fn ($pattern) => request()->routeIs($pattern));
    $user = Auth::user();
    $isFaculty = $user && $user->role === 'faculty';
    $isStaffish = $user && $user->isSchoolOps();
    $isSuperAdmin = $user && $user->isSuperAdmin();

    $attendanceChildren = [
        ['label' => 'Gate Terminal',      'route' => 'attendance.scan',        'patterns' => ['attendance.scan', 'attendance.process'], 'icon' => 'scan', 'target' => '_blank'],
    ];
    if (config('face.enabled')) {
        $attendanceChildren[] = ['label' => 'Face Gate Terminal', 'route' => 'attendance.face', 'patterns' => ['attendance.face', 'attendance.face.identify'], 'icon' => 'scan', 'target' => '_blank'];
    }
    if ($isStaffish) {
        $attendanceChildren[] = ['label' => 'Manage Video', 'route' => 'attendance.changeVideo', 'patterns' => ['attendance.changeVideo', 'attendance.uploadVideo'], 'icon' => 'settings'];
        $attendanceChildren[] = ['label' => 'Issue Visitor Pass', 'route' => 'visitors.issue.create', 'patterns' => ['visitors.issue.*'], 'icon' => 'badge'];
    }

    $reportsChildren = [
        ['label' => 'School Form 2 (SF2)', 'route' => 'sf2.index',                    'patterns' => ['sf2.*'], 'icon' => 'book'],
        ['label' => 'Attendance Logs',     'route' => 'attendance_logs.index',        'patterns' => ['attendance_logs.index', 'attendance_logs.export.*'], 'icon' => 'clock'],
    ];
    if ($isStaffish) {
        $reportsChildren[] = ['label' => 'Patron Reports',      'route' => 'attendance_logs.reports.hub',  'patterns' => ['attendance_logs.reports.*'], 'icon' => 'chart'];
        $reportsChildren[] = ['label' => 'Visitor Logs',        'route' => 'visitor_logs.index',           'patterns' => ['visitor_logs.*'],              'icon' => 'clock'];
    }
    // Staff has no Admin menu — surface full activity here
    if ($user && $user->role === 'staff') {
        $reportsChildren[] = ['label' => 'Activity Log', 'route' => 'activity_logs.index', 'patterns' => ['activity_logs.*'], 'icon' => 'list'];
    }

    $navLinks = [
        [
            'label'    => 'Home',
            'route'    => 'home',
            'patterns' => ['home'],
            'icon'     => 'home',
        ],
        [
            'label'    => 'Zendy Portal',
            'route'    => 'zendy.home',
            'patterns' => ['zendy.*'],
            'icon'     => 'book',
            'target'   => '_blank',
        ],
        [
            'label'    => 'Attendance',
            'icon'     => 'calendar-check',
            'patterns' => ['attendance.scan', 'attendance.face', 'attendance.face.identify', 'attendance.process', 'attendance.section', 'attendance.changeVideo', 'attendance.uploadVideo', 'visitors.issue.*'],
            'children' => $attendanceChildren,
        ],
        [
            'label'    => 'Reports',
            'icon'     => 'chart',
            'patterns' => ['sf2.*', 'attendance_logs.*', 'visitor_logs.*', 'activity_logs.*'],
            'children' => $reportsChildren,
        ],
        [
            'label'    => 'Data',
            'icon'     => 'users',
            'patterns' => ['students.*', 'pending.index', 'students.pending', 'employees.*', 'pending.employees'],
            'children' => array_values(array_filter([
                ['label' => 'Students',  'route' => 'students.index',  'patterns' => ['students.*', 'pending.index', 'students.pending'], 'icon' => 'users'],
                $isStaffish ? ['label' => 'Employees', 'route' => 'employees.index', 'patterns' => ['employees.*', 'pending.employees'], 'icon' => 'badge'] : null,
            ])),
        ],
    ];

    if ($isStaffish) {
        $navLinks[] = [
            'label'    => 'Communication',
            'icon'     => 'message',
            'patterns' => ['feedback.index', 'sms.*'],
            'children' => [
                ['label' => 'Feedback',        'route' => 'feedback.index',  'patterns' => ['feedback.index'],                            'icon' => 'message'],
                ['label' => 'SMS Blast',       'route' => 'sms.page',        'patterns' => ['sms.page', 'sms.send'],                      'icon' => 'send'],
                ['label' => 'SMS Logs',        'route' => 'sms.logs',        'patterns' => ['sms.logs'],                                  'icon' => 'list'],
                ['label' => 'Gate Terminal Message', 'route' => 'sms.scanMessage', 'patterns' => ['sms.scanMessage', 'sms.scanMessage.update'], 'icon' => 'settings'],
            ],
        ];
    } elseif ($user && $user->role === 'faculty' && \App\Support\AdvisoryScope::canManageAnyClass($user)) {
        $navLinks[] = [
            'label'    => 'Communication',
            'icon'     => 'message',
            'patterns' => ['sms.page', 'sms.send', 'sms.count'],
            'children' => [
                ['label' => 'SMS Blast (my classes)', 'route' => 'sms.page', 'patterns' => ['sms.page', 'sms.send'], 'icon' => 'send'],
            ],
        ];
    }

    $adminChildren = [
        ['label' => 'School Setup', 'route' => 'school-setup.index', 'patterns' => ['school-setup.*', 'prospectus.*'], 'icon' => 'grid'],
        ['label' => 'School Calendar', 'route' => 'school_calendar.index', 'patterns' => ['school_calendar.*'], 'icon' => 'calendar-check'],
        ['label' => 'Attendance Policy', 'route' => 'attendance.policy.settings', 'patterns' => ['attendance.policy.settings', 'attendance.policy.settings.update'], 'icon' => 'clock'],
        ['label' => 'Kiosks', 'route' => 'gate_devices.index', 'patterns' => ['gate_devices.*'], 'icon' => 'scan'],
    ];
    // Activity log: super = all; band admins = department; shown under Admin for platform admins
    if ($isSuperAdmin || ($user && $user->isBandAdmin())) {
        $adminChildren[] = ['label' => 'Activity Log', 'route' => 'activity_logs.index', 'patterns' => ['activity_logs.*'], 'icon' => 'list'];
    }
    if ($isSuperAdmin) {
        $adminChildren[] = [
            'label'    => 'Accounts',
            'icon'     => 'user-plus',
            'patterns' => ['users.*'],
            'children' => [
                ['label' => 'Create Account', 'route' => 'users.create', 'patterns' => ['users.create', 'users.store'], 'icon' => 'user-plus'],
                ['label' => 'View Accounts',  'route' => 'users.index',  'patterns' => ['users.index', 'users.edit'],   'icon' => 'list'],
            ],
        ];
    }

    if ($isFaculty) {
        $navLinks[] = [
            'label'    => 'My Activity',
            'route'    => 'activity_logs.index',
            'patterns' => ['activity_logs.*'],
            'icon'     => 'list',
        ];
    }
    $icon = function (string $name) {
        return match ($name) {
            'home'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
            'book'      => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M4 5.5v16"/><path d="M8 7h8"/>',
            'scan'            => '<path d="M7 3H4a1 1 0 0 0-1 1v3"/><path d="M17 3h3a1 1 0 0 1 1 1v3"/><path d="M7 21H4a1 1 0 0 1-1-1v-3"/><path d="M17 21h3a1 1 0 0 0 1-1v-3"/><path d="M8 12h8"/>',
            'calendar-check'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>',
            'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'chart'     => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-3"/>',
            'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'badge'     => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M9 9h6"/><path d="M9 13h6"/>',
            'message'   => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/>',
            'send'      => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
            'settings'  => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
            'grid'      => '<path d="M4 4h6v6H4z"/><path d="M14 4h6v6h-6z"/><path d="M4 14h6v6H4z"/><path d="M14 14h6v6h-6z"/>',
            'folder'    => '<path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/>',
            'list'      => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
            'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            default     => '<circle cx="12" cy="12" r="9"/>',
        };
    };
@endphp

@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggle = document.querySelector('[data-admin-sidebar-toggle]');
            const overlay = document.querySelector('[data-admin-sidebar-overlay]');
            const collapseBtn = document.getElementById('sidebarCollapseBtn');
            const body = document.body;

            /* ── Mobile open/close ── */
            const setOpen = (open) => body.classList.toggle('admin-sidebar-open', open);
            toggle?.addEventListener('click', () => setOpen(!body.classList.contains('admin-sidebar-open')));
            overlay?.addEventListener('click', () => setOpen(false));
            window.addEventListener('resize', () => {
                if (window.innerWidth >= 992) setOpen(false);
            });

            /* ── Desktop collapse ── */
            const STORAGE_KEY = 'pantas-sidebar-collapsed';
            const closeAllSubmenus = () => {
                document.querySelectorAll('.admin-sidebar-item.open, .admin-sidebar-subitem.open').forEach(item => {
                    item.classList.remove('open');
                    item.querySelector('[aria-expanded]')?.setAttribute('aria-expanded', 'false');
                });
            };
            const setCollapsed = (collapsed) => {
                body.classList.toggle('sidebar-collapsed', collapsed);
                if (collapsed) closeAllSubmenus();
                try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (_) {}
            };

            try {
                if (localStorage.getItem(STORAGE_KEY) === '1') body.classList.add('sidebar-collapsed');
            } catch (_) {}

            collapseBtn?.addEventListener('click', () => setCollapsed(!body.classList.contains('sidebar-collapsed')));

            /* ── Submenu accordions (level 1 & 2) ── */
            document.querySelectorAll('.admin-sidebar-link--parent').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (body.classList.contains('sidebar-collapsed')) return;
                    const item = btn.closest('.admin-sidebar-item, .admin-sidebar-subitem');
                    if (!item) return;
                    const isOpen = item.classList.toggle('open');
                    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
            });
        });
    </script>
@endonce

<button class="admin-sidebar-toggle" type="button" aria-label="Open admin menu" data-admin-sidebar-toggle>
    <span></span><span></span><span></span>
</button>

<div class="admin-sidebar-overlay" data-admin-sidebar-overlay></div>

<aside class="admin-sidebar" aria-label="Admin sidebar">
    <a href="{{ route('home') }}" class="admin-sidebar-brand">
        <img src="{{ asset('images/pantasLogo.png') }}"
             alt="Assumption College of Davao"
             class="admin-sidebar-brand-img"
             width="3905" height="1056">
        <span class="admin-sidebar-brand-seal" aria-hidden="true">
            <img src="{{ asset('images/pantasLogo.png') }}" alt="" width="3905" height="1056">
        </span>
        <span class="admin-sidebar-brand-role">
            {{ ucfirst($user->role ?? 'Admin') }} Dashboard
        </span>
    </a>

    <nav class="admin-sidebar-nav">

        {{-- ── Main navigation ── --}}
        @foreach($navLinks as $link)
            @php
                $hasChildren    = !empty($link['children']);
                $linkActive     = $isActive($link['patterns']);
                $anyChildActive = $hasChildren && collect($link['children'])->contains(fn($c) => $isActive($c['patterns']));
                $open           = $linkActive || $anyChildActive;
            @endphp

            @if($hasChildren)
                <div class="admin-sidebar-item {{ $open ? 'open' : '' }}">
                    <button class="admin-sidebar-link admin-sidebar-link--parent {{ $open ? 'active' : '' }}"
                            type="button" aria-expanded="{{ $open ? 'true' : 'false' }}"
                            title="{{ $link['label'] }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icon($link['icon']) !!}</svg>
                        <span>{{ $link['label'] }}</span>
                        <svg class="admin-sidebar-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="admin-sidebar-submenu">
                        @foreach($link['children'] as $child)
                            @php $childActive = $isActive($child['patterns']); @endphp
                            <a href="{{ route($child['route']) }}"
                               class="admin-sidebar-link admin-sidebar-link--child {{ $childActive ? 'active' : '' }}"
                               title="{{ $child['label'] }}"
                               @if(!empty($child['target'])) target="{{ $child['target'] }}" rel="noopener" @endif
                               @if($childActive) aria-current="page" @endif>
                                <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icon($child['icon']) !!}</svg>
                                <span>{{ $child['label'] }}</span>  
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                <a href="{{ route($link['route']) }}"
                   class="admin-sidebar-link {{ $linkActive ? 'active' : '' }}"
                   title="{{ $link['label'] }}"
                   @if(!empty($link['target'])) target="{{ $link['target'] }}" rel="noopener" @endif
                   @if($linkActive) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icon($link['icon']) !!}</svg>
                    <span>{{ $link['label'] }}</span>
                    <svg class="admin-sidebar-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            @endif
        @endforeach

        {{-- ── Admin dropdown (isAdmin only) ── --}}
        @can('isAdmin')
            @php
                $adminActive = collect($adminChildren)->contains(function ($child) use ($isActive) {
                    if ($isActive($child['patterns'])) return true;
                    return !empty($child['children']) && collect($child['children'])->contains(fn($gc) => $isActive($gc['patterns']));
                });
            @endphp
            <div class="admin-sidebar-item {{ $adminActive ? 'open' : '' }}">
                <button class="admin-sidebar-link admin-sidebar-link--parent {{ $adminActive ? 'active' : '' }}"
                        type="button" aria-expanded="{{ $adminActive ? 'true' : 'false' }}"
                        title="Admin">
                    <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icon('shield') !!}</svg>
                    <span>Admin</span>
                    <svg class="admin-sidebar-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div class="admin-sidebar-submenu">
                    @foreach($adminChildren as $child)
                        @php
                            $childHasChildren = !empty($child['children']);
                            $childActive      = $isActive($child['patterns']);
                            $anyGrandActive   = $childHasChildren && collect($child['children'])->contains(fn($gc) => $isActive($gc['patterns']));
                            $childOpen        = $childActive || $anyGrandActive;
                        @endphp

                        @if($childHasChildren)
                            <div class="admin-sidebar-subitem {{ $childOpen ? 'open' : '' }}">
                                <button class="admin-sidebar-link admin-sidebar-link--child admin-sidebar-link--parent {{ $childOpen ? 'active' : '' }}"
                                        type="button" aria-expanded="{{ $childOpen ? 'true' : 'false' }}"
                                        title="{{ $child['label'] }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icon($child['icon']) !!}</svg>
                                    <span>{{ $child['label'] }}</span>
                                    <svg class="admin-sidebar-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <div class="admin-sidebar-subsubmenu">
                                    @foreach($child['children'] as $grandchild)
                                        @php $gcActive = $isActive($grandchild['patterns']); @endphp
                                        <a href="{{ route($grandchild['route']) }}"
                                           class="admin-sidebar-link admin-sidebar-link--child {{ $gcActive ? 'active' : '' }}"
                                           title="{{ $grandchild['label'] }}"
                                           @if($gcActive) aria-current="page" @endif>
                                            <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icon($grandchild['icon']) !!}</svg>
                                            <span>{{ $grandchild['label'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <a href="{{ route($child['route']) }}"
                               class="admin-sidebar-link admin-sidebar-link--child {{ $childActive ? 'active' : '' }}"
                               title="{{ $child['label'] }}"
                               @if($childActive) aria-current="page" @endif>
                                <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icon($child['icon']) !!}</svg>
                                <span>{{ $child['label'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        @endcan

    </nav>

    <div class="admin-sidebar-user">
        <div class="admin-sidebar-avatar" title="{{ $user->name }}">
            {{ strtoupper(substr($user->fname ?? $user->name ?? 'A', 0, 1)) }}
        </div>
        <button type="button" class="admin-sidebar-logout-btn" data-bs-toggle="modal" data-bs-target="#logoutModal" aria-label="Log out" title="Log out">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 19V5a2 2 0 0 0-2-2h-4"/></svg>
            <span>Log out</span>
        </button>
    </div>
</aside>

<!-- Logout confirmation modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px">
        <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.18);">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div style="width:48px;height:48px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 19V5a2 2 0 0 0-2-2h-4"/>
                    </svg>
                </div>
            </div>
            <div class="modal-body text-center px-4 pt-3 pb-2">
                <h6 class="fw-700 mb-1" style="font-size:1rem;font-weight:700;color:#111;font-family:'Segoe UI',sans-serif;">Sign out</h6>
                <p class="text-muted mb-0" style="font-size:0.85rem;font-family:'Segoe UI',sans-serif;">Are you sure you want to log out of your account?</p>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 d-flex gap-2 justify-content-center">
                <button type="button" class="btn btn-light btn-sm px-4 fw-600" data-bs-dismiss="modal"
                    style="border-radius:8px;font-weight:600;font-family:'Segoe UI',sans-serif;border:1.5px solid #e5e7eb;">
                    Cancel
                </button>
                <form action="{{ route('logout') }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm px-4"
                        style="border-radius:8px;background:#dc2626;border:none;color:#fff;font-weight:600;font-family:'Segoe UI',sans-serif;">
                        Yes, log out
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
