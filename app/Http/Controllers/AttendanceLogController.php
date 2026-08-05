<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AttendanceLog;
use App\Models\GateDevice;
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

        $kiosks = GateDevice::query()->orderBy('name')->get(['id', 'name', 'is_active']);
        $kioskNameOptions = collect();
        if (Schema::hasColumn('attendance_logs', 'kiosk_name')) {
            $kioskNameOptions = AttendanceLog::query()
                ->whereNotNull('kiosk_name')
                ->where('kiosk_name', '!=', '')
                ->distinct()
                ->orderBy('kiosk_name')
                ->pluck('kiosk_name');
        }

        return view('attendance_logs.index', compact(
            'logs',
            'summary',
            'yearOptions',
            'homeroomSections',
            'policy',
            'kiosks',
            'kioskNameOptions',
        ));
    }

    /** @return array{total: int, in: int, late: int, out: int, today: int} */
    private function summaryForQuery($query, AttendancePolicyService $policy): array
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->toDateString();

        $lateQuery = $policy->applyLatePredicate(
            $policy->restrictToFirstInOfDay((clone $query)->where('status', 'IN')),
            late: true
        );

        $lateIdSubquery = (clone $lateQuery)->reorder()->select('attendance_logs.id');

        return [
            'total' => (clone $query)->count(),
            // On-time first IN + any later same-day IN (afternoon return, etc.).
            'in' => (clone $query)->where('status', 'IN')->whereNotIn('attendance_logs.id', $lateIdSubquery)->count(),
            'late' => (clone $lateQuery)->count(),
            'out' => (clone $query)->where('status', 'OUT')->count(),
            'today' => (clone $query)->whereDate('scanned_at', $today)->count(),
        ];
    }

    private function filteredLogs(Request $request, AttendancePolicyService $policy)
    {
        $classification = strtoupper((string) ($request->classification ?: $request->status));

        $query = AttendanceLog::with(['student', 'gateDevice']);
        \App\Support\AdvisoryScope::applyToAttendanceLogs($query);

        return $query
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

            ->when($request->gate_device_id,
                fn ($q) => $q->where('gate_device_id', $request->gate_device_id))

            ->when($request->kiosk_name,
                fn ($q) => $q->where('kiosk_name', $request->kiosk_name))

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
                    })->orWhere('kiosk_name', 'like', "%{$search}%");
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