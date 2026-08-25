<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AttendanceLog;
use App\Models\GateDevice;
use App\Models\GradeSection;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use App\Services\PatronAttendanceReportService;
use App\Services\AttendancePolicyService;
use App\Support\AdvisoryScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AttendanceLogsExport;
use Carbon\Carbon;

class AttendanceLogController extends Controller
{
    public function index(Request $request, AttendancePolicyService $policy)
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->toDateString();

        // Default to today so first paint does not scan 66k+ rows.
        // "All time" is opt-in via period=all (clears from/to).
        if (! $request->filled('from') && ! $request->filled('to') && $request->input('period') !== 'all') {
            return redirect()->route('attendance_logs.index', array_merge(
                $request->query(),
                ['from' => $today, 'to' => $today]
            ));
        }

        $baseQuery = $this->filteredLogs($request, $policy);

        $logs = (clone $baseQuery)
            ->paginate(25)
            ->withQueryString();

        $classifications = $policy->classifyLogs($logs->items());

        $summary = Cache::remember(
            $this->summaryCacheKey($request),
            45,
            fn () => $this->summaryForQuery(clone $baseQuery, $policy)
        );

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

        $kiosks = GateDevice::query()->orderBy('name')->get(['id', 'name', 'is_active']);
        $kioskNameOptions = collect();
        if (Schema::hasColumn('attendance_logs', 'kiosk_name')) {
            $kioskNameOptions = Cache::remember('attendance_logs:kiosk_names', 300, function () {
                return AttendanceLog::query()
                    ->whereNotNull('kiosk_name')
                    ->where('kiosk_name', '!=', '')
                    ->distinct()
                    ->orderBy('kiosk_name')
                    ->pluck('kiosk_name');
            });
        }

        return view('attendance_logs.index', compact(
            'logs',
            'summary',
            'classifications',
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
        [$todayStart, $todayEnd] = $this->dayBounds($today, $tz);

        $late = $policy->applyLatePredicate(
            $policy->restrictToFirstInOfDay((clone $query)->where('status', 'IN')),
            late: true
        )->count();

        $inStatus = (clone $query)->where('status', 'IN')->count();

        return [
            'total' => (clone $query)->count(),
            // On-time first IN + any later same-day IN (afternoon return, etc.).
            'in' => max(0, $inStatus - $late),
            'late' => $late,
            'out' => (clone $query)->where('status', 'OUT')->count(),
            'today' => (clone $query)->whereBetween('scanned_at', [$todayStart, $todayEnd])->count(),
        ];
    }

    private function summaryCacheKey(Request $request): string
    {
        $userId = auth()->id() ?? 'guest';
        $filters = $request->only([
            'from', 'to', 'period', 'year', 'year_level', 'homeroom_section',
            'gate_device_id', 'kiosk_name', 'status', 'classification', 'search',
        ]);

        return 'attendance_logs:summary:'.$userId.':'.md5(json_encode($filters));
    }

    private function filteredLogs(Request $request, AttendancePolicyService $policy)
    {
        $classification = strtoupper((string) ($request->classification ?: $request->status));
        $tz = config('app.timezone', 'Asia/Manila');

        $query = AttendanceLog::with([
            'student:id,firstname,lastname,student_id,year,section,course',
            'gateDevice:id,name',
        ]);
        AdvisoryScope::applyToAttendanceLogs($query);

        return $query
            ->when($request->filled('from'), function ($q) use ($request, $tz) {
                [$start] = $this->dayBounds($request->from, $tz);
                $q->where('scanned_at', '>=', $start);
            })

            ->when($request->filled('to'), function ($q) use ($request, $tz) {
                [, $end] = $this->dayBounds($request->to, $tz);
                $q->where('scanned_at', '<=', $end);
            })

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

    /** @return array{0: string, 1: string} */
    private function dayBounds(string $date, string $tz): array
    {
        $day = Carbon::parse($date, $tz);

        return [
            $day->copy()->startOfDay()->format('Y-m-d H:i:s'),
            $day->copy()->endOfDay()->format('Y-m-d H:i:s'),
        ];
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
        $logs = $this->filteredLogs($request, $policy)->limit(5000)->get();

        $pdf = Pdf::loadView('attendance_logs.pdf', compact('logs'));
        return $pdf->download('attendance_logs.pdf');
    }

    public function exportExcel(Request $request, AttendancePolicyService $policy)
    {
        $logs = $this->filteredLogs($request, $policy)->limit(10000)->get();

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
