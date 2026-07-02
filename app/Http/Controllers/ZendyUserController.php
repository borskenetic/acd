<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ZendyUserController extends Controller
{
    private function validationRules(?User $user = null): array
    {
        return [
            'fname' => 'required|string|max:255',
            'lname' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'role' => ['required', Rule::in(User::zendyRoles())],
            'campus' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'course' => 'nullable|string|max:255|required_if:role,student',
            'password' => $user ? 'nullable|string|min:6' : 'required|string|min:6',
        ];
    }

    public function index(Request $request)
    {
        $query = User::query()->whereIn('role', User::zendyRoles());

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('fname', 'like', "%{$search}%")
                    ->orWhere('lname', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        $users = $query->orderBy('lname')->orderBy('fname')->paginate(15)->withQueryString();

        return view('zendy.users.index', [
            'users' => $users,
            'roles' => User::roleOptions(),
        ]);
    }

    public function create()
    {
        return view('zendy.users.create', [
            'roles' => User::roleOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());

        User::create([
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'campus' => $validated['campus'] ?? null,
            'department' => $validated['department'] ?? null,
            'course' => $validated['role'] === 'student' ? ($validated['course'] ?? null) : null,
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->route('zendy.users.index')->with('success', 'Portal user created.');
    }

    public function edit(User $user)
    {
        abort_unless(in_array($user->role, User::zendyRoles(), true), 404);

        return view('zendy.users.edit', [
            'user' => $user,
            'roles' => User::roleOptions(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        abort_unless(in_array($user->role, User::zendyRoles(), true), 404);

        $validated = $request->validate($this->validationRules($user));

        $payload = [
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'campus' => $validated['campus'] ?? null,
            'department' => $validated['department'] ?? null,
            'course' => $validated['role'] === 'student' ? ($validated['course'] ?? null) : null,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        $user->update($payload);

        return redirect()->route('zendy.users.index')->with('success', 'Portal user updated.');
    }

    public function destroy(User $user)
    {
        abort_unless(in_array($user->role, User::zendyRoles(), true), 404);

        if ($user->id === auth()->id()) {
            return redirect()->route('zendy.users.index')->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('zendy.users.index')->with('success', 'Portal user deleted.');
    }
}
