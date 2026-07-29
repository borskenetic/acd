<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AttendanceLog;
use App\Models\GradeSection;
use App\Models\Student;
use Illuminate\Support\Facades\Schema;
use App\Services\PatronAttendanceReportService;
use App\Services\AttendancePolicyService;
use App\Support\PatronOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AttendanceLogsExport;

class AttendanceLogController extends Controller
{
    public function index(Request $request, AttendancePolicyService $policy)
    {
        $baseQuery = $this->filteredLogs($request, $policy);

        $logs = (clone $baseQuery)
            ->paginate(25)
            ->withQueryString();

        $summary = $this->summaryForQuery(clone $baseQuery, $policy);

        $yearOptions = PatronOptions::allYearOptions();

        $homeroomSections = collect();
        if (Schema::hasTable('grade_sections')) {
            $homeroomSections = $homeroomSections->merge(
                GradeSection::query()->orderBy('section')->pluck('section')
            );
        }
        $homeroomSections = $homeroomSections
            ->merge(
                Student::query()
                    ->whereNotNull('section')
                    ->where('section', '!=', '')
                    ->distinct()
                    ->orderBy('section')
                    ->pluck('section')
            )
            ->unique()
            ->sort()
            ->values();

        return view('attendance_logs.index', compact(
            'logs',
            'summary',
            'yearOptions',
            'homeroomSections',
            'policy',
        ));
    }

    /** @return array{total: int, in: int, late: int, out: int, today: int} */
    private function summaryForQuery($query, AttendancePolicyService $policy): array
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->toDateString();

        return [
            'total' => (clone $query)->count(),
            'in' => $policy->applyLatePredicate(
                (clone $query)->where('status', 'IN'),
                late: false
            )->count(),
            'late' => $policy->applyLatePredicate(
                (clone $query)->where('status', 'IN'),
                late: true
            )->count(),
            'out' => (clone $query)->where('status', 'OUT')->count(),
            'today' => (clone $query)->whereDate('scanned_at', $today)->count(),
        ];
    }

    private function filteredLogs(Request $request, AttendancePolicyService $policy)
    {
        $classification = strtoupper((string) ($request->classification ?: $request->status));

        return AttendanceLog::with('student')

            ->when($request->from,
                fn($q) => $q->whereDate('scanned_at', '>=', $request->from))

            ->when($request->to,
                fn($q) => $q->whereDate('scanned_at', '<=', $request->to))

            ->when($request->year ?: $request->year_level,
                fn ($q) => $q->whereHas('student',
                    fn ($q2) => $q2->where('year', $request->year ?: $request->year_level)
                ))

            ->when($request->homeroom_section,
                fn ($q) => $q->whereHas('student',
                    fn ($q2) => $q2->where('section', $request->homeroom_section)
                ))

            ->when($request->status && ! $request->classification,
                fn ($q) => $q->where('status', strtoupper((string) $request->status))
            )

            ->when(in_array($classification, ['IN', 'LATE', 'OUT'], true),
                fn ($q) => $policy->applyClassificationFilter($q, $classification)
            )

            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;

                $q->where(function ($query) use ($search) {
                    $query->whereHas('student', function ($q2) use ($search) {
                        $q2->where('firstname', 'like', "%{$search}%")
                            ->orWhere('lastname', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%");
                    });
                });
            })

            ->orderBy('scanned_at', 'desc');
    }

    public function create()
    {
        $students = Student::all();
        return view('attendance_logs.create', compact('students'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'status' => 'required|in:in,out',
            'scanned_at' => 'required|date',
        ]);

        AttendanceLog::create($request->only(['student_id', 'status', 'scanned_at']));

        return redirect()->route('attendance_logs.index')
            ->with('success', 'Attendance logged!');
    }

    public function exportPdf(Request $request, AttendancePolicyService $policy)
    {
        $logs = $this->filteredLogs($request, $policy)->get();

        $pdf = Pdf::loadView('attendance_logs.pdf', compact('logs'));
        return $pdf->download('attendance_logs.pdf');
    }

    public function exportExcel(Request $request, AttendancePolicyService $policy)
    {
        $logs = $this->filteredLogs($request, $policy)->get();

        return Excel::download(
            new AttendanceLogsExport($logs),
            'attendance_logs.xlsx'
        );
    }

    public function reportsHub()
    {
        return view('attendance_logs.reports_hub');
    }

    public function reportsDashboard(Request $request, PatronAttendanceReportService $patronReports)
    {
        $programNameByCode = collect();
        $only = $request->query('only');
        $from = $request->query('from');
        $to = $request->query('to');

        return view('attendance_logs.reports_dashboard', array_merge(
            compact('programNameByCode', 'only', 'from', 'to'),
            $patronReports->build($from, $to)
        ));
    }

    public function reportsExportCsv(Request $request, PatronAttendanceReportService $patronReports)
    {
        return $patronReports->streamCsvResponse(
            $request->query('from'),
            $request->query('to')
        );
    }
}