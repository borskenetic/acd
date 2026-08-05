<?php

namespace App\Http\Controllers;

use App\Models\Sf2Report;
use App\Models\Sf2ReportStudent;
use App\Services\Sf2AttendanceLogMapper;
use App\Services\Sf2ExcelExportService;
use App\Services\Sf2GridBuilder;
use App\Services\Sf2SchoolCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Sf2ReportController extends Controller
{
    public function __construct(
        protected Sf2SchoolCalendar $calendar,
        protected Sf2GridBuilder $grid,
        protected Sf2ExcelExportService $excel,
        protected Sf2AttendanceLogMapper $logMapper,
    ) {}

    public function index()
    {
        $reports = Sf2Report::query()
            ->tap(fn ($q) => \App\Support\AdvisoryScope::applyToSf2Reports($q))
            ->withCount('students')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('sf2.index', compact('reports'));
    }

    public function create()
    {
        $rosterData = $this->logMapper->rosterDropdownData();
        $user = auth()->user();
        $gradeLevels = [];
        if ($user && $user->role === 'faculty') {
            $pairs = \App\Support\AdvisoryScope::classPairs($user);
            $gradeLevels = array_values(array_unique(array_column($pairs, 'year')));
            $sectionsByGrade = [];
            foreach ($pairs as $pair) {
                $sectionsByGrade[$pair['year']] ??= [];
                if (! in_array($pair['section'], $sectionsByGrade[$pair['year']], true)) {
                    $sectionsByGrade[$pair['year']][] = $pair['section'];
                }
            }
            $rosterData['grades'] = $gradeLevels;
            $rosterData['sections_by_grade'] = $sectionsByGrade;
            $defaultGrade = $pairs[0]['year'] ?? null;
            $defaultSection = $pairs[0]['section'] ?? null;
        } else {
            $gradeLevels = $rosterData['grades'] !== []
                ? $rosterData['grades']
                : config('sf2.grade_levels', []);
            $defaultGrade = null;
            $defaultSection = null;
        }

        $school = config('sf2.school', []);
        $defaults = [
            'school_name' => $school['name'] ?? config('app.name'),
            'school_id' => $school['school_id'] ?? '',
            'school_year' => $this->defaultSchoolYear(),
            'semester' => $school['semester'] ?? 'FIRST SEMESTER',
            'division' => $school['division'] ?? 'DAVAO CITY',
            'region' => $school['region'] ?? 'XI',
            'track_and_strand' => $school['track_and_strand'] ?? '',
            'tvl_courses' => $school['tvl_courses'] ?? '',
            'report_month' => (int) now(config('sf2.timezone', 'Asia/Manila'))->format('n'),
            'report_year' => (int) now(config('sf2.timezone', 'Asia/Manila'))->format('Y'),
            'grade_level' => $defaultGrade,
            'section' => $defaultSection,
            'teacher_name' => ($user && in_array($user->role, ['faculty', 'staff'], true))
                ? $user->fullName()
                : null,
        ];

        return view('sf2.create', compact('gradeLevels', 'defaults', 'rosterData'));
    }

    public function previewFromLogs(Request $request)
    {
        $validated = $request->validate([
            'grade_level' => 'required|string|max:64',
            'section' => 'required|string|max:64',
            'report_month' => 'required|integer|min:1|max:12',
            'report_year' => 'required|integer|min:2000|max:2100',
        ]);

        $user = auth()->user();
        if ($user && $user->role === 'faculty') {
            if (! \App\Support\AdvisoryScope::canViewClass($validated['grade_level'], $validated['section'], $user)) {
                return response()->json(['message' => 'You can only generate SF2 for your assigned classes.'], 403);
            }
        }

        $preview = $this->logMapper->buildPreview(
            $validated['grade_level'],
            $validated['section'],
            (int) $validated['report_year'],
            (int) $validated['report_month']
        );

        return response()->json($preview);
    }

    public function store(Request $request)
    {
        $report = $this->persistReport($request, new Sf2Report);

        return redirect()
            ->route('sf2.show', $report)
            ->with('success', 'SF2 report saved. You can preview or download the PDF.');
    }

    public function show(Sf2Report $sf2)
    {
        $this->authorizeSf2Access($sf2);
        $sf2->load('students');
        $grid = $this->grid->build($sf2);

        return view('sf2.show', [
            'report' => $sf2,
            'grid' => $grid,
        ]);
    }

    public function edit(Sf2Report $sf2)
    {
        $this->authorizeSf2Access($sf2);
        $sf2->load('students');
        $gradeLevels = config('sf2.grade_levels', []);

        return view('sf2.edit', compact('sf2', 'gradeLevels'));
    }

    public function update(Request $request, Sf2Report $sf2)
    {
        $this->authorizeSf2Access($sf2);
        $report = $this->persistReport($request, $sf2);

        return redirect()
            ->route('sf2.show', $report)
            ->with('success', 'SF2 report updated.');
    }

    public function destroy(Sf2Report $sf2)
    {
        $this->authorizeSf2Access($sf2);
        $sf2->delete();

        return redirect()
            ->route('sf2.index')
            ->with('success', 'SF2 report deleted.');
    }

    public function pdf(Sf2Report $sf2)
    {
        $this->authorizeSf2Access($sf2);
        $sf2->load('students');
        $grid = $this->grid->build($sf2);

        $pdf = Pdf::loadView('pdf.sf2', [
            'report' => $sf2,
            'grid' => $grid,
        ])
            ->setPaper('a4', 'landscape');

        $filename = sprintf(
            'SF2_%s_%s_%s_%d.pdf',
            str_replace(' ', '_', $sf2->grade_level),
            str_replace(' ', '_', $sf2->section),
            $sf2->reportMonthLabel(),
            $sf2->report_year
        );

        return $pdf->download($filename);
    }

    public function excel(Sf2Report $sf2)
    {
        $this->authorizeSf2Access($sf2);

        return $this->excel->download($sf2);
    }

    protected function authorizeSf2Access(Sf2Report $sf2): void
    {
        $user = auth()->user();
        if ($user && $user->role === 'faculty') {
            if (! \App\Support\AdvisoryScope::canViewClass($sf2->grade_level, $sf2->section, $user)) {
                abort(403, 'You can only access SF2 reports for your assigned classes.');
            }
        }
    }

    protected function persistReport(Request $request, Sf2Report $report): Sf2Report
    {
        $validated = $request->validate([
            'school_id' => 'nullable|string|max:50',
            'school_name' => 'required|string|max:255',
            'school_year' => 'required|string|max:16',
            'semester' => 'nullable|string|max:64',
            'division' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:32',
            'report_month' => 'required|integer|min:1|max:12',
            'report_year' => 'required|integer|min:2000|max:2100',
            'grade_level' => 'required|string|max:64',
            'section' => 'required|string|max:64',
            'track_and_strand' => 'nullable|string|max:255',
            'tvl_courses' => 'nullable|string|max:255',
            'teacher_name' => 'nullable|string|max:255',
            'school_head_name' => 'nullable|string|max:255',
            'students' => 'required|array|min:1',
            'students.*.sex' => 'required|in:male,female',
            'students.*.last_name' => 'required|string|max:100',
            'students.*.first_name' => 'required|string|max:100',
            'students.*.middle_name' => 'nullable|string|max:100',
            'students.*.remarks' => 'nullable|string|max:500',
            'students.*.absent_dates' => 'nullable|string|max:2000',
            'students.*.tardy_dates' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();
        if ($user && $user->role === 'faculty') {
            if (! \App\Support\AdvisoryScope::canViewClass($validated['grade_level'], $validated['section'], $user)) {
                abort(403, 'You can only save SF2 for your assigned classes.');
            }
        }

        if (empty($validated['teacher_name']) && $user) {
            $validated['teacher_name'] = $user->fullName();
        }

        $schoolDefaults = config('sf2.school', []);
        $schoolDays = $this->calendar->schoolDaysInMonth(
            (int) $validated['report_year'],
            (int) $validated['report_month']
        );

        return DB::transaction(function () use ($request, $report, $validated, $schoolDays, $schoolDefaults) {
            $report->fill([
                'user_id' => $request->user()?->id,
                'school_id' => $validated['school_id'] ?? ($schoolDefaults['school_id'] ?? null),
                'school_name' => $validated['school_name'],
                'school_year' => $validated['school_year'],
                'semester' => $validated['semester'] ?? ($schoolDefaults['semester'] ?? null),
                'division' => $validated['division'] ?? ($schoolDefaults['division'] ?? null),
                'region' => $validated['region'] ?? ($schoolDefaults['region'] ?? null),
                'report_month' => (int) $validated['report_month'],
                'report_year' => (int) $validated['report_year'],
                'grade_level' => $validated['grade_level'],
                'section' => $validated['section'],
                'track_and_strand' => $validated['track_and_strand'] ?? ($schoolDefaults['track_and_strand'] ?? null),
                'tvl_courses' => $validated['tvl_courses'] ?? ($schoolDefaults['tvl_courses'] ?? null),
                'school_days' => $schoolDays,
                'teacher_name' => $validated['teacher_name'] ?? null,
                'school_head_name' => $validated['school_head_name'] ?? null,
            ]);
            $report->save();

            $report->students()->delete();

            foreach ($validated['students'] as $i => $row) {
                Sf2ReportStudent::create([
                    'sf2_report_id' => $report->id,
                    'sort_order' => $i,
                    'sex' => $row['sex'],
                    'last_name' => $row['last_name'],
                    'first_name' => $row['first_name'],
                    'middle_name' => $row['middle_name'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                    'absent_dates' => $this->grid->parseDateList($row['absent_dates'] ?? null),
                    'tardy_dates' => $this->grid->parseDateList($row['tardy_dates'] ?? null),
                ]);
            }

            return $report->fresh(['students']);
        });
    }

    protected function defaultSchoolYear(): string
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $now = now($tz);
        $y = (int) $now->format('Y');
        $m = (int) $now->format('n');

        if ($m >= 6) {
            return $y.'-'.($y + 1);
        }

        return ($y - 1).'-'.$y;
    }
}
