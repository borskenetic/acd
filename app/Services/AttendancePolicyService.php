<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class AttendancePolicyService
{
    public function timezone(): string
    {
        return (string) config('sf2.timezone', config('app.timezone', 'Asia/Manila'));
    }

    public function loginTime(?string $year = null): string
    {
        $year = $this->normalizeYear($year);
        if ($year !== null) {
            $overrides = $this->loginTimeOverrides();
            if (isset($overrides[$year])) {
                return $overrides[$year];
            }
        }

        return (string) ($this->policy()['login_time'] ?? config('attendance.gate.login_time', '08:00'));
    }

    /**
     * Year labels with a non-default expected login time (H:i).
     *
     * @return array<string, string>
     */
    public function loginTimeOverrides(): array
    {
        $raw = config('attendance.gate.login_time_by_year', []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $year => $time) {
            $year = $this->normalizeYear(is_string($year) ? $year : null);
            if ($year === null || ! is_string($time) || trim($time) === '') {
                continue;
            }
            $out[$year] = $this->normalizeTimeInput($time);
        }

        return $out;
    }

    public function logoutTime(): string
    {
        return (string) ($this->policy()['logout_time'] ?? config('attendance.gate.logout_time', '16:00'));
    }

    public function tardyGraceMinutes(): int
    {
        return (int) ($this->policy()['tardy_grace_minutes'] ?? config('attendance.gate.tardy_grace_minutes', 10));
    }

    public function consecutiveLateThreshold(): int
    {
        return (int) ($this->policy()['consecutive_late_threshold'] ?? config('attendance.sms.consecutive_late_threshold', 5));
    }

    public function consecutiveAbsentThreshold(): int
    {
        return (int) ($this->policy()['consecutive_absent_threshold'] ?? config('attendance.sms.consecutive_absent_threshold', 3));
    }

    public function tardyCutoffForDate(string $date, ?string $year = null): Carbon
    {
        $tz = $this->timezone();

        return Carbon::parse($date.' '.$this->loginTime($year), $tz)->addMinutes($this->tardyGraceMinutes());
    }

    /** Time-of-day after which first IN counts as late (H:i:s). */
    public function lateCutoffTimeString(?string $year = null): string
    {
        return Carbon::today($this->timezone())
            ->setTimeFromTimeString($this->loginTime($year))
            ->addMinutes($this->tardyGraceMinutes())
            ->format('H:i:s');
    }

    public function isLateIn(Carbon $scannedAt, ?string $year = null): bool
    {
        $instant = $scannedAt->copy()->timezone($this->timezone());

        return $instant->gt($this->tardyCutoffForDate($instant->toDateString(), $year));
    }

    /**
     * True when this row is the student's earliest IN on that calendar day.
     * Only the first IN can be classified LATE (afternoon returns stay IN).
     */
    public function isFirstInOfDay(AttendanceLog $log): bool
    {
        if (! $log->student_id || ! $log->scanned_at || ! $log->id) {
            return true;
        }

        $day = $log->scanned_at->copy()->timezone($this->timezone())->toDateString();

        $firstId = AttendanceLog::query()
            ->where('student_id', $log->student_id)
            ->where('status', 'IN')
            ->whereDate('scanned_at', $day)
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->value('id');

        return (int) $firstId === (int) $log->id;
    }

    /**
     * @return 'IN'|'LATE'|'OUT'|null
     */
    public function classifyLog(AttendanceLog $log): ?string
    {
        $status = strtoupper((string) $log->status);

        if ($status === 'OUT') {
            return 'OUT';
        }

        if ($status !== 'IN' || ! $log->scanned_at) {
            return null;
        }

        if (! $this->isFirstInOfDay($log)) {
            return 'IN';
        }

        $year = $log->relationLoaded('student')
            ? $log->student?->year
            : $log->student()->value('year');

        return $this->isLateIn($log->scanned_at, is_string($year) ? $year : null) ? 'LATE' : 'IN';
    }

    public function applyClassificationFilter(Builder $query, string $classification): Builder
    {
        $classification = strtoupper(trim($classification));

        if ($classification === 'OUT') {
            return $query->where('status', 'OUT');
        }

        if ($classification === 'LATE') {
            return $this->applyLatePredicate(
                $this->restrictToFirstInOfDay($query->where('status', 'IN')),
                late: true
            );
        }

        if ($classification === 'IN') {
            // On-time first arrival, or any later IN the same day (never LATE).
            return $query->where('status', 'IN')->where(function (Builder $outer) {
                $outer->where(function (Builder $q) {
                    $this->applyLatePredicate(
                        $this->restrictToFirstInOfDay($q),
                        late: false
                    );
                })->orWhere(function (Builder $q) {
                    $this->excludeFirstInOfDay($q);
                });
            });
        }

        return $query;
    }

    /** Keep only the earliest IN row per student per calendar day. */
    public function restrictToFirstInOfDay(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereRaw(
            "{$table}.id = (
                SELECT al2.id
                FROM {$table} AS al2
                WHERE al2.student_id = {$table}.student_id
                  AND DATE(al2.scanned_at) = DATE({$table}.scanned_at)
                  AND UPPER(TRIM(al2.status)) = 'IN'
                ORDER BY al2.scanned_at ASC, al2.id ASC
                LIMIT 1
            )"
        );
    }

    /** Keep IN rows that are not the earliest IN that calendar day. */
    public function excludeFirstInOfDay(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereRaw(
            "{$table}.id <> (
                SELECT al2.id
                FROM {$table} AS al2
                WHERE al2.student_id = {$table}.student_id
                  AND DATE(al2.scanned_at) = DATE({$table}.scanned_at)
                  AND UPPER(TRIM(al2.status)) = 'IN'
                ORDER BY al2.scanned_at ASC, al2.id ASC
                LIMIT 1
            )"
        );
    }

    /**
     * Restrict an IN-status query to late or on-time rows, honoring per-year login overrides.
     * Callers that mean "LATE badge" should also {@see restrictToFirstInOfDay()}.
     */
    public function applyLatePredicate(Builder $query, bool $late): Builder
    {
        $defaultCutoff = $this->lateCutoffTimeString();
        $overrides = $this->loginTimeOverrides();
        $overrideYears = array_keys($overrides);
        $operator = $late ? '>' : '<=';

        return $query->where(function (Builder $outer) use ($defaultCutoff, $overrides, $overrideYears, $operator) {
            foreach ($overrides as $year => $loginTime) {
                $cutoff = Carbon::today($this->timezone())
                    ->setTimeFromTimeString($loginTime)
                    ->addMinutes($this->tardyGraceMinutes())
                    ->format('H:i:s');

                $outer->orWhere(function (Builder $q) use ($year, $cutoff, $operator) {
                    $q->whereHas('student', fn (Builder $s) => $s->where('year', $year))
                        ->whereTime('scanned_at', $operator, $cutoff);
                });
            }

            $outer->orWhere(function (Builder $q) use ($defaultCutoff, $overrideYears, $operator) {
                if ($overrideYears !== []) {
                    $q->where(function (Builder $yearQ) use ($overrideYears) {
                        $yearQ->whereDoesntHave('student')
                            ->orWhereHas('student', fn (Builder $s) => $s->whereNotIn('year', $overrideYears));
                    });
                }

                $q->whereTime('scanned_at', $operator, $defaultCutoff);
            });
        });
    }

    public function isDepartureWindow(Carbon $scannedAt): bool
    {
        $tz = $this->timezone();
        $cutoff = Carbon::today($tz)->setTimeFromTimeString($this->logoutTime());

        return $scannedAt->copy()->timezone($tz)->gte($cutoff);
    }

    /** @return array<string, mixed> */
    public function toFormValues(): array
    {
        return [
            'login_time' => $this->normalizeTimeInput($this->loginTime()),
            'logout_time' => $this->normalizeTimeInput($this->logoutTime()),
            'tardy_grace_minutes' => $this->tardyGraceMinutes(),
            'consecutive_late_threshold' => $this->consecutiveLateThreshold(),
            'consecutive_absent_threshold' => $this->consecutiveAbsentThreshold(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function save(array $data): void
    {
        Setting::setAttendancePolicy([
            'login_time' => $this->normalizeTimeInput((string) ($data['login_time'] ?? '07:30')),
            'logout_time' => $this->normalizeTimeInput((string) ($data['logout_time'] ?? '16:00')),
            'tardy_grace_minutes' => (int) ($data['tardy_grace_minutes'] ?? 15),
            'consecutive_late_threshold' => (int) ($data['consecutive_late_threshold'] ?? 5),
            'consecutive_absent_threshold' => (int) ($data['consecutive_absent_threshold'] ?? 3),
        ]);
    }

    /** @return array<string, mixed> */
    protected function policy(): array
    {
        return Setting::attendancePolicy();
    }

    protected function normalizeTimeInput(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return $time;
        }

        try {
            return Carbon::parse($time)->format('H:i');
        } catch (\Throwable) {
            return '07:30';
        }
    }

    protected function normalizeYear(?string $year): ?string
    {
        if ($year === null) {
            return null;
        }

        $year = trim(preg_replace('/\s+/', ' ', $year) ?? '');

        return $year !== '' ? $year : null;
    }
}
