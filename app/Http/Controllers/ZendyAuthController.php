<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ZendyAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('zendy')->check()) {
            return redirect()->route('zendy.home');
        }

        return view('zendy.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $throttleKey = 'zendy-login:'.strtolower((string) $request->input('email')).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        if (Auth::guard('zendy')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            return redirect()->route('zendy.home');
        }

        RateLimiter::hit($throttleKey, 60);

        return back()->withErrors([
            'email' => 'Invalid Zendy portal credentials.',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('zendy')->logout();

        $request->session()->regenerateToken();

        return redirect()->route('zendy.login');
    }
}
