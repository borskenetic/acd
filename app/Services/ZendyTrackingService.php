<?php

namespace App\Services;

use App\Models\ZendyLog;
use App\Models\ZendyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ZendyTrackingService
{
    public const SESSION_CLICK_ID = 'zendy_click_id';
    public const SESSION_LAUNCHED_AT = 'zendy_launched_at';
    public const SESSION_RETURN_LOGGED = 'zendy_return_logged_for';
    public const SESSION_TAB_CLOSE_LOGGED = 'zendy_tab_close_logged_for';
    public const SESSION_EXTERNAL_NAV = 'zendy_external_nav_for';

    public function logAccess(Request $request, string $action, ?ZendyUser $user = null, array $extra = []): ZendyLog
    {
        $this->recordReturnIfApplicable($request, $user);

        $clickId = (string) Str::uuid();

        $request->session()->put(self::SESSION_CLICK_ID, $clickId);
        $request->session()->put(self::SESSION_LAUNCHED_AT, now()->toIso8601String());

        if ($action === 'go_to_zendy') {
            $request->session()->put(self::SESSION_EXTERNAL_NAV, $clickId);
        }

        $metadata = array_merge([
            'click_id' => $clickId,
            'access_method' => $action,
            'referer' => $request->headers->get('referer'),
            'landing_path' => $request->path(),
            'sso_available' => config('zendy.sso_enabled', false),
        ], $extra);

        return ZendyLog::create([
            'zendy_user_id' => $user?->id,
            'actor_role' => $user?->role,
            'action' => $action,
            'first_name' => $user?->fname ?? $extra['first_name'] ?? null,
            'last_name' => $user?->lname ?? $extra['last_name'] ?? null,
            'email' => $user?->email ?? $extra['email'] ?? null,
            'course' => $user?->course ?? $extra['course'] ?? null,
            'department' => $user?->department ?? $extra['department'] ?? null,
            'campus' => $user?->campus ?? $extra['campus'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    public function recordReturnIfApplicable(Request $request, ?ZendyUser $user = null): void
    {
        $launchedAt = $request->session()->get(self::SESSION_LAUNCHED_AT);
        $clickId = $request->session()->get(self::SESSION_CLICK_ID);

        if (! $launchedAt || ! $clickId) {
            return;
        }

        $alreadyLogged = $request->session()->get(self::SESSION_RETURN_LOGGED);
        if ($alreadyLogged === $clickId) {
            return;
        }

        if ($request->session()->get(self::SESSION_TAB_CLOSE_LOGGED) === $clickId) {
            $request->session()->forget([self::SESSION_LAUNCHED_AT, self::SESSION_CLICK_ID]);

            return;
        }

        $this->createSessionEndLog($request, $user, $clickId, $launchedAt, 'zendy_return', [
            'note' => 'Estimated from time between launch and next portal visit',
        ]);

        $request->session()->put(self::SESSION_RETURN_LOGGED, $clickId);
        $request->session()->forget([self::SESSION_LAUNCHED_AT, self::SESSION_CLICK_ID]);
    }

    public function recordTabClose(Request $request, ?ZendyUser $user, string $clickId, int $durationSeconds): bool
    {
        $sessionClickId = $request->session()->get(self::SESSION_CLICK_ID);
        if ($sessionClickId !== $clickId) {
            return false;
        }

        if ($request->session()->get(self::SESSION_TAB_CLOSE_LOGGED) === $clickId) {
            return false;
        }

        if ($request->session()->get(self::SESSION_RETURN_LOGGED) === $clickId) {
            return false;
        }

        if ($request->session()->get(self::SESSION_EXTERNAL_NAV) === $clickId) {
            $request->session()->forget(self::SESSION_EXTERNAL_NAV);

            return false;
        }

        $launchedAt = $request->session()->get(self::SESSION_LAUNCHED_AT);
        if (! $launchedAt) {
            return false;
        }

        $this->createSessionEndLog($request, $user, $clickId, $launchedAt, 'zendy_tab_close', [
            'estimated_duration_seconds' => $durationSeconds,
            'trigger' => 'tab_close',
            'note' => 'Logged when the user closed or left the portal tab',
        ]);

        $request->session()->put(self::SESSION_TAB_CLOSE_LOGGED, $clickId);
        $request->session()->forget([self::SESSION_LAUNCHED_AT, self::SESSION_CLICK_ID]);

        return true;
    }

    public static function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.' sec';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainder = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d hr %d min', $hours, $minutes);
        }

        if ($remainder > 0) {
            return sprintf('%d min %d sec', $minutes, $remainder);
        }

        return $minutes.' min';
    }

    private function createSessionEndLog(
        Request $request,
        ?ZendyUser $user,
        string $clickId,
        string $launchedAt,
        string $action,
        array $extraMetadata = [],
    ): void {
        $durationSeconds = (int) \Carbon\Carbon::parse($launchedAt)->diffInSeconds(now());

        ZendyLog::create([
            'zendy_user_id' => $user?->id,
            'actor_role' => $user?->role,
            'action' => $action,
            'first_name' => $user?->fname,
            'last_name' => $user?->lname,
            'email' => $user?->email,
            'course' => $user?->course,
            'department' => $user?->department,
            'campus' => $user?->campus,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'metadata' => array_merge([
                'click_id' => $clickId,
                'related_launch_at' => $launchedAt,
                'estimated_duration_seconds' => $durationSeconds,
            ], $extraMetadata),
        ]);
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
}
