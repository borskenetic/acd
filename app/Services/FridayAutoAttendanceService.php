<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fridays are online classes — mark every student present with a morning IN
 * when they have no real scan yet. OUT is written only after dismissal time
 * has passed (otherwise EOD / half-day auto-out closes the day later).
 */
class FridayAutoAttendanceService
{
    public const SOURCE = 'friday_auto';

    public function __construct(
        protected AttendancePolicyService $policy,
        protected Sf2SchoolCalendar $calendar,
        protected StudentSessionScheduleService $schedule,
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
        $now = Carbon::now($tz);
        $ins = 0;
        $outs = 0;
        $skipped = 0;

        Student::query()
            ->orderBy('id')
            ->chunkById(200, function ($students) use ($dateStr, $tz, $now, &$ins, &$outs, &$skipped) {
                foreach ($students as $student) {
                    $result = $this->markStudent($student, $dateStr, $tz, $now);
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
    protected function markStudent(Student $student, string $dateStr, string $tz, Carbon $now): array
    {
        return DB::transaction(function () use ($student, $dateStr, $tz, $now) {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();

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

            [$inAt, $outAt] = $this->windowForStudent($student, $dateStr, $tz, $now);

            $createdIn = false;
            $createdOut = false;

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

            // Never invent a future OUT — that blocks real campus scans for the rest of the day.
            if (! $hasAnyOut && $now->gte($outAt)) {
                AttendanceLog::create([
                    'student_id' => $student->id,
                    'status' => 'OUT',
                    'section' => 'Friday online (auto)',
                    'scanned_at' => $outAt,
                    'source' => self::SOURCE,
                ]);
                $createdOut = true;
            }

            return [
                'in' => $createdIn,
                'out' => $createdOut,
                'skipped' => ! $createdIn && ! $createdOut,
            ];
        });
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function windowForStudent(Student $student, string $dateStr, string $tz, Carbon $asOf): array
    {
        $login = $this->policy->loginTime($student->year, $student->section);
        $logout = $this->policy->logoutTime($student->year, $student->section);

        $inAt = Carbon::parse($dateStr.' '.$login, $tz);
        $outAt = Carbon::parse($dateStr.' '.$logout, $tz);

        if ($this->schedule->usesSessionModel($student)) {
            $schedule = $this->schedule->resolveSchedule($student);
            if ($schedule) {
                $halfDay = $this->schedule->isHalfDayToday($student, $asOf);
                if ($halfDay) {
                    $halfOut = $this->schedule->outAllowedAt(
                        $schedule,
                        StudentSessionScheduleService::SESSION_HALF_DAY_OUT,
                        $asOf,
                        true
                    );
                    if ($halfOut) {
                        $outAt = $halfOut;
                    }
                } else {
                    $eod = $this->schedule->eodOutAt($schedule, $asOf);
                    if ($eod) {
                        $outAt = $eod;
                    }
                }
            }
        }

        if ($outAt->lte($inAt)) {
            $outAt = $inAt->copy()->addHours(4);
        }

        return [$inAt, $outAt];
    }
}
