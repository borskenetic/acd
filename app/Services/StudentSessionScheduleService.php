<?php

namespace App\Services;

use App\Enums\EducationalLevel;
use App\Models\AttendanceLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StudentSessionScheduleService
{
    public const SESSION_MORNING_IN = 'morning_in';

    public const SESSION_LUNCH_OUT = 'lunch_out';

    public const SESSION_AFTERNOON_IN = 'afternoon_in';

    public const SESSION_EOD_OUT = 'eod_out';

    public const SESSION_HALF_DAY_OUT = 'half_day_out';

    public function timezone(): string
    {
        return (string) config('attendance_sessions.timezone', 'Asia/Manila');
    }

    public function usesSessionModel(Student $student): bool
    {
        return $this->resolveScheduleKey($student) !== null;
    }

    /** @return array<string, mixed>|null */
    public function resolveSchedule(Student $student): ?array
    {
        $key = $this->resolveScheduleKey($student);
        if ($key === null) {
            return null;
        }

        $schedule = config("attendance_sessions.schedules.{$key}");

        return is_array($schedule) ? $schedule + ['key' => $key] : null;
    }

    public function resolveScheduleKey(Student $student): ?string
    {
        $year = $this->normalizeYearLabel($student->year);
        if ($year === null) {
            return null;
        }

        foreach (config('attendance_sessions.schedules', []) as $key => $schedule) {
            $years = $schedule['years'] ?? [];
            if (in_array($year, $years, true)) {
                return $key;
            }
        }

        return null;
    }

    public function normalizeYearLabel(?string $year): ?string
    {
        if ($year === null) {
            return null;
        }

        $year = trim(preg_replace('/\s+/', ' ', $year) ?? '');
        if ($year === '') {
            return null;
        }

        if (EducationalLevel::isKinderYear($year) || strcasecmp($year, 'Kinder') === 0) {
            return 'Kinder';
        }

        return $year;
    }

    public function isHalfDayToday(Student $student, ?Carbon $at = null): bool
    {
        $schedule = $this->resolveSchedule($student);
        if ($schedule === null) {
            return false;
        }

        if (! empty($schedule['half_day'])) {
            return true;
        }

        $at ??= Carbon::now($this->timezone());

        return (bool) config('attendance_sessions.friday_half_day', true)
            && $at->copy()->timezone($this->timezone())->isFriday();
    }

    /**
     * Today's attendance logs oldest→newest.
     *
     * @return Collection<int, AttendanceLog>
     */
    public function todayLogs(Student $student, ?Carbon $at = null): Collection
    {
        $at ??= Carbon::now($this->timezone());
        $day = $at->copy()->timezone($this->timezone())->toDateString();

        return AttendanceLog::query()
            ->where('student_id', $student->id)
            ->whereDate('scanned_at', $day)
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Decide the next allowed scan for a session-model student.
     *
     * @return array{
     *   type: string,
     *   next_status?: string,
     *   session_key?: string,
     *   session_label?: string,
     *   message?: string,
     *   allowed_after?: string,
     *   last_status?: string
     * }
     */
    public function decideNextScan(Student $student, ?Carbon $at = null): array
    {
        $at ??= Carbon::now($this->timezone());
        $at = $at->copy()->timezone($this->timezone());
        $schedule = $this->resolveSchedule($student);

        if ($schedule === null) {
            return ['type' => 'not_session'];
        }

        $logs = $this->todayLogs($student, $at);
        $count = $logs->count();
        $halfDay = $this->isHalfDayToday($student, $at);
        $maxScans = $halfDay ? 2 : 4;

        if ($count >= $maxScans) {
            $last = $logs->last();
            $lastStatus = strtoupper((string) ($last?->status ?? 'OUT'));

            return [
                'type' => 'already_scanned',
                'message' => $this->alreadyScannedMessage($lastStatus, $halfDay ? 'today' : 'the afternoon'),
                'session_label' => $halfDay ? 'today' : 'afternoon',
                'last_status' => $lastStatus,
            ];
        }

        $last = $logs->last();
        if ($last && $this->isWithinCooldown($student, $last, $at)) {
            $lastStatus = strtoupper((string) $last->status);
            $sessionLabel = $this->sessionLabelForIndex($count - 1, $halfDay);

            return [
                'type' => 'already_scanned',
                'message' => $this->alreadyScannedMessage($lastStatus, $sessionLabel),
                'session_label' => $sessionLabel,
                'last_status' => $lastStatus,
            ];
        }

        $expected = $this->expectedAction($count, $halfDay);
        if ($expected === null) {
            return [
                'type' => 'already_scanned',
                'message' => 'You have already completed scanning for today.',
                'session_label' => 'today',
            ];
        }

        if ($expected['status'] === 'OUT') {
            $allowedAt = $this->outAllowedAt($schedule, $expected['session_key'], $at, $halfDay);
            if ($allowedAt && $at->lt($allowedAt)) {
                return [
                    'type' => 'early_out_blocked',
                    'message' => str_replace(
                        '{time}',
                        $allowedAt->format('g:i A'),
                        'You are not yet allowed to scan OUT. Please try again after {time}.'
                    ),
                    'allowed_after' => $allowedAt->format('g:i A'),
                    'next_status' => 'OUT',
                    'session_key' => $expected['session_key'],
                    'session_label' => $expected['session_label'],
                ];
            }
        }

        return [
            'type' => 'ok',
            'next_status' => $expected['status'],
            'session_key' => $expected['session_key'],
            'session_label' => $expected['session_label'],
        ];
    }

    /**
     * @return array{status: string, session_key: string, session_label: string}|null
     */
    public function expectedAction(int $todayScanCount, bool $halfDay): ?array
    {
        return match (true) {
            $todayScanCount === 0 => [
                'status' => 'IN',
                'session_key' => self::SESSION_MORNING_IN,
                'session_label' => 'morning',
            ],
            $todayScanCount === 1 && $halfDay => [
                'status' => 'OUT',
                'session_key' => self::SESSION_HALF_DAY_OUT,
                'session_label' => 'morning',
            ],
            $todayScanCount === 1 && ! $halfDay => [
                'status' => 'OUT',
                'session_key' => self::SESSION_LUNCH_OUT,
                'session_label' => 'morning',
            ],
            $todayScanCount === 2 && ! $halfDay => [
                'status' => 'IN',
                'session_key' => self::SESSION_AFTERNOON_IN,
                'session_label' => 'afternoon',
            ],
            $todayScanCount === 3 && ! $halfDay => [
                'status' => 'OUT',
                'session_key' => self::SESSION_EOD_OUT,
                'session_label' => 'afternoon',
            ],
            default => null,
        };
    }

    public function outAllowedAt(array $schedule, string $sessionKey, Carbon $at, bool $halfDay): ?Carbon
    {
        $day = $at->copy()->timezone($this->timezone())->startOfDay();

        $time = match ($sessionKey) {
            self::SESSION_HALF_DAY_OUT => $halfDay && empty($schedule['half_day'])
                ? ($schedule['lunch_out'] ?? null) // Friday half-day uses morning dismissal
                : ($schedule['half_day_out'] ?? $schedule['lunch_out'] ?? null),
            self::SESSION_LUNCH_OUT => $schedule['lunch_out'] ?? null,
            self::SESSION_EOD_OUT => $schedule['eod_out'] ?? null,
            default => null,
        };

        if ($time === null || $time === '') {
            return null;
        }

        return $day->copy()->setTimeFromTimeString($time);
    }

    public function lunchOutAt(array $schedule, Carbon $at): ?Carbon
    {
        $time = $schedule['lunch_out'] ?? null;
        if ($time === null || $time === '') {
            return null;
        }

        return $at->copy()->timezone($this->timezone())->startOfDay()->setTimeFromTimeString($time);
    }

    public function afternoonInAt(array $schedule, Carbon $at): ?Carbon
    {
        $time = $schedule['afternoon_in'] ?? null;
        if ($time === null || $time === '') {
            return null;
        }

        return $at->copy()->timezone($this->timezone())->startOfDay()->setTimeFromTimeString($time);
    }

    public function eodOutAt(array $schedule, Carbon $at): ?Carbon
    {
        $time = $schedule['eod_out'] ?? null;
        if ($time === null || $time === '') {
            return null;
        }

        return $at->copy()->timezone($this->timezone())->startOfDay()->setTimeFromTimeString($time);
    }

    public function isWithinLunchWindow(Student $student, Carbon $at): bool
    {
        $schedule = $this->resolveSchedule($student);
        if ($schedule === null || $this->isHalfDayToday($student, $at)) {
            return false;
        }

        $lunch = $this->lunchOutAt($schedule, $at);
        $afternoon = $this->afternoonInAt($schedule, $at);
        if (! $lunch || ! $afternoon) {
            return false;
        }

        $at = $at->copy()->timezone($this->timezone());

        return $at->gte($lunch) && $at->lt($afternoon);
    }

    public function cooldownMinutes(Student $student, Carbon $at): int
    {
        if ($this->isWithinLunchWindow($student, $at)) {
            return (int) config('attendance_sessions.lunch_cooldown_minutes', 5);
        }

        return (int) config('attendance_sessions.cooldown_minutes', 15);
    }

    public function isWithinCooldown(Student $student, AttendanceLog $last, Carbon $at): bool
    {
        $minutes = $this->cooldownMinutes($student, $at);
        $lastAt = $last->scanned_at->copy()->timezone($this->timezone());

        return $lastAt->diffInSeconds($at, false) < ($minutes * 60);
    }

    public function alreadyScannedMessage(string $status, string $sessionLabel): string
    {
        $status = strtoupper($status) === 'OUT' ? 'OUT' : 'IN';

        return "You have already scanned {$status} for the {$sessionLabel} session.";
    }

    public function sessionLabelForIndex(int $index, bool $halfDay): string
    {
        if ($halfDay) {
            return $index === 0 ? 'morning' : 'morning';
        }

        return match ($index) {
            0, 1 => 'morning',
            2, 3 => 'afternoon',
            default => 'today',
        };
    }

    /** Settings payload for gate terminals. */
    public function settingsPayload(): array
    {
        return [
            'timezone' => $this->timezone(),
            'cooldown_minutes' => (int) config('attendance_sessions.cooldown_minutes', 15),
            'lunch_cooldown_minutes' => (int) config('attendance_sessions.lunch_cooldown_minutes', 5),
            'friday_half_day' => (bool) config('attendance_sessions.friday_half_day', true),
            'schedules' => config('attendance_sessions.schedules', []),
        ];
    }
}
