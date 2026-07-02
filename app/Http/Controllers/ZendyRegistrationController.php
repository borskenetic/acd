<?php

namespace App\Http\Controllers;

use App\Models\PendingUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ZendyRegistrationController extends Controller
{
    public function create()
    {
        return view('zendy.register');
    }

    public function store(Request $request)
    {
        $allowedRoles = array_diff(array_keys(User::roleOptions()), ['admin']);

        $validated = $request->validate([
            'role' => ['required', Rule::in($allowedRoles)],
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'unique:pending_users,email',
                'unique:users,email',
            ],
            'password' => 'required|min:6',
            'campus' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'course' => 'required_if:role,student|nullable|string|max:255',
        ]);

        PendingUser::create([
            'fname' => $validated['firstname'],
            'lname' => $validated['lastname'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'campus' => $validated['campus'] ?? null,
            'department' => $validated['department'] ?? null,
            'course' => $validated['role'] === 'student' ? ($validated['course'] ?? null) : null,
            'role' => $validated['role'],
        ]);

        return redirect()->route('zendy.login')
            ->with('success', 'Registration submitted. You can sign in once an administrator approves your account.');
    }
}
