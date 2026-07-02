<?php

namespace App\Http\Controllers;

use App\Models\PendingUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ZendyPendingController extends Controller
{
    public function index()
    {
        $pendingUsers = PendingUser::latest()->paginate(15);

        return view('zendy.pending', compact('pendingUsers'));
    }

    public function approve(PendingUser $pendingUser)
    {
        DB::transaction(function () use ($pendingUser) {
            User::create([
                'fname' => $pendingUser->fname,
                'lname' => $pendingUser->lname,
                'email' => $pendingUser->email,
                'password' => $pendingUser->password,
                'campus' => $pendingUser->campus,
                'department' => $pendingUser->department,
                'course' => $pendingUser->course,
                'role' => $pendingUser->role,
            ]);

            $pendingUser->delete();
        });

        return back()->with('success', 'Registration approved. The user can now sign in.');
    }

    public function reject(PendingUser $pendingUser)
    {
        $pendingUser->delete();

        return back()->with('success', 'Registration rejected.');
    }
}
