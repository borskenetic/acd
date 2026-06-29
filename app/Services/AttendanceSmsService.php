<?php

namespace App\Services;

use App\Http\Controllers\SmsController;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentAttendanceAlertState;
use App\Models\StudentDailySms;
use Carbon\Carbon;

class AttendanceSmsService
{
    public function __construct(
        protected StudentConsecutiveAttendanceService $consecutive,
    ) {}

    public function handleStudentScan(Student $student, string $status, Carbon $scannedAt): void
    {
        $number = trim((string) ($student->emergency_number ?? ''));
        if ($number === '') {
            return;
        }

        $tz = config('sf2.timezone', 'Asia/Manila');
        $scannedAt = $scannedAt->copy()->timezone($tz);
        $date = $scannedAt->toDateString();

        $daily = StudentDailySms::firstOrCreate(
            ['student_id' => $student->id, 'log_date' => $date],
            ['arrival_sent' => false, 'departure_sent' => false]
        );

        $name = trim($student->firstname.' '.$student->lastname);
        $time = $scannedAt->format('h:i A');

        if (! $daily->arrival_sent) {
            $this->sendTemplate(
                $number,
                Setting::scanSmsArrivalTemplate(),
                ['name' => $name, 'status' => $status, 'time' => $time]
            );
            $daily->update(['arrival_sent' => true]);
        } elseif (! $daily->departure_sent && $this->isDepartureWindow($scannedAt)) {
            $this->sendTemplate(
                $number,
                Setting::scanSmsDepartureTemplate(),
                ['name' => $name, 'status' => $status, 'time' => $time]
            );
            $daily->update(['departure_sent' => true]);
        }

        if (strtoupper($status) === 'IN') {
            $this->checkConsecutiveLateAlert($student, $scannedAt);
        }
    }

    public function checkConsecutiveAbsentAlerts(?Carbon $asOf = null): int
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $asOf ??= Carbon::now($tz);
        $threshold = (int) config('attendance.sms.consecutive_absent_threshold', 3);
        $sent = 0;

        foreach ($this->consecutive->studentsInSf2Grades() as $student) {
            $counts = $this->consecutive->countsForStudent($student, $asOf);
            $consecutiveAbsent = $counts['consecutive_absent'];

            $state = StudentAttendanceAlertState::firstOrCreate(
                ['student_id' => $student->id],
                ['late_streak_notified' => 0, 'absent_streak_notified' => 0]
            );

            if ($consecutiveAbsent < $threshold) {
                if ($state->absent_streak_notified > 0) {
                    $state->update(['absent_streak_notified' => 0]);
                }

                continue;
            }

            if ($state->absent_streak_notified >= $threshold) {
                continue;
            }

            $name = trim($student->firstname.' '.$student->lastname);
            $ok = $this->sendTemplate(
                (string) $student->emergency_number,
                Setting::smsConsecutiveAbsentTemplate(),
                ['name' => $name, 'count' => (string) $consecutiveAbsent]
            );

            if ($ok) {
                $state->update(['absent_streak_notified' => $consecutiveAbsent]);
                $sent++;
            }
        }

        return $sent;
    }

    protected function checkConsecutiveLateAlert(Student $student, Carbon $scannedAt): void
    {
        $threshold = (int) config('attendance.sms.consecutive_late_threshold', 5);
        $counts = $this->consecutive->countsForStudent($student, $scannedAt, $scannedAt);
        $consecutiveLate = $counts['consecutive_late'];

        $state = StudentAttendanceAlertState::firstOrCreate(
            ['student_id' => $student->id],
            ['late_streak_notified' => 0, 'absent_streak_notified' => 0]
        );

        if ($consecutiveLate < $threshold) {
            if ($state->late_streak_notified > 0) {
                $state->update(['late_streak_notified' => 0]);
            }

            return;
        }

        if ($state->late_streak_notified >= $threshold) {
            return;
        }

        $name = trim($student->firstname.' '.$student->lastname);
        $ok = $this->sendTemplate(
            (string) $student->emergency_number,
            Setting::smsConsecutiveLateTemplate(),
            ['name' => $name, 'count' => (string) $consecutiveLate]
        );

        if ($ok) {
            $state->update(['late_streak_notified' => $consecutiveLate]);
        }
    }

    protected function isDepartureWindow(Carbon $scannedAt): bool
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $after = (string) config('attendance.sms.departure_after', '16:00');
        $cutoff = Carbon::today($tz)->setTimeFromTimeString($after);

        return $scannedAt->gte($cutoff);
    }

    /** @param  array<string, string>  $vars */
    protected function sendTemplate(string $number, string $template, array $vars): bool
    {
        $message = $template;
        foreach ($vars as $key => $value) {
            $message = str_replace('{'.$key.'}', $value, $message);
        }

        return app(SmsController::class)->sendDirect($number, $message);
    }
}
