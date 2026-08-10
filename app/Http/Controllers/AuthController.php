<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function showLogin()
    {
        if (Auth::guard('web')->check()) {
            $role = Auth::guard('web')->user()->role;
            if (in_array($role, ['admin', 'staff', 'shs_admin', 'k10_admin'], true)) {
                return redirect()->route('home');
            }

            return $this->redirectForRole($role);
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $this->activityLogger->logAuthEvent(
                'auth.login.success',
                'Logged in',
                Auth::guard('web')->user(),
                $request,
                ['email' => $request->input('email')],
            );

            return $this->redirectForRole(Auth::guard('web')->user()->role);
        }

        $this->activityLogger->logAuthEvent(
            'auth.login.failed',
            'Failed login attempt',
            null,
            $request,
            ['email' => $request->input('email')],
        );

        return back()->withErrors([
            'email' => 'Invalid credentials.',
        ]);
    }

    public function logout(Request $request)
    {
        $user = Auth::guard('web')->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->activityLogger->logAuthEvent(
            'auth.logout',
            'Logged out',
            $user,
            $request,
        );

        return redirect()->route('login');
    }

    private function redirectForRole(?string $role)
    {
        return match ($role) {
            'admin', 'staff', 'shs_admin', 'k10_admin' => redirect()->route('home'),
            'student', 'faculty' => redirect()->route('attendance.scan'),
            default => redirect()->route('login')->with('error', 'Unauthorized role.'),
        };
    }
}
