<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\AdvisoryScope;
use Illuminate\Http\Request;

class PlatformActivityLogController extends Controller
{
    public function index(Request $request, ActivityLogger $logger)
    {
        $viewer = $request->user();
        if (! $viewer) {
            abort(403);
        }

        $visibleUserIds = AdvisoryScope::visibleActivityUserIds($viewer);
        $query = ActivityLog::query()->with('user')->orderByDesc('created_at');

        if ($visibleUserIds !== null) {
            $query->whereIn('user_id', $visibleUserIds !== [] ? $visibleUserIds : [0]);
        }

        if ($request->filled('user_id')) {
            $filterUserId = $request->integer('user_id');
            if ($visibleUserIds !== null && ! in_array($filterUserId, $visibleUserIds, true)) {
                abort(403, 'That user is outside your activity access.');
            }
            $query->where('user_id', $filterUserId);
        }

        if ($request->filled('role')) {
            $query->where('user_role', $request->input('role'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('summary', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(30)->withQueryString();

        $usersQuery = User::query()
            ->orderBy('fname')
            ->orderBy('lname');

        if ($visibleUserIds !== null) {
            $usersQuery->whereIn('id', $visibleUserIds !== [] ? $visibleUserIds : [0]);
        }

        $users = $usersQuery->get(['id', 'fname', 'lname', 'email', 'role']);

        $actionsQuery = ActivityLog::query()->select('action')->distinct()->orderBy('action');
        if ($visibleUserIds !== null) {
            $actionsQuery->whereIn('user_id', $visibleUserIds !== [] ? $visibleUserIds : [0]);
        }
        $actionOptions = $actionsQuery->pluck('action');

        $actionLabels = $logger->actionLabels();

        $scopeLabel = match (true) {
            $viewer->isSuperAdmin() => 'All platform activity',
            $viewer->role === 'staff' => 'All staff and admin activity',
            $viewer->role === 'shs_admin' => 'Your activity and faculty assigned to Grade 11–12',
            $viewer->role === 'k10_admin' => 'Your activity and faculty assigned to Kinder–Grade 10',
            $viewer->role === 'faculty' => 'Your own activity only',
            default => null,
        };

        $canFilterUsers = $visibleUserIds === null || count($visibleUserIds) > 1;

        return view('activity_logs.index', compact(
            'logs',
            'users',
            'actionOptions',
            'actionLabels',
            'scopeLabel',
            'canFilterUsers',
        ));
    }
}
