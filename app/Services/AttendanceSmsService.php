<?php

namespace App\Services;

use App\Models\GateDevice;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentAttendanceAlertState;
use App\Models\StudentDailySms;
use Carbon\Carbon;

class AttendanceSmsService
{
    public function __construct(
        protected StudentConsecutiveAttendanceService $consecutive,
        protected AttendancePolicyService $policy,
        protected StudentSessionScheduleService $sessionSchedule,
    ) {}

    public function handleStudentScan(
        Student $student,
        string $status,
        Carbon $scannedAt,
        ?string $sessionKey = null,
        ?string $forcedEvent = null,
        ?GateDevice $gateDevice = null,
    ): void {
        $number = trim((string) ($student->emergency_number ?? ''));
        if ($number === '') {
            return;
        }

        $tz = config('sf2.timezone', 'Asia/Manila');
        $scannedAt = $scannedAt->copy()->timezone($tz);
        $date = $scannedAt->toDateString();

        $daily = StudentDailySms::firstOrCreate(
            ['student_id' => $student->id, 'log_date' => $date],
            ['arrival_sent' => false, 'departure_sent' => false, 'events_sent' => []]
        );

        $guardianName = $this->guardianDisplayName($student);
        $time = $scannedAt->format('h:i A');
        $childName = trim($student->firstname.' '.$student->lastname);

        if ($forcedEvent === 'missed_eod') {
            $this->sendOnce($daily, 'missed_eod', $number, Setting::scanSmsMissedEodTemplate(), [
                'name' => $guardianName,
                'child' => $childName,
                'status' => $status,
                'time' => $time,
            ], $student, $gateDevice);

            return;
        }

        if ($this->sessionSchedule->usesSessionModel($student)) {
            $event = $forcedEvent ?: ($sessionKey ?: $this->inferSessionEvent($student, $status, $scannedAt));
            $template = $this->templateForSessionEvent($event, $student, $scannedAt);

            $this->sendOnce($daily, $event, $number, $template, [
                'name' => $guardianName,
                'child' => $childName,
                'status' => $status,
                'time' => $time,
            ], $student, $gateDevice);
        } else {
            // SHS / College: keep arrival + departure (once each), guardian name in {name}.
            if (! $daily->arrival_sent) {
                $ok = $this->sendTemplate(
                    $number,
                    Setting::scanSmsArrivalTemplate(),
                    ['name' => $guardianName, 'child' => $childName, 'status' => $status, 'time' => $time],
                    'arrival',
                    $student,
                    $gateDevice,
                    [
                        'student_id' => $student->id,
                        'log_date' => $date,
                        'kind' => 'arrival',
                    ],
                );
                if ($ok) {
                    $daily->update(['arrival_sent' => true]);
                }
            } elseif (! $daily->departure_sent && $this->isDepartureWindow($scannedAt, $student)) {
                $ok = $this->sendTemplate(
                    $number,
                    Setting::scanSmsDepartureTemplate(),
                    ['name' => $guardianName, 'child' => $childName, 'status' => $status, 'time' => $time],
                    'departure',
                    $student,
                    $gateDevice,
                    [
                        'student_id' => $student->id,
                        'log_date' => $date,
                        'kind' => 'departure',
                    ],
                );
                if ($ok) {
                    $daily->update(['departure_sent' => true]);
                }
            }
        }

        if (strtoupper($status) === 'IN') {
            $this->checkConsecutiveLateAlert($student, $scannedAt);
        }
    }

