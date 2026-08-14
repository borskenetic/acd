<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function showSessionExpired()
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('home');
        }

        return view('auth.session-expired');
    }

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

        $throttleKey = 'login:'.strtolower((string) $request->input('email')).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        if (Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
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

        RateLimiter::hit($throttleKey, 60);

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
