<?php

namespace App\Http\Controllers;

use App\Models\ZendyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ZendyUserController extends Controller
{
    private function validationRules(?ZendyUser $zendyUser = null): array
    {
        return [
            'fname' => 'required|string|max:255',
            'lname' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('zendy_users', 'email')->ignore($zendyUser?->id),
            ],
            'role' => ['required', Rule::in(array_keys(ZendyUser::roleOptions()))],
            'campus' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'course' => [
                Rule::requiredIf(fn () => ZendyUser::isStudentRole(request('role'))),
                'nullable',
                'string',
                'max:255',
            ],
            'password' => $zendyUser ? 'nullable|string|min:6' : 'required|string|min:6',
        ];
    }

    public function index(Request $request)
    {
        $query = ZendyUser::query();

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
            'roles' => ZendyUser::roleOptions(),
        ]);
    }

    public function create()
    {
        return view('zendy.users.create', [
            'roles' => ZendyUser::roleOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());

        ZendyUser::create([
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'campus' => $validated['campus'] ?? null,
            'department' => $validated['department'] ?? null,
            'course' => ZendyUser::isStudentRole($validated['role']) ? ($validated['course'] ?? null) : null,
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->route('zendy.users.index')->with('success', 'Portal user created.');
    }

    public function edit(ZendyUser $zendyUser)
    {
        return view('zendy.users.edit', [
            'user' => $zendyUser,
            'roles' => ZendyUser::roleOptions(),
        ]);
    }

    public function update(Request $request, ZendyUser $zendyUser)
    {
        $validated = $request->validate($this->validationRules($zendyUser));

        $payload = [
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'campus' => $validated['campus'] ?? null,
            'department' => $validated['department'] ?? null,
            'course' => ZendyUser::isStudentRole($validated['role']) ? ($validated['course'] ?? null) : null,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        $zendyUser->update($payload);

        return redirect()->route('zendy.users.index')->with('success', 'Portal user updated.');
    }

    public function destroy(ZendyUser $zendyUser)
    {
        if ($zendyUser->id === Auth::guard('zendy')->id()) {
            return redirect()->route('zendy.users.index')->with('error', 'You cannot delete your own account.');
        }

        $zendyUser->delete();

        return redirect()->route('zendy.users.index')->with('success', 'Portal user deleted.');
    }
}
