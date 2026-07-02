<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ZendyAuthController extends Controller
{
    public function showLogin()
    {
        if (auth()->check()) {
            if (Gate::allows('canAccessZendy')) {
                return redirect()->route('zendy.home');
            }

            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('zendy.login')
                ->with('error', 'Your account does not have Zendy portal access.');
        }

        return view('zendy.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            if (! Gate::allows('canAccessZendy')) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'email' => 'Your account does not have Zendy portal access.',
                ]);
            }

            return redirect()->route('zendy.home');
        }

        return back()->withErrors([
            'email' => 'Invalid credentials.',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('zendy.login');
    }
}
