<?php

namespace App\Services;

use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AttendanceLunchAutofillService
{
    public function __construct(
        protected StudentSessionScheduleService $schedule,
        protected StudentScanService $scan,
    ) {}

    /**
     * For full-day session students who morning-IN'd but never lunch-OUT'd:
     * insert auto Lunch OUT then auto Afternoon IN.
     */
    public function run(?Carbon $asOf = null): array
    {
        $tz = $this->schedule->timezone();
        $asOf ??= Carbon::now($tz);
        $asOf = $asOf->copy()->timezone($tz);

        $filled = 0;
        $skipped = 0;

        $students = Student::query()
            ->whereNotNull('year')
            ->orderBy('id')
            ->get();

        foreach ($students as $student) {
            if (! $this->schedule->usesSessionModel($student)) {
                $skipped++;

                continue;
            }

            if ($this->schedule->isHalfDayToday($student, $asOf)) {
                $skipped++;

                continue;
            }

            $schedule = $this->schedule->resolveSchedule($student);
            if ($schedule === null) {
                $skipped++;

                continue;
            }

            $logs = $this->schedule->todayLogs($student, $asOf);
            if ($logs->count() !== 1) {
                $skipped++;

                continue;
            }

            $only = $logs->first();
            if (! $only || strtoupper((string) $only->status) !== 'IN') {
                $skipped++;

                continue;
            }

            $lunchAt = $this->schedule->lunchOutAt($schedule, $asOf);
            $afternoonAt = $this->schedule->afternoonInAt($schedule, $asOf);
            if (! $lunchAt || ! $afternoonAt) {
                $skipped++;

                continue;
            }

            // Only run once afternoon session has started (job scheduled at 13:00).
            if ($asOf->lt($afternoonAt)) {
                $skipped++;

                continue;
            }

            // Never stamp OUT/IN before the real morning IN (late arrivals).
            $minOut = $only->scanned_at->copy()->addSecond();
            if ($lunchAt->lt($minOut)) {
                $lunchAt = $minOut->copy();
            }
            $minAfternoon = $lunchAt->copy()->addSecond();
            if ($afternoonAt->lt($minAfternoon)) {
                $afternoonAt = $minAfternoon;
            }

            try {
                $this->scan->recordAutomaticScan(
                    $student,
                    'OUT',
                    $lunchAt,
                    'auto_lunch_out',
                    StudentSessionScheduleService::SESSION_LUNCH_OUT,
                    sendSms: false,
                );

                $this->scan->recordAutomaticScan(
                    $student,
                    'IN',
                    $afternoonAt,
                    'auto_afternoon_in',
                    StudentSessionScheduleService::SESSION_AFTERNOON_IN,
                    sendSms: false,
                );

                $filled++;
            } catch (\Throwable $e) {
                Log::warning('Lunch autofill failed', [
                    'student_id' => $student->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        return ['filled' => $filled, 'skipped' => $skipped];
    }
}