    public function checkConsecutiveAbsentAlerts(?Carbon $asOf = null): int
    {
        if (! Setting::smsConsecutiveAbsentAlertsEnabled()) {
            return 0;
        }

        $tz = config('sf2.timezone', 'Asia/Manila');
        $asOf ??= Carbon::now($tz);
        $threshold = $this->policy->consecutiveAbsentThreshold();
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

            $ok = $this->sendTemplate(
                (string) $student->emergency_number,
                Setting::smsConsecutiveAbsentTemplate(),
                [
                    'name' => $this->guardianDisplayName($student),
                    'child' => trim($student->firstname.' '.$student->lastname),
                    'count' => (string) $consecutiveAbsent,
                ],
                'consecutive_absent',
                $student,
                null,
                [
                    'student_id' => $student->id,
                    'kind' => 'consecutive_absent',
                    'streak_count' => $consecutiveAbsent,
                ],
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
        if (! Setting::smsConsecutiveLateAlertsEnabled()) {
            return;
        }

        $threshold = $this->policy->consecutiveLateThreshold();
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

        $ok = $this->sendTemplate(
            (string) $student->emergency_number,
            Setting::smsConsecutiveLateTemplate(),
            [
                'name' => $this->guardianDisplayName($student),
                'child' => trim($student->firstname.' '.$student->lastname),
                'count' => (string) $consecutiveLate,
            ],
            'consecutive_late',
            $student,
            null,
            [
                'student_id' => $student->id,
                'kind' => 'consecutive_late',
                'streak_count' => $consecutiveLate,
            ],
        );

        if ($ok) {
            $state->update(['late_streak_notified' => $consecutiveLate]);
        }
    }

    protected function guardianDisplayName(Student $student): string
    {
        $guardian = trim((string) ($student->emergency_person ?? ''));
        if ($guardian !== '') {
            return $guardian;
        }

        return 'Parent/Guardian';
    }

    protected function inferSessionEvent(Student $student, string $status, Carbon $scannedAt): string
    {
        $halfDay = $this->sessionSchedule->isHalfDayToday($student, $scannedAt);
        $count = max(0, $this->sessionSchedule->todayLogs($student, $scannedAt)->count() - 1);
        $expected = $this->sessionSchedule->expectedAction($count, $halfDay);

        return $expected['session_key'] ?? (strtoupper($status) === 'IN' ? 'morning_in' : 'eod_out');
    }

    protected function templateForSessionEvent(string $event, ?Student $student = null, ?Carbon $scannedAt = null): string
    {
        return match ($event) {
            StudentSessionScheduleService::SESSION_MORNING_IN => Setting::scanSmsMorningInTemplate(),
            StudentSessionScheduleService::SESSION_HALF_DAY_OUT => Setting::scanSmsHalfDayOutTemplate(),
            StudentSessionScheduleService::SESSION_LUNCH_OUT => $this->lunchOutTemplate($student, $scannedAt),
            StudentSessionScheduleService::SESSION_AFTERNOON_IN => Setting::scanSmsAfternoonInTemplate(),
            StudentSessionScheduleService::SESSION_EOD_OUT => Setting::scanSmsEodOutTemplate(),
            'missed_eod' => Setting::scanSmsMissedEodTemplate(),
            default => Setting::scanSmsArrivalTemplate(),
        };
    }

    protected function lunchOutTemplate(?Student $student, ?Carbon $scannedAt): string
    {
        if ($student && $scannedAt) {
            $schedule = $this->sessionSchedule->resolveSchedule($student);
            if ($schedule !== null) {
                $lunchOutAt = $this->sessionSchedule->lunchOutAt($schedule, $scannedAt);
                if ($lunchOutAt && $scannedAt->copy()->timezone($this->sessionSchedule->timezone())->lt($lunchOutAt)) {
                    return Setting::scanSmsEarlyOutTemplate();
                }
            }
        }

        return Setting::scanSmsLunchOutTemplate();
    }

    /** @param  array<string, string>  $vars */
    protected function sendOnce(
        StudentDailySms $daily,
        string $event,
        string $number,
        string $template,
        array $vars,
        ?Student $student = null,
        ?GateDevice $gateDevice = null,
    ): void {
        $sent = $daily->events_sent ?? [];
        if (! is_array($sent)) {
            $sent = [];
        }

        if (in_array($event, $sent, true)) {
            return;
        }

        $logDate = $daily->log_date instanceof \DateTimeInterface
            ? $daily->log_date->format('Y-m-d')
            : (string) $daily->log_date;

        if ($this->sendTemplate($number, $template, $vars, $event, $student, $gateDevice, [
            'student_id' => $student?->id ?? $daily->student_id,
            'log_date' => $logDate,
            'kind' => 'event',
            'event' => $event,
        ])) {
            $sent[] = $event;
            $daily->update(['events_sent' => array_values(array_unique($sent))]);
        }
    }

    protected function isDepartureWindow(Carbon $scannedAt, ?Student $student = null): bool
    {
        return $this->policy->isDepartureWindow(
            $scannedAt,
            is_string($student?->year) ? $student->year : null,
            is_string($student?->section) ? $student->section : null,
        );
    }

    /**
     * @param  array<string, string>  $vars
     * @param  array<string, mixed>|null  $attendanceClaim
     */
    protected function sendTemplate(
        string $number,
        string $template,
        array $vars,
        string $type = 'gate',
        ?Student $student = null,
        ?GateDevice $gateDevice = null,
        ?array $attendanceClaim = null,
    ): bool {
        $number = trim($number);
        if ($number === '') {
            return false;
        }

        // Disabled gate templates skip sending but count as handled so once-per-day
        // flags (arrival/departure/events_sent) still advance.
        if (Setting::isScanSmsEvent($type) && ! Setting::scanSmsEventEnabled($type)) {
            return true;
        }

        $message = $template;
        foreach ($vars as $key => $value) {
            $message = str_replace('{'.$key.'}', $value, $message);
        }

        $child = $vars['child'] ?? null;
        $label = is_string($child) && $child !== ''
            ? $child
            : (is_string($vars['name'] ?? null) ? $vars['name'] : null);

        $meta = [];
        if ($gateDevice) {
            $meta['gate_device_id'] = $gateDevice->id;
            $meta['kiosk_name'] = $gateDevice->name;
        }
        if (is_array($attendanceClaim) && $attendanceClaim !== []) {
            $meta['attendance_claim'] = $attendanceClaim;
        }

        return app(ModemSmsService::class)->sendWithRetry($number, $message, [
            'type' => $type !== '' ? $type : 'gate',
            'student_id' => $student?->id,
            'recipient_label' => $label,
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }
}
