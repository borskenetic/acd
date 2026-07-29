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
     * @return list<string>
     */
    public function gradeLevelsFromStudents(): array
    {
        $allowed = config('sf2.grade_levels', []);
        $order = array_flip($allowed);

        return Student::query()
            ->whereNotNull('year')
            ->whereIn('year', $allowed)
            ->distinct()
            ->pluck('year')
            ->sortBy(fn (string $year) => $order[$year] ?? 999)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function sectionsForGrade(string $gradeLevel): array
    {
        $fromSetup = Schema::hasTable('grade_sections')
            ? GradeSection::query()
                ->where('grade_level', $gradeLevel)
                ->orderBy('section')
                ->pluck('section')
                ->unique()
                ->values()
                ->all()
            : [];

        $fromStudents = Student::query()
            ->where('year', $gradeLevel)
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->distinct()
            ->orderBy('section')
            ->pluck('section')
            ->all();

        return array_values(array_unique(array_merge($fromSetup, $fromStudents)));
    }

    /**
     * @return array{grades: list<string>, sections_by_grade: array<string, list<string>>}
     */
    public function rosterDropdownData(): array
    {
        $grades = $this->gradeLevelsFromStudents();
        $sectionsByGrade = [];

        foreach ($grades as $grade) {
            $sectionsByGrade[$grade] = $this->sectionsForGrade($grade);
        }

        return [
            'grades' => $grades,
            'sections_by_grade' => $sectionsByGrade,
        ];
    }

    public function roster(string $gradeLevel, string $section): Collection
    {
        return Student::query()
            ->where('year', $gradeLevel)
            ->where('section', $section)
            ->orderByRaw("CASE WHEN sex = 'male' THEN 0 WHEN sex = 'female' THEN 1 ELSE 2 END")
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get();
    }

    /**
     * @param  list<string>  $schoolDays
     * @param  array<string, Carbon>  $firstInByDate
     * @return array{absent_dates: list<string>, tardy_dates: list<string>}
     */
    public function marksForStudent(array $schoolDays, array $firstInByDate, ?string $year = null): array
    {
        $absent = [];
        $tardy = [];

        foreach ($schoolDays as $date) {
            $scannedAt = $firstInByDate[$date] ?? null;

            if ($scannedAt === null) {
                $absent[] = $date;

                continue;
            }

            if ($scannedAt->gt($this->policy->tardyCutoffForDate($date, $year))) {
                $tardy[] = $date;
            }
        }

        return [
            'absent_dates' => $absent,
            'tardy_dates' => $tardy,
        ];
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
                $student->year
            );

            $students[] = [
                'sex' => $student->sex,
                'last_name' => $student->lastname,
                'first_name' => $student->firstname,
                'middle_name' => $student->middle_initial ?: null,
                'remarks' => '',
                'absent_dates' => implode(', ', $marks['absent_dates']),
                'tardy_dates' => implode(', ', $marks['tardy_dates']),
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
