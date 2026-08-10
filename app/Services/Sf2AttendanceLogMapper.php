<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\GradeSection;
use App\Models\Student;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class Sf2AttendanceLogMapper
{
    public function __construct(
        protected Sf2SchoolCalendar $calendar,
        protected AttendancePolicyService $policy,
    ) {}

    /**
     * Canonical K–12 label used by SF2 config (e.g. "9" / "G9" → "Grade 9").
     */
    public function canonicalizeYear(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        if ($value === '') {
            return null;
        }

        $allowed = config('sf2.grade_levels', []);
        $compact = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        foreach ($allowed as $option) {
            if (strtolower(preg_replace('/\s+/', '', $option) ?? '') === $compact) {
                return $option;
            }
        }

        if (preg_match('/\bkinder(?:garten)?\s*([12])?\b/i', $value) || preg_match('/^k\s*([12])?$/i', $value)) {
            return in_array('Kinder', $allowed, true) ? 'Kinder' : null;
        }

        if (preg_match('/^(?:grade|g)?\s*(1[0-2]|[1-9])$/i', $value, $m)
            || preg_match('/\bgrade\s*(1[0-2]|[1-9])\b/i', $value, $m)) {
            $label = 'Grade '.$m[1];

            return in_array($label, $allowed, true) ? $label : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function yearAliases(string $gradeLevel): array
    {
        $canonical = $this->canonicalizeYear($gradeLevel) ?? trim($gradeLevel);
        $aliases = [$canonical, trim($gradeLevel)];

        if (preg_match('/^Grade\s+(1[0-2]|[1-9])$/i', $canonical, $m)) {
            $n = $m[1];
            $aliases = array_merge($aliases, [
                (string) $n,
                'G'.$n,
                'g'.$n,
                'Grade'.$n,
                'GRADE '.$n,
                'grade '.$n,
            ]);
        }

        if (strcasecmp($canonical, 'Kinder') === 0) {
            $aliases = array_merge($aliases, [
                'Kindergarten',
                'Kinder 1',
                'Kinder 2',
                'K',
                'K1',
                'K2',
            ]);
        }

        return array_values(array_unique(array_filter($aliases, fn ($v) => $v !== '')));
    }

    /**
     * Grades that have either enrolled students or school-setup sections.
     *
     * @return list<string>
     */
    public function gradeLevelsFromStudents(): array
    {
        $allowed = config('sf2.grade_levels', []);
        $order = array_flip($allowed);

        $fromStudents = Student::query()
            ->whereNotNull('year')
            ->where('year', '!=', '')
            ->distinct()
            ->pluck('year')
            ->map(fn ($year) => $this->canonicalizeYear((string) $year))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $fromSetup = Schema::hasTable('grade_sections')
            ? GradeSection::query()
                ->whereIn('grade_level', $allowed)
                ->distinct()
                ->pluck('grade_level')
                ->map(fn ($year) => $this->canonicalizeYear((string) $year) ?? $year)
                ->filter(fn ($year) => in_array($year, $allowed, true))
                ->unique()
                ->values()
                ->all()
            : [];

        return collect($fromStudents)
            ->merge($fromSetup)
            ->unique()
            ->sortBy(fn (string $year) => $order[$year] ?? 999)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function sectionsForGrade(string $gradeLevel): array
    {
        $aliases = $this->yearAliases($gradeLevel);

        $fromSetup = Schema::hasTable('grade_sections')
            ? GradeSection::query()
                ->whereIn('grade_level', $aliases)
                ->orderBy('section')
                ->pluck('section')
                ->unique()
                ->values()
                ->all()
            : [];

        $fromStudents = Student::query()
            ->whereIn('year', $aliases)
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->distinct()
            ->orderBy('section')
            ->pluck('section')
            ->all();

        $merged = array_values(array_unique(array_merge($fromSetup, $fromStudents)));
        sort($merged, SORT_NATURAL | SORT_FLAG_CASE);

        return $merged;
    }

    /**
     * @return array{grades: list<string>, sections_by_grade: array<string, list<string>>}
     */
    public function rosterDropdownData(): array
    {
        // Keep setup in sync so SF2 / registration share one section catalog.
        GradeSection::syncFromStudents();

        $allGrades = config('sf2.grade_levels', []);
        $sectionsByGrade = [];

        // Always build a map for every SF2 grade so the UI is never half-populated.
        foreach ($allGrades as $grade) {
            $sectionsByGrade[$grade] = $this->sectionsForGrade($grade);
        }

        return [
            'grades' => $allGrades,
            'sections_by_grade' => $sectionsByGrade,
        ];
    }

    public function roster(string $gradeLevel, string $section): Collection
    {
        return Student::query()
            ->whereIn('year', $this->yearAliases($gradeLevel))
            ->where('section', $section)
            ->orderByRaw("CASE WHEN sex = 'male' THEN 0 WHEN sex = 'female' THEN 1 ELSE 2 END")
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get();
    }

    /**
     * @param  list<string>  $schoolDays
     * @param  array<string, Carbon>  $firstInByDate
     * @return array{absent_dates: list<string>, tardy_dates: list<string>, half_day_dates: list<string>}
     */
    public function marksForStudent(
        array $schoolDays,
        array $firstInByDate,
        ?string $year = null,
        ?string $section = null,
        bool $supportHalfDay = true,
    ): array {
        $absent = [];
        $tardy = [];
        $halfDay = [];
        $tz = config('sf2.timezone', 'Asia/Manila');
        // Dates from generation day onward default PRESENT (not yet taken).
        $today = Carbon::now($tz)->toDateString();
        $halfStart = $this->halfDayStartOnDate('1970-01-01'); // time-of-day only via helper below

        foreach ($schoolDays as $date) {
            $scannedAt = $firstInByDate[$date] ?? null;

            if ($scannedAt === null) {
                // Dates after generation day default PRESENT (not yet taken).
                if ($date > $today) {
                    continue;
                }

                // Friday = online classes → auto-present even without a scan yet.
                if ($this->calendar->isFridayOnlineDay($date)) {
                    continue;
                }

                $absent[] = $date;

                continue;
            }

            // First IN at/after noon (default 12:00) ⇒ half-day (K–10 only).
            if ($supportHalfDay && $scannedAt->gte($this->halfDayStartOnDate($date))) {
                $halfDay[] = $date;

                continue;
            }

            if ($scannedAt->gt($this->policy->tardyCutoffForDate($date, $year, $section))) {
                $tardy[] = $date;
            }
        }

        return [
            'absent_dates' => $absent,
            'tardy_dates' => $tardy,
            'half_day_dates' => $halfDay,
        ];
    }

    public function usesHalfDayMarks(string $gradeLevel): bool
    {
        $level = trim($gradeLevel);
        $shs = config('sf2.shs_grades', ['Grade 11', 'Grade 12']);

        foreach ($shs as $option) {
            if (strcasecmp($level, (string) $option) === 0) {
                return false;
            }
        }

        return ! (bool) preg_match('/\b(?:grade\s*)?1[12]\b/i', $level);
    }

    protected function halfDayStartOnDate(string $date): Carbon
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $time = (string) config('sf2.half_day_start_time', '12:00');

        return Carbon::parse($date.' '.$time, $tz);
    }

    /**
     * @return array{
     *   students: list<array<string, mixed>>,
     *   warnings: list<string>,
     *   school_days: list<string>
     * }
     */
    public function buildPreview(string $gradeLevel, string $section, int $reportYear, int $reportMonth): array
    {
        $schoolDays = $this->calendar->schoolDaysInMonth($reportYear, $reportMonth);
        $roster = $this->roster($gradeLevel, $section);
        $warnings = [];

        if ($roster->isEmpty()) {
            return [
                'students' => [],
                'warnings' => ['No students found for this grade and section.'],
                'school_days' => $schoolDays,
            ];
        }

        $firstInMap = $this->firstInLogsByStudentAndDate(
            $roster->pluck('id')->all(),
            $reportYear,
            $reportMonth
        );

        $students = [];
        $supportHalfDay = $this->usesHalfDayMarks($gradeLevel);

        foreach ($roster as $student) {
            if (! in_array($student->sex, ['male', 'female'], true)) {
                $warnings[] = sprintf(
                    '%s, %s skipped — set sex on the student record.',
                    $student->lastname,
                    $student->firstname
                );

                continue;
            }

            $marks = $this->marksForStudent(
                $schoolDays,
                $firstInMap[$student->id] ?? [],
                $student->year,
                $student->section,
                $supportHalfDay
            );

            $students[] = [
                'sex' => $student->sex,
                'last_name' => $student->lastname,
                'first_name' => $student->firstname,
                'middle_name' => $student->middle_initial ?: null,
                'remarks' => '',
                'absent_dates' => implode(', ', $marks['absent_dates']),
                'tardy_dates' => implode(', ', $marks['tardy_dates']),
                'half_day_dates' => implode(', ', $marks['half_day_dates']),
            ];
        }

        if ($students === [] && $warnings === []) {
            $warnings[] = 'No learners with sex set (male/female) in this section.';
        }

        return [
            'students' => $students,
            'warnings' => $warnings,
            'school_days' => $schoolDays,
        ];
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array<string, Carbon>>
     */
    protected function firstInLogsByStudentAndDate(array $studentIds, int $year, int $month): array
    {
        if ($studentIds === []) {
            return [];
        }

        $tz = config('sf2.timezone', 'Asia/Manila');
        $start = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        $logs = AttendanceLog::query()
            ->whereIn('student_id', $studentIds)
            ->where('status', 'IN')
            ->whereBetween('scanned_at', [$start, $end])
            ->orderBy('scanned_at')
            ->get(['student_id', 'scanned_at']);

        $map = [];

        foreach ($logs as $log) {
            $instant = $log->scanned_at->timezone($tz);
            $date = $instant->toDateString();

            if (! isset($map[$log->student_id][$date])) {
                $map[$log->student_id][$date] = $instant;
            }
        }

        return $map;
    }
}
