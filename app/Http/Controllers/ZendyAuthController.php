<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        if (Auth::guard('zendy')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->route('zendy.home');
        }

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
