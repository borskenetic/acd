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

        $consecutiveLate = 0;
        $consecutiveAbsent = 0;

        foreach (array_reverse($schoolDays) as $date) {
            if ($date > $today) {
                continue;
            }

            $scannedAt = $firstIn[$date] ?? null;

            if ($scannedAt === null) {
                $consecutiveAbsent++;
                $consecutiveLate = 0;

                continue;
            }

            $consecutiveAbsent = 0;

            if ($scannedAt->gt($this->policy->tardyCutoffForDate($date, $student->year, $student->section))) {
                $consecutiveLate++;
            } else {
                $consecutiveLate = 0;
            }
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

        while (count($days) < $max) {
            if ($cursor->isWeekday()) {
                $days[] = $cursor->toDateString();
            }
            $cursor->subDay();
        }

        return array_reverse($days);
    }

    /**
     * @return array<string, Carbon>
     */
    protected function firstInByDate(int $studentId, string $fromDate, string $toDate): array
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $start = Carbon::parse($fromDate, $tz)->startOfDay();
        $end = Carbon::parse($toDate, $tz)->endOfDay();

        $map = [];

        AttendanceLog::query()
            ->where('student_id', $studentId)
            ->where('status', 'IN')
            ->whereBetween('scanned_at', [$start, $end])
            ->orderBy('scanned_at')
            ->get(['scanned_at'])
            ->each(function (AttendanceLog $log) use (&$map, $tz) {
                $instant = $log->scanned_at->timezone($tz);
                $date = $instant->toDateString();
                if (! isset($map[$date])) {
                    $map[$date] = $instant;
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
