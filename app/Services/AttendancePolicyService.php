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

    public function loginTime(): string
    {
        return (string) ($this->policy()['login_time'] ?? config('attendance.gate.login_time', '08:00'));
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

    public function tardyCutoffForDate(string $date): Carbon
    {
        $tz = $this->timezone();

        return Carbon::parse($date.' '.$this->loginTime(), $tz)->addMinutes($this->tardyGraceMinutes());
    }

    /** Time-of-day after which first IN counts as late (H:i:s). */
    public function lateCutoffTimeString(): string
    {
        return Carbon::today($this->timezone())
            ->setTimeFromTimeString($this->loginTime())
            ->addMinutes($this->tardyGraceMinutes())
            ->format('H:i:s');
    }

    public function isLateIn(Carbon $scannedAt): bool
    {
        $instant = $scannedAt->copy()->timezone($this->timezone());

        return $instant->gt($this->tardyCutoffForDate($instant->toDateString()));
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

        return $this->isLateIn($log->scanned_at) ? 'LATE' : 'IN';
    }

    public function applyClassificationFilter(Builder $query, string $classification): Builder
    {
        $classification = strtoupper(trim($classification));

        if ($classification === 'OUT') {
            return $query->where('status', 'OUT');
        }

        if ($classification === 'LATE') {
            return $query->where('status', 'IN')->whereTime('scanned_at', '>', $this->lateCutoffTimeString());
        }

        if ($classification === 'IN') {
            return $query->where('status', 'IN')->whereTime('scanned_at', '<=', $this->lateCutoffTimeString());
        }

        return $query;
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
}
