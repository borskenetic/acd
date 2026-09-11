<?php

namespace App\Http\Controllers;

use App\Exports\MissedOutsExport;
use App\Models\GradeSection;
use App\Models\Student;
use App\Services\MissedOutsReportService;
use App\Support\AdvisoryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class MissedOutsReportController extends Controller
{
    public function index(Request $request, MissedOutsReportService $service)
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->toDateString();

        if (! $request->filled('from') && ! $request->filled('to') && $request->input('period') !== 'all') {
            return redirect()->route('missed_outs.index', array_merge(
                $request->query(),
                ['from' => $today, 'to' => $today]
            ));
        }

        $logs = $service->paginate($request);
        $summary = $service->summary($request);
        $yearOptions = AdvisoryScope::yearOptions(auth()->user());

        $homeroomSections = collect();
        if (Schema::hasTable('grade_sections')) {
            $sectionsQuery = GradeSection::query()->orderBy('section');
            $allowed = auth()->user()?->allowedGradeLevels();
            if (is_array($allowed)) {
                $sectionsQuery->whereIn('grade_level', $allowed !== [] ? $allowed : ['__none__']);
            }
            $homeroomSections = $homeroomSections->merge($sectionsQuery->pluck('section'));
        }
        $studentSectionQuery = Student::query()
            ->tap(fn ($q) => AdvisoryScope::applyToStudents($q))
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->distinct()
            ->orderBy('section')
            ->pluck('section');
        $homeroomSections = $homeroomSections
            ->merge($studentSectionQuery)
            ->unique()
            ->sort()
            ->values();

        return view('missed_outs.index', compact('logs', 'summary', 'yearOptions', 'homeroomSections'));
    }

    public function exportExcel(Request $request, MissedOutsReportService $service)
    {
        $logs = $service->collect($request);

        return Excel::download(
            new MissedOutsExport($logs),
            'missed-outs-'.now()->format('Y-m-d').'.xlsx'
        );
    }

    public function exportCsv(Request $request, MissedOutsReportService $service)
    {
        return $service->streamCsvResponse($request);
    }
}
