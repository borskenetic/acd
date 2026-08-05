<?php

namespace App\Providers;

use App\Models\User;
use App\Support\AdvisoryScope;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [];

    public function boot()
    {
        $this->registerPolicies();

        Gate::define('isAdmin', fn (User $user) => $user->role === 'admin');

        Gate::define('isStaff', fn (User $user) => $user->role === 'staff');

        Gate::define('isFaculty', fn (User $user) => $user->role === 'faculty');

        Gate::define('isAdminOrStaff', fn (User $user) =>
            in_array($user->role, ['admin', 'staff'], true)
        );

        /** Admin, staff, or faculty (adviser portal). */
        Gate::define('isAdminOrStaffOrFaculty', fn (User $user) =>
            in_array($user->role, ['admin', 'staff', 'faculty'], true)
        );

        /**
         * Can open student create/edit/delete routes.
         * Faculty: only if they have at least one adviser assignment (enforced further in controller).
         */
        Gate::define('isAdminOrFaculty', fn (User $user) =>
            in_array($user->role, ['admin', 'staff', 'faculty'], true)
                && ($user->role !== 'faculty' || AdvisoryScope::canManageAnyClass($user))
        );

        /** Explicit staff+admin+adviser manage students gate alias. */
        Gate::define('manageStudents', fn (User $user) =>
            in_array($user->role, ['admin', 'staff'], true)
            || ($user->role === 'faculty' && AdvisoryScope::canManageAnyClass($user))
        );

        /** Faculty who can SMS their advisory classes only (or admins/staff school-wide). */
        Gate::define('sendSmsBlast', fn (User $user) =>
            in_array($user->role, ['admin', 'staff'], true)
            || ($user->role === 'faculty' && AdvisoryScope::canManageAnyClass($user))
        );

        Gate::define('isStudent', fn (User $user) =>
            in_array($user->role, ['student', 'faculty'], true)
        );

        Gate::define('zendyAdmin', fn () =>
            auth('zendy')->check() && auth('zendy')->user()->role === 'admin'
        );
    }
}
