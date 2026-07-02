<?php

namespace App\Services;

use App\Models\User;
use App\Models\ZendyLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ZendyTrackingService
{
    public const SESSION_CLICK_ID = 'zendy_click_id';
    public const SESSION_LAUNCHED_AT = 'zendy_launched_at';

    public function logAccess(Request $request, string $action, ?User $user = null, array $extra = []): ZendyLog
    {
        $this->recordReturnIfApplicable($request, $user);

        $clickId = (string) Str::uuid();

        $request->session()->put(self::SESSION_CLICK_ID, $clickId);
        $request->session()->put(self::SESSION_LAUNCHED_AT, now()->toIso8601String());

        $profile = $user ? $this->profileSnapshot($user) : [];

        $metadata = array_merge([
            'click_id' => $clickId,
            'access_method' => $action,
            'referer' => $request->headers->get('referer'),
            'landing_path' => $request->path(),
            'sso_available' => config('zendy.sso_enabled', false),
        ], $extra);

        return ZendyLog::create([
            'actor_user_id' => $user?->id,
            'actor_role' => $user?->role,
            'action' => $action,
            'first_name' => $user?->fname ?? $extra['first_name'] ?? null,
            'last_name' => $user?->lname ?? $extra['last_name'] ?? null,
            'email' => $user?->email ?? $extra['email'] ?? null,
            'course' => $profile['course'] ?? $extra['course'] ?? null,
            'department' => $profile['department'] ?? $extra['department'] ?? null,
            'campus' => $profile['campus'] ?? $extra['campus'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    public function recordReturnIfApplicable(Request $request, ?User $user = null): void
    {
        $launchedAt = $request->session()->get(self::SESSION_LAUNCHED_AT);
        $clickId = $request->session()->get(self::SESSION_CLICK_ID);

        if (! $launchedAt || ! $clickId) {
            return;
        }

        $alreadyLogged = $request->session()->get('zendy_return_logged_for');
        if ($alreadyLogged === $clickId) {
            return;
        }

        $launchTime = \Carbon\Carbon::parse($launchedAt);
        $durationSeconds = (int) $launchTime->diffInSeconds(now());
        $profile = $user ? $this->profileSnapshot($user) : [];

        ZendyLog::create([
            'actor_user_id' => $user?->id,
            'actor_role' => $user?->role,
            'action' => 'zendy_return',
            'first_name' => $user?->fname,
            'last_name' => $user?->lname,
            'email' => $user?->email,
            'course' => $profile['course'] ?? null,
            'department' => $profile['department'] ?? null,
            'campus' => $profile['campus'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'metadata' => [
                'click_id' => $clickId,
                'related_launch_at' => $launchedAt,
                'estimated_duration_seconds' => $durationSeconds,
                'note' => 'Estimated from time between launch and next portal visit',
            ],
        ]);

        $request->session()->put('zendy_return_logged_for', $clickId);
        $request->session()->forget([self::SESSION_LAUNCHED_AT, self::SESSION_CLICK_ID]);
    }

    public function baseQuery(Request $request)
    {
        $query = ZendyLog::query();

        if ($request->filled('search_name')) {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%'.$request->search_name.'%')
                    ->orWhere('last_name', 'like', '%'.$request->search_name.'%');
            });
        }

        if ($request->filled('search_course')) {
            $query->where('course', 'like', '%'.$request->search_course.'%');
        }

        if ($request->filled('search_campus')) {
            $query->where('campus', 'like', '%'.$request->search_campus.'%');
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        return $query;
    }

    private function profileSnapshot(User $user): array
    {
        $student = $user->relationLoaded('student') ? $user->student : $user->student()->first();

        return [
            'course' => $user->course ?? $student?->course,
            'department' => $user->department,
            'campus' => $user->campus,
        ];
    }
}
