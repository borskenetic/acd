<?php

namespace App\Http\Controllers;

use App\Services\ZendyTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZendyReportController extends Controller
{
    public function __construct(private ZendyTrackingService $tracking) {}

    public function index(Request $request)
    {
        $baseQuery = $this->tracking->baseQuery($request);

        $logs = (clone $baseQuery)->orderBy('created_at', 'desc')->paginate(15);

        $launchActions = ['go_to_zendy', 'zendy_launch', 'zendy_sso', 'zendy_form_submission'];

        $totalLaunches = (clone $baseQuery)->whereIn('action', $launchActions)->count();
        $uniqueUsers = (clone $baseQuery)->whereNotNull('actor_user_id')->distinct('actor_user_id')->count('actor_user_id');
        $estimatedReturns = (clone $baseQuery)->where('action', 'zendy_return')->count();

        $avgDuration = (clone $baseQuery)
            ->where('action', 'zendy_return')
            ->get()
            ->avg(fn ($log) => $log->metadata['estimated_duration_seconds'] ?? null);

        $submissionsByCourse = (clone $baseQuery)
            ->select('course', DB::raw('count(*) as total'))
            ->whereNotNull('course')
            ->groupBy('course')
            ->orderByDesc('total')
            ->get();

        $submissionsByCampus = (clone $baseQuery)
            ->select('campus', DB::raw('count(*) as total'))
            ->whereNotNull('campus')
            ->groupBy('campus')
            ->orderByDesc('total')
            ->get();

        $submissionsByAction = (clone $baseQuery)
            ->select('action', DB::raw('count(*) as total'))
            ->groupBy('action')
            ->orderByDesc('total')
            ->get();

        $submissionsOverTime = (clone $baseQuery)
            ->whereIn('action', $launchActions)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return view('zendy.reports', compact(
            'logs',
            'totalLaunches',
            'uniqueUsers',
            'estimatedReturns',
            'avgDuration',
            'submissionsByCourse',
            'submissionsByCampus',
            'submissionsByAction',
            'submissionsOverTime',
        ));
    }
}
