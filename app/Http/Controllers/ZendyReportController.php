<?php

namespace App\Http\Controllers;

use App\Models\ZendyLog;
use App\Services\ZendyTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ZendyReportController extends Controller
{
    public function __construct(private ZendyTrackingService $tracking) {}

    public function index(Request $request)
    {
        return view('zendy.reports', $this->reportData($request));
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->reportData($request);
        $filename = 'zendy-reports-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $w = static function (array $row) use ($out): void {
                fputcsv($out, $row);
            };

            $w(['Zendy usage reports', 'Exported at', now()->format('Y-m-d H:i')]);
            $w([]);

            $w(['# SUMMARY']);
            $w(['Metric', 'Value']);
            $w(['Total launches', $data['totalLaunches']]);
            $w(['Unique users', $data['uniqueUsers']]);
            $w(['Estimated returns', $data['estimatedReturns']]);
            $w([
                'Avg. time away',
                $data['avgDuration'] ? gmdate('H:i:s', (int) $data['avgDuration']) : '—',
            ]);
            $w([]);

            $w(['# LAUNCHES BY COURSE']);
            $w(['Course', 'Total']);
            foreach ($data['submissionsByCourse'] as $row) {
                $w([$row->course, $row->total]);
            }
            $w([]);

            $w(['# LAUNCHES BY CAMPUS']);
            $w(['Campus', 'Total']);
            foreach ($data['submissionsByCampus'] as $row) {
                $w([$row->campus, $row->total]);
            }
            $w([]);

            $w(['# BY EVENT TYPE']);
            $w(['Event', 'Total']);
            foreach ($data['submissionsByAction'] as $row) {
                $actionLabel = (new ZendyLog(['action' => $row->action]))->actionLabel();
                $w([$actionLabel, $row->total]);
            }
            $w([]);

            $w(['# LAUNCHES OVER TIME']);
            $w(['Date', 'Total']);
            foreach ($data['submissionsOverTime'] as $row) {
                $w([$row->date, $row->total]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function reportData(Request $request): array
    {
        $baseQuery = $this->tracking->baseQuery($request);

        $launchActions = ['go_to_zendy', 'zendy_launch', 'zendy_sso', 'zendy_form_submission'];

        $totalLaunches = (clone $baseQuery)->whereIn('action', $launchActions)->count();
        $uniqueUsers = (clone $baseQuery)->whereNotNull('zendy_user_id')->distinct('zendy_user_id')->count('zendy_user_id');
        $estimatedReturns = (clone $baseQuery)->whereIn('action', ['zendy_return', 'zendy_tab_close'])->count();

        $avgDuration = (clone $baseQuery)
            ->whereIn('action', ['zendy_return', 'zendy_tab_close'])
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

        return compact(
            'totalLaunches',
            'uniqueUsers',
            'estimatedReturns',
            'avgDuration',
            'submissionsByCourse',
            'submissionsByCampus',
            'submissionsByAction',
            'submissionsOverTime',
        );
    }
}
