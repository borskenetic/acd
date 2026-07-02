<?php

namespace App\Providers;

use App\Models\User;
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

        Gate::define('isAdminOrStaff', fn (User $user) =>
            in_array($user->role, ['admin', 'staff'])
        );

        Gate::define('isStudent', fn (User $user) =>
            in_array($user->role, ['student', 'faculty'])
        );

        Gate::define('zendyAdmin', fn () =>
            auth('zendy')->check() && auth('zendy')->user()->role === 'admin'
        );
    }
}
