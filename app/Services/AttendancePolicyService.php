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

    /**
     * Expected login (H:i). Section schedule > year override > gate default.
     */
    public function loginTime(?string $year = null, ?string $section = null): string
    {
        $schedule = $this->scheduleFor($year, $section);
        if ($schedule !== null) {
            return $schedule['login_time'];
        }

        $year = $this->normalizeLabel($year);
        if ($year !== null) {
            $overrides = $this->loginTimeOverrides();
            if (isset($overrides[$year])) {
                return $overrides[$year];
            }
        }

        return (string) ($this->policy()['login_time'] ?? config('attendance.gate.login_time', '08:00'));
    }

    /**
     * Expected logout (H:i). Section schedule > gate default.
     */
    public function logoutTime(?string $year = null, ?string $section = null): string
    {
        $schedule = $this->scheduleFor($year, $section);
        if ($schedule !== null && ! empty($schedule['logout_time'])) {
            return $schedule['logout_time'];
        }

        return (string) ($this->policy()['logout_time'] ?? config('attendance.gate.logout_time', '16:00'));
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
            $year = $this->normalizeLabel(is_string($year) ? $year : null);
            if ($year === null || ! is_string($time) || trim($time) === '') {
                continue;
            }
            $out[$year] = $this->normalizeTimeInput($time);
        }

        return $out;
    }

    /**
     * Configured year+section evening/special schedules.
     *
     * @return list<array{years: list<string>, sections: list<string>, login_time: string, logout_time: string}>
     */
    public function sectionSchedules(): array
    {
        $raw = config('attendance.gate.schedules_by_year_section', []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $years = [];
            foreach ((array) ($row['years'] ?? []) as $year) {
                $year = $this->normalizeLabel(is_string($year) ? $year : null);
                if ($year !== null) {
                    $years[] = $year;
                }
            }

            $sections = [];
            foreach ((array) ($row['sections'] ?? []) as $section) {
                $section = $this->normalizeLabel(is_string($section) ? $section : null);
                if ($section !== null) {
                    $sections[] = $section;
                }
            }

            if ($years === [] || $sections === []) {
                continue;
            }

            $login = is_string($row['login_time'] ?? null) ? $this->normalizeTimeInput($row['login_time']) : null;
            $logout = is_string($row['logout_time'] ?? null) ? $this->normalizeTimeInput($row['logout_time']) : null;
            if ($login === null) {
                continue;
            }

            $out[] = [
                'years' => $years,
                'sections' => $sections,
                'login_time' => $login,
                'logout_time' => $logout ?? $this->logoutTime(),
            ];
        }

        return $out;
    }

    /**
     * @return array{login_time: string, logout_time: string}|null
     */
    public function scheduleFor(?string $year, ?string $section): ?array
    {
        $year = $this->normalizeLabel($year);
        $section = $this->normalizeLabel($section);
        if ($year === null || $section === null) {
            return null;
        }

        $sectionKey = mb_strtolower($section);

        foreach ($this->sectionSchedules() as $sched) {
            if (! in_array($year, $sched['years'], true)) {
                continue;
            }

            foreach ($sched['sections'] as $name) {
                if (mb_strtolower($name) === $sectionKey) {
                    return [
                        'login_time' => $sched['login_time'],
                        'logout_time' => $sched['logout_time'],
                    ];
                }
            }
        }

        return null;
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

    public function tardyCutoffForDate(string $date, ?string $year = null, ?string $section = null): Carbon
    {
        $tz = $this->timezone();

        return Carbon::parse($date.' '.$this->loginTime($year, $section), $tz)
            ->addMinutes($this->tardyGraceMinutes());
    }

    /** Time-of-day after which first IN counts as late (H:i:s). */
    public function lateCutoffTimeString(?string $year = null, ?string $section = null): string
    {
        return Carbon::today($this->timezone())
            ->setTimeFromTimeString($this->loginTime($year, $section))
            ->addMinutes($this->tardyGraceMinutes())
            ->format('H:i:s');
    }

    public function isLateIn(Carbon $scannedAt, ?string $year = null, ?string $section = null): bool
    {
        $instant = $scannedAt->copy()->timezone($this->timezone());

        return $instant->gt($this->tardyCutoffForDate($instant->toDateString(), $year, $section));
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

        $student = $log->relationLoaded('student')
            ? $log->student
            : $log->student()->first(['year', 'section']);

        $year = is_string($student?->year ?? null) ? $student->year : null;
        $section = is_string($student?->section ?? null) ? $student->section : null;

        return $this->isLateIn($log->scanned_at, $year, $section) ? 'LATE' : 'IN';
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
     * Restrict an IN-status query to late or on-time rows.
     * Priority: evening section schedule → year override → default gate time.
     */
    public function applyLatePredicate(Builder $query, bool $late): Builder
    {
        $operator = $late ? '>' : '<=';
        $grace = $this->tardyGraceMinutes();
        $tz = $this->timezone();
        $defaultCutoff = $this->lateCutoffTimeString();
        $sectionSchedules = $this->sectionSchedules();
        $yearOverrides = $this->loginTimeOverrides();

        return $query->where(function (Builder $outer) use (
            $operator,
            $grace,
            $tz,
            $defaultCutoff,
            $sectionSchedules,
            $yearOverrides
        ) {
            foreach ($sectionSchedules as $sched) {
                $cutoff = Carbon::today($tz)
                    ->setTimeFromTimeString($sched['login_time'])
                    ->addMinutes($grace)
                    ->format('H:i:s');

                $outer->orWhere(function (Builder $q) use ($sched, $cutoff, $operator) {
                    $q->whereHas('student', function (Builder $s) use ($sched) {
                        $this->constrainStudentToSectionSchedule($s, $sched);
                    })->whereTime('scanned_at', $operator, $cutoff);
                });
            }

            foreach ($yearOverrides as $year => $loginTime) {
                $cutoff = Carbon::today($tz)
                    ->setTimeFromTimeString($loginTime)
                    ->addMinutes($grace)
                    ->format('H:i:s');

                $excludedSections = $this->sectionsCoveredForYear($year, $sectionSchedules);

                $outer->orWhere(function (Builder $q) use ($year, $cutoff, $operator, $excludedSections) {
                    $q->whereHas('student', function (Builder $s) use ($year, $excludedSections) {
                        $s->where('year', $year);
                        if ($excludedSections !== []) {
                            $s->where(function (Builder $notEvening) use ($excludedSections) {
                                $notEvening->whereNull('section')
                                    ->orWhere('section', '')
                                    ->orWhereRaw(
                                        'LOWER(TRIM(section)) NOT IN ('.implode(',', array_fill(0, count($excludedSections), '?')).')',
                                        $excludedSections
                                    );
                            });
                        }
                    })->whereTime('scanned_at', $operator, $cutoff);
                });
            }

            $outer->orWhere(function (Builder $q) use (
                $defaultCutoff,
                $operator,
                $yearOverrides,
                $sectionSchedules
            ) {
                $q->where(function (Builder $notSpecial) use ($yearOverrides, $sectionSchedules) {
                    $notSpecial->whereDoesntHave('student')
                        ->orWhereHas('student', function (Builder $s) use ($yearOverrides, $sectionSchedules) {
                            $s->where(function (Builder $studentScope) use ($yearOverrides, $sectionSchedules) {
                                $overrideYears = array_keys($yearOverrides);
                                if ($overrideYears !== []) {
                                    $studentScope->whereNotIn('year', $overrideYears);
                                }

                                foreach ($sectionSchedules as $sched) {
                                    $studentScope->where(function (Builder $notSched) use ($sched) {
                                        $notSched->whereNotIn('year', $sched['years'])
                                            ->orWhere(function (Builder $wrongSection) use ($sched) {
                                                $keys = array_map(
                                                    fn (string $name) => mb_strtolower($name),
                                                    $sched['sections']
                                                );
                                                $wrongSection->whereNull('section')
                                                    ->orWhere('section', '')
                                                    ->orWhereRaw(
                                                        'LOWER(TRIM(section)) NOT IN ('.implode(',', array_fill(0, count($keys), '?')).')',
                                                        $keys
                                                    );
                                            });
                                    });
                                }
                            });
                        });
                })->whereTime('scanned_at', $operator, $defaultCutoff);
            });
        });
    }

    public function isDepartureWindow(Carbon $scannedAt, ?string $year = null, ?string $section = null): bool
    {
        $tz = $this->timezone();
        $cutoff = Carbon::today($tz)->setTimeFromTimeString($this->logoutTime($year, $section));

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

    protected function normalizeLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array{years: list<string>, sections: list<string>, login_time: string, logout_time: string}  $sched
     */
    protected function constrainStudentToSectionSchedule(Builder $studentQuery, array $sched): void
    {
        $studentQuery->whereIn('year', $sched['years'])
            ->where(function (Builder $sectionQ) use ($sched) {
                foreach ($sched['sections'] as $i => $name) {
                    $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                    $sectionQ->{$method}('LOWER(TRIM(section)) = ?', [mb_strtolower($name)]);
                }
            });
    }

    /**
     * @param  list<array{years: list<string>, sections: list<string>, login_time: string, logout_time: string}>  $sectionSchedules
     * @return list<string> lowercase section names
     */
    protected function sectionsCoveredForYear(string $year, array $sectionSchedules): array
    {
        $sections = [];
        foreach ($sectionSchedules as $sched) {
            if (! in_array($year, $sched['years'], true)) {
                continue;
            }
            foreach ($sched['sections'] as $name) {
                $sections[] = mb_strtolower($name);
            }
        }

        return array_values(array_unique($sections));
    }
}
