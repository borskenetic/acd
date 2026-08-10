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

        /** Superadmin only (user accounts, activity log). */
        Gate::define('isSuperAdmin', fn (User $user) => $user->isSuperAdmin());

        /**
         * Platform admins: superadmin + SHS Admin + K–10 Admin.
         * Data for band admins is keyholed via AdvisoryScope.
         */
        Gate::define('isAdmin', fn (User $user) => $user->isPlatformAdmin());

        Gate::define('isStaff', fn (User $user) => $user->role === 'staff');

        Gate::define('isFaculty', fn (User $user) => $user->role === 'faculty');

        Gate::define('isAdminOrStaff', fn (User $user) => $user->isSchoolOps());

        /** Admin tiers, staff, or faculty (adviser portal). */
        Gate::define('isAdminOrStaffOrFaculty', fn (User $user) =>
            $user->isSchoolOps() || $user->role === 'faculty'
        );

        /**
         * Can open student create/edit/delete routes.
         * Faculty: only if they have at least one adviser assignment (enforced further in controller).
         */
        Gate::define('isAdminOrFaculty', fn (User $user) =>
            ($user->isSchoolOps() || $user->role === 'faculty')
                && ($user->role !== 'faculty' || AdvisoryScope::canManageAnyClass($user))
        );

        /** Explicit staff+admin+adviser manage students gate alias. */
        Gate::define('manageStudents', fn (User $user) =>
            $user->isSchoolOps()
            || ($user->role === 'faculty' && AdvisoryScope::canManageAnyClass($user))
        );

        /** Faculty who can SMS their advisory classes only (or school-ops school-wide / band). */
        Gate::define('sendSmsBlast', fn (User $user) =>
            $user->isSchoolOps()
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
