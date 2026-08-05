<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StudentConsecutiveAttendanceService
{
    public function __construct(
        protected Sf2SchoolCalendar $calendar,
        protected AttendancePolicyService $policy,
    ) {}

    /**
     * @return array{consecutive_late: int, consecutive_absent: int}
     */
    public function countsForStudent(Student $student, ?Carbon $asOf = null, ?Carbon $includeInAt = null): array
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $asOf ??= Carbon::now($tz);

        $schoolDays = $this->schoolDaysUpTo($asOf, 60);
        if ($schoolDays === []) {
            return ['consecutive_late' => 0, 'consecutive_absent' => 0];
        }

        $firstIn = $this->firstInByDate(
            $student->id,
            $schoolDays[0],
            $schoolDays[count($schoolDays) - 1]
        );

        $today = $asOf->toDateString();
        if ($includeInAt !== null && $includeInAt->toDateString() === $today) {
            $firstIn[$today] = $includeInAt->copy()->timezone($tz);
        }

        return $this->computeStreaks($schoolDays, $firstIn, $today, $student->year, $student->section);
    }

    /**
     * Batch streak counts for a page of students (avoids N+1 log lookups).
     *
     * @param  iterable<Student>  $students
     * @return array<int, array{consecutive_late: int, consecutive_absent: int}>
     */
    public function countsForStudents(iterable $students, ?Carbon $asOf = null): array
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $asOf ??= Carbon::now($tz);
        $today = $asOf->toDateString();
        $schoolDays = $this->schoolDaysUpTo($asOf, 60);

        $out = [];
        $students = collect($students);
        if ($students->isEmpty() || $schoolDays === []) {
            foreach ($students as $student) {
                $out[$student->id] = ['consecutive_late' => 0, 'consecutive_absent' => 0];
            }

            return $out;
        }

        $ids = $students->pluck('id')->all();
        $firstInMap = $this->firstInLogsByStudentAndDate($ids, $schoolDays[0], $schoolDays[count($schoolDays) - 1]);

        foreach ($students as $student) {
            $out[$student->id] = $this->computeStreaks(
                $schoolDays,
                $firstInMap[$student->id] ?? [],
                $today,
                $student->year,
                $student->section
            );
        }

        return $out;
    }

    /**
     * Current streaks looking backward from $today (most recent attendance day first).
     * Stops when the current streak breaks so earlier history does not wipe the count.
     *
     * @param  list<string>  $schoolDays
     * @param  array<string, Carbon>  $firstIn
     * @return array{consecutive_late: int, consecutive_absent: int}
     */
    protected function computeStreaks(
        array $schoolDays,
        array $firstIn,
        string $today,
        ?string $year,
        ?string $section,
    ): array {
        $consecutiveLate = 0;
        $consecutiveAbsent = 0;

        foreach (array_reverse($schoolDays) as $date) {
            if ($date > $today) {
                continue;
            }

            $scannedAt = $firstIn[$date] ?? null;

            if ($scannedAt === null) {
                // Friday online classes count as present — ends current streak.
                if ($this->calendar->isFridayOnlineDay($date)) {
                    break;
                }

                // Gap after a late streak: keep the late count (absence is not “more late”).
                if ($consecutiveLate > 0) {
                    break;
                }

                $consecutiveAbsent++;

                continue;
            }

            // Presence after absences: current streak is the absent count.
            if ($consecutiveAbsent > 0) {
                break;
            }

            if ($scannedAt->gt($this->policy->tardyCutoffForDate($date, $year, $section))) {
                $consecutiveLate++;

                continue;
            }

            // On-time presence ends the late streak (count stays 0 if this is day 1).
            break;
        }

        return [
            'consecutive_late' => $consecutiveLate,
            'consecutive_absent' => $consecutiveAbsent,
        ];
    }

    /** @return list<string> */
    protected function schoolDaysUpTo(Carbon $asOf, int $max): array
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $days = [];
        $cursor = $asOf->copy()->timezone($tz)->startOfDay();
        // Walk back far enough to collect $max attendance days (holidays skip).
        $guard = 0;
        while (count($days) < $max && $guard < $max * 4) {
            $key = $cursor->toDateString();
            if ($this->calendar->isAttendanceDay($key)) {
                $days[] = $key;
            }
            $cursor->subDay();
            $guard++;
        }

        return array_reverse($days);
    }

    /**
     * @return array<string, Carbon>
     */
    protected function firstInByDate(int $studentId, string $fromDate, string $toDate): array
    {
        return $this->firstInLogsByStudentAndDate([$studentId], $fromDate, $toDate)[$studentId] ?? [];
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array<string, Carbon>>
     */
    protected function firstInLogsByStudentAndDate(array $studentIds, string $fromDate, string $toDate): array
    {
        if ($studentIds === []) {
            return [];
        }

        $tz = config('sf2.timezone', 'Asia/Manila');
        $start = Carbon::parse($fromDate, $tz)->startOfDay();
        $end = Carbon::parse($toDate, $tz)->endOfDay();

        $map = [];

        AttendanceLog::query()
            ->whereIn('student_id', $studentIds)
            ->where('status', 'IN')
            ->whereBetween('scanned_at', [$start, $end])
            ->orderBy('scanned_at')
            ->get(['student_id', 'scanned_at'])
            ->each(function (AttendanceLog $log) use (&$map, $tz) {
                $instant = $log->scanned_at->timezone($tz);
                $date = $instant->toDateString();
                if (! isset($map[$log->student_id][$date])) {
                    $map[$log->student_id][$date] = $instant;
                }
            });

        return $map;
    }

    /** @return Collection<int, Student> */
    public function studentsInSf2Grades(): Collection
    {
        $grades = config('sf2.grade_levels', []);

        return Student::query()
            ->whereIn('year', $grades)
            ->whereNotNull('emergency_number')
            ->where('emergency_number', '!=', '')
            ->get();
    }
}
