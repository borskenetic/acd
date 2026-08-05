<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fridays are online classes — mark every student present with timed IN/OUT
 * in their expected gate window when they have no real scan yet.
 */
class FridayAutoAttendanceService
{
    public const SOURCE = 'friday_auto';

    public function __construct(
        protected AttendancePolicyService $policy,
        protected Sf2SchoolCalendar $calendar,
    ) {}

    /**
     * @return array{date: string, ins: int, outs: int, skipped: int}
     */
    public function markForDate(?Carbon $date = null, bool $force = false): array
    {
        $tz = $this->policy->timezone();
        $day = ($date ?? Carbon::now($tz))->copy()->timezone($tz)->startOfDay();

        if (! $day->isFriday() && ! $force) {
            return [
                'date' => $day->toDateString(),
                'ins' => 0,
                'outs' => 0,
                'skipped' => 0,
            ];
        }

        if (! $this->calendar->isAttendanceDay($day->toDateString())) {
            return [
                'date' => $day->toDateString(),
                'ins' => 0,
                'outs' => 0,
                'skipped' => 0,
            ];
        }

        $dateStr = $day->toDateString();
        $ins = 0;
        $outs = 0;
        $skipped = 0;

        Student::query()
            ->orderBy('id')
            ->chunkById(200, function ($students) use ($dateStr, $tz, &$ins, &$outs, &$skipped) {
                foreach ($students as $student) {
                    $result = $this->markStudent($student, $dateStr, $tz);
                    $ins += $result['in'] ? 1 : 0;
                    $outs += $result['out'] ? 1 : 0;
                    $skipped += $result['skipped'] ? 1 : 0;
                }
            });

        Log::info('Friday auto-attendance complete', compact('dateStr', 'ins', 'outs', 'skipped'));

        return [
            'date' => $dateStr,
            'ins' => $ins,
            'outs' => $outs,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{in: bool, out: bool, skipped: bool}
     */
    protected function markStudent(Student $student, string $dateStr, string $tz): array
    {
        $hasAnyIn = AttendanceLog::query()
            ->where('student_id', $student->id)
            ->where('status', 'IN')
            ->whereDate('scanned_at', $dateStr)
            ->exists();

        $hasAnyOut = AttendanceLog::query()
            ->where('student_id', $student->id)
            ->where('status', 'OUT')
            ->whereDate('scanned_at', $dateStr)
            ->exists();

        if ($hasAnyIn && $hasAnyOut) {
            return ['in' => false, 'out' => false, 'skipped' => true];
        }

        $login = $this->policy->loginTime($student->year, $student->section);
        $logout = $this->policy->logoutTime($student->year, $student->section);

        $inAt = Carbon::parse($dateStr.' '.$login, $tz);
        $outAt = Carbon::parse($dateStr.' '.$logout, $tz);
        if ($outAt->lte($inAt)) {
            $outAt = $inAt->copy()->addHours(4);
        }

        $createdIn = false;
        $createdOut = false;

        DB::transaction(function () use ($student, $hasAnyIn, $hasAnyOut, $inAt, $outAt, &$createdIn, &$createdOut) {
            if (! $hasAnyIn) {
                AttendanceLog::create([
                    'student_id' => $student->id,
                    'status' => 'IN',
                    'section' => 'Friday online (auto)',
                    'scanned_at' => $inAt,
                    'source' => self::SOURCE,
                ]);
                $createdIn = true;
            }

            if (! $hasAnyOut) {
                AttendanceLog::create([
                    'student_id' => $student->id,
                    'status' => 'OUT',
                    'section' => 'Friday online (auto)',
                    'scanned_at' => $outAt,
                    'source' => self::SOURCE,
                ]);
                $createdOut = true;
            }
        });

        return ['in' => $createdIn, 'out' => $createdOut, 'skipped' => false];
    }
}
