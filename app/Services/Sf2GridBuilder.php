<?php

namespace App\Services;

use App\Models\Sf2Report;
use App\Models\Sf2ReportStudent;
use Carbon\Carbon;

class Sf2GridBuilder
{
    public const MARK_PRESENT = 'present';

    public const MARK_ABSENT = 'absent';

    public const MARK_TARDY = 'tardy';

    public const MARK_HALF = 'half';

    /**
     * @return array{
     *   columns: list<array{date: string, day_num: int, dow: string}>,
     *   padded_columns: list<array{date: ?string, day_num: ?int, dow: ?string}>,
     *   male: list<array{student: Sf2ReportStudent, marks: array<string, string>, absent_total: float, tardy_total: int, half_total: int}>,
     *   female: list<array{student: Sf2ReportStudent, marks: array<string, string>, absent_total: float, tardy_total: int, half_total: int}>,
     *   male_daily_totals: array<string, float>,
     *   female_daily_totals: array<string, float>,
     *   combined_daily_totals: array<string, float>,
     *   summary: array{
     *     male_count: int,
     *     female_count: int,
     *     school_days: int,
     *     male_ada: int,
     *     female_ada: int,
     *     total_ada: int,
     *     male_pct_enrolment: float,
     *     female_pct_enrolment: float,
     *     total_pct_enrolment: float,
     *     male_pct_attendance: float,
     *     female_pct_attendance: float,
     *     total_pct_attendance: float,
     *   }
     * }
     */
    public function build(Sf2Report $report): array
    {
        $report->loadMissing('students');

        $schoolDays = $report->school_days ?? [];
        $maxCols = (int) config('sf2.max_day_columns', 25);
        $tz = config('sf2.timezone', 'Asia/Manila');

        $columns = [];
        foreach ($schoolDays as $date) {
            $c = Carbon::parse($date, $tz);
            $columns[] = [
                'date' => $date,
                'day_num' => (int) $c->format('j'),
                'dow' => $this->dowLabel($c),
            ];
        }

        $padded = $columns;
        while (count($padded) < $maxCols) {
            $padded[] = ['date' => null, 'day_num' => null, 'dow' => null];
        }

        $male = [];
        $female = [];
        $maleDaily = array_fill_keys($schoolDays, 0.0);
        $femaleDaily = array_fill_keys($schoolDays, 0.0);

        foreach ($report->students as $student) {
            $row = $this->buildStudentRow($student, $schoolDays);
            if ($student->isMale()) {
                $male[] = $row;
                foreach ($schoolDays as $d) {
                    $maleDaily[$d] += $this->attendanceWeight($row['marks'][$d] ?? self::MARK_PRESENT);
                }
            } else {
                $female[] = $row;
                foreach ($schoolDays as $d) {
                    $femaleDaily[$d] += $this->attendanceWeight($row['marks'][$d] ?? self::MARK_PRESENT);
                }
            }
        }

        $combinedDaily = [];
        foreach ($schoolDays as $d) {
            $combinedDaily[$d] = ($maleDaily[$d] ?? 0) + ($femaleDaily[$d] ?? 0);
        }

        $days = count($schoolDays);
        $mCount = count($male);
        $fCount = count($female);
        $maleAda = $this->averageDailyAttendance($maleDaily, $days);
        $femaleAda = $this->averageDailyAttendance($femaleDaily, $days);

        return [
            'columns' => $columns,
            'padded_columns' => $padded,
            'male' => $male,
            'female' => $female,
            'male_daily_totals' => $maleDaily,
            'female_daily_totals' => $femaleDaily,
            'combined_daily_totals' => $combinedDaily,
            'summary' => [
                'male_count' => $mCount,
                'female_count' => $fCount,
                'school_days' => $days,
                'male_ada' => $maleAda,
                'female_ada' => $femaleAda,
                'total_ada' => $maleAda + $femaleAda,
                'male_pct_enrolment' => $mCount > 0 ? 1.0 : 0.0,
                'female_pct_enrolment' => $fCount > 0 ? 1.0 : 0.0,
                'total_pct_enrolment' => ($mCount + $fCount) > 0 ? 1.0 : 0.0,
                'male_pct_attendance' => $mCount > 0 ? min(1.0, $maleAda / $mCount) : 0.0,
                'female_pct_attendance' => $fCount > 0 ? min(1.0, $femaleAda / $fCount) : 0.0,
                'total_pct_attendance' => ($mCount + $fCount) > 0
                    ? min(1.0, ($maleAda + $femaleAda) / ($mCount + $fCount))
                    : 0.0,
            ],
        ];
    }

    /**
     * Attendance units for ADA: present/tardy = 1, half-day = 0.5, absent = 0.
     */
    public function attendanceWeight(string $mark): float
    {
        return match ($mark) {
            self::MARK_ABSENT => 0.0,
            self::MARK_HALF => 0.5,
            default => 1.0,
        };
    }

    /**
     * Average daily attendance, always rounded up to a whole number (no decimals in summary).
     *
     * @param  array<string, float|int>  $dailyTotals
     */
    public function averageDailyAttendance(array $dailyTotals, int $schoolDays): int
    {
        if ($schoolDays <= 0) {
            return 0;
        }

        return (int) ceil(array_sum($dailyTotals) / $schoolDays);
    }

    /**
     * @param  list<string>  $schoolDays
     * @return array{student: Sf2ReportStudent, marks: array<string, string>, absent_total: float, tardy_total: int, half_total: int}
     */
    protected function buildStudentRow(Sf2ReportStudent $student, array $schoolDays): array
    {
        $absent = collect($student->absent_dates ?? [])->map(fn ($d) => $this->normalizeDate($d))->filter()->all();
        $tardy = collect($student->tardy_dates ?? [])->map(fn ($d) => $this->normalizeDate($d))->filter()->all();
        $half = collect($student->half_day_dates ?? [])->map(fn ($d) => $this->normalizeDate($d))->filter()->all();

        $absentSet = array_flip($absent);
        $tardySet = array_flip($tardy);
        $halfSet = array_flip($half);

        $marks = [];
        $absentTotal = 0.0;
        $tardyTotal = 0;
        $halfTotal = 0;

        foreach ($schoolDays as $date) {
            if (isset($absentSet[$date])) {
                $marks[$date] = self::MARK_ABSENT;
                $absentTotal += 1.0;
            } elseif (isset($halfSet[$date])) {
                $marks[$date] = self::MARK_HALF;
                $absentTotal += 0.5;
                $halfTotal++;
            } elseif (isset($tardySet[$date])) {
                $marks[$date] = self::MARK_TARDY;
                $tardyTotal++;
            } else {
                $marks[$date] = self::MARK_PRESENT;
            }
        }

        return [
            'student' => $student,
            'marks' => $marks,
            'absent_total' => $absentTotal,
            'tardy_total' => $tardyTotal,
            'half_total' => $halfTotal,
        ];
    }

    protected function dowLabel(Carbon $date): string
    {
        return match ((int) $date->dayOfWeekIso) {
            1 => 'M',
            2 => 'T',
            3 => 'W',
            4 => 'TH',
            5 => 'F',
            default => '',
        };
    }

    public function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('sf2.timezone', 'Asia/Manila'))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse textarea / comma-separated date lines from manual entry.
     *
     * @return list<string>
     */
    public function parseDateList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $out = [];

        foreach ($parts as $part) {
            $normalized = $this->normalizeDate($part);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }
}
