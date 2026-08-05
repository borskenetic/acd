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
     * Expected login (H:i). Temporary override > section schedule > year/SHS > gate default.
     */
    public function loginTime(?string $year = null, ?string $section = null): string
    {
        $temp = $this->activeTemporaryOverride();
        $schedule = $this->scheduleFor($year, $section);

        if ($schedule !== null) {
            if ($temp !== null && ($temp['apply_to_shs_evening'] ?? false)) {
                return $temp['login_time'];
            }

            return $schedule['login_time'];
        }

        $year = $this->normalizeLabel($year);
        if ($year !== null && $this->isSeniorHighYear($year)) {
            if ($temp !== null && ($temp['apply_to_shs'] ?? false)) {
                return $temp['login_time'];
            }

            return $this->shsLoginTime();
        }

        if ($year !== null) {
            $overrides = $this->loginTimeOverrides();
            if (isset($overrides[$year])) {
                if ($temp !== null && ($temp['apply_to_default'] ?? true)) {
                    return $temp['login_time'];
                }

                return $overrides[$year];
            }
        }

        if ($temp !== null && ($temp['apply_to_default'] ?? true)) {
            return $temp['login_time'];
        }

        return (string) ($this->policy()['login_time'] ?? config('attendance.gate.login_time', '08:00'));
    }

    /**
     * Expected logout (H:i). Temporary override > section schedule > SHS > gate default.
     */
    public function logoutTime(?string $year = null, ?string $section = null): string
    {
        $temp = $this->activeTemporaryOverride();
        $schedule = $this->scheduleFor($year, $section);

        if ($schedule !== null) {
            if ($temp !== null && ($temp['apply_to_shs_evening'] ?? false)) {
                return $temp['logout_time'];
            }

            return $schedule['logout_time'];
        }

        $year = $this->normalizeLabel($year);
        if ($year !== null && $this->isSeniorHighYear($year)) {
            if ($temp !== null && ($temp['apply_to_shs'] ?? false)) {
                return $temp['logout_time'];
            }

            return $this->shsLogoutTime();
        }

        if ($temp !== null && ($temp['apply_to_default'] ?? true)) {
            return $temp['logout_time'];
        }

        return (string) ($this->policy()['logout_time'] ?? config('attendance.gate.logout_time', '16:00'));
    }

    public function shsLoginTime(): string
    {
        return (string) ($this->policy()['shs_login_time']
            ?? config('attendance.gate.shs_login_time')
            ?? config('attendance.gate.login_time_by_year.Grade 12')
            ?? config('attendance.gate.login_time', '07:30'));
    }

    public function shsLogoutTime(): string
    {
        return (string) ($this->policy()['shs_logout_time']
            ?? config('attendance.gate.shs_logout_time')
            ?? config('attendance.gate.logout_time', '16:00'));
    }

    public function shsEveningLoginTime(): string
    {
        $cfg = $this->configEveningSchedule();

        return (string) ($this->policy()['shs_evening_login_time']
            ?? config('attendance.gate.night_login_time')
            ?? ($cfg['login_time'] ?? null)
            ?? '16:30');
    }

    public function shsEveningLogoutTime(): string
    {
        $cfg = $this->configEveningSchedule();

        return (string) ($this->policy()['shs_evening_logout_time']
            ?? config('attendance.gate.night_logout_time')
            ?? ($cfg['logout_time'] ?? null)
            ?? '21:00');
    }

    /**
     * Permanent base login (ignores temporary override). Used for form display of saved defaults.
     */
    public function permanentLoginTime(): string
    {
        return (string) ($this->policy()['login_time'] ?? config('attendance.gate.login_time', '07:30'));
    }

    public function permanentLogoutTime(): string
    {
        return (string) ($this->policy()['logout_time'] ?? config('attendance.gate.logout_time', '16:00'));
    }

    /**
     * Year labels with a non-default expected login time (H:i).
     * SHS years use policy shs_login_time when set.
     *
     * @return array<string, string>
     */
    public function loginTimeOverrides(): array
    {
        $out = [];
        $raw = config('attendance.gate.login_time_by_year', []);
        if (is_array($raw)) {
            foreach ($raw as $year => $time) {
                $year = $this->normalizeLabel(is_string($year) ? $year : null);
                if ($year === null || ! is_string($time) || trim($time) === '') {
                    continue;
                }
                $out[$year] = $this->normalizeTimeInput($time);
            }
        }

        $shsLogin = $this->shsLoginTime();
        foreach ($this->seniorHighYears() as $year) {
            $out[$year] = $shsLogin;
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
        if (! is_array($raw) || $raw === []) {
            $raw = [[
                'years' => $this->seniorHighYears(),
                'sections' => config('attendance.gate.evening_sections', [
                    'Abigail', 'Abigail Evening', 'Dignity', 'Dignity Evening',
                ]),
                'login_time' => $this->shsEveningLoginTime(),
                'logout_time' => $this->shsEveningLogoutTime(),
            ]];
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

            // Prefer admin-saved evening times over config defaults when years are SHS.
            $isShsEvening = count(array_intersect($years, $this->seniorHighYears())) > 0;
            $login = is_string($row['login_time'] ?? null) ? $this->normalizeTimeInput($row['login_time']) : null;
            $logout = is_string($row['logout_time'] ?? null) ? $this->normalizeTimeInput($row['logout_time']) : null;

            if ($isShsEvening) {
                $login = $this->shsEveningLoginTime();
                $logout = $this->shsEveningLogoutTime();
            }

            if ($login === null) {
                continue;
            }

            $out[] = [
                'years' => $years,
                'sections' => $sections,
                'login_time' => $login,
                'logout_time' => $logout ?? $this->permanentLogoutTime(),
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

        foreach ($this->sectionSchedules() as $sched) {
            if (! in_array($year, $sched['years'], true)) {
                continue;
            }

            foreach ($sched['sections'] as $name) {
                if ($this->sectionMatches($section, $name)) {
                    return [
                        'login_time' => $sched['login_time'],
                        'logout_time' => $sched['logout_time'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Case-insensitive section match: exact, or either contains the other
     * (so "Abigail" matches "Abigail Evening" and vice versa).
     */
    public function sectionMatches(string $studentSection, string $configuredSection): bool
    {
        $a = mb_strtolower(trim($studentSection));
        $b = mb_strtolower(trim($configuredSection));
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return str_contains($a, $b) || str_contains($b, $a);
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
                        $this->constrainStudentOutsideSections($s, $excludedSections);
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
                                                $this->constrainStudentOutsideSections($wrongSection, $sched['sections']);
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

    /**
     * Active temporary time change for "today", or null when none / expired.
     *
     * @return array{
     *   login_time: string,
     *   logout_time: string,
     *   starts_on: string,
     *   ends_on: string,
     *   apply_to_default: bool,
     *   apply_to_shs: bool,
     *   apply_to_shs_evening: bool
     * }|null
     */
    public function activeTemporaryOverride(?Carbon $asOf = null): ?array
    {
        $raw = $this->policy()['temporary_override'] ?? null;
        if (! is_array($raw) || empty($raw['enabled'])) {
            return null;
        }

        $starts = is_string($raw['starts_on'] ?? null) ? $raw['starts_on'] : null;
        $ends = is_string($raw['ends_on'] ?? null) ? $raw['ends_on'] : null;
        if ($starts === null || $ends === null) {
            return null;
        }

        $tz = $this->timezone();
        $day = ($asOf ?? Carbon::now($tz))->copy()->timezone($tz)->toDateString();
        if ($day < $starts || $day > $ends) {
            return null;
        }

        $login = is_string($raw['login_time'] ?? null) ? $this->normalizeTimeInput($raw['login_time']) : null;
        $logout = is_string($raw['logout_time'] ?? null) ? $this->normalizeTimeInput($raw['logout_time']) : null;
        if ($login === null || $logout === null) {
            return null;
        }

        return [
            'login_time' => $login,
            'logout_time' => $logout,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'apply_to_default' => (bool) ($raw['apply_to_default'] ?? true),
            'apply_to_shs' => (bool) ($raw['apply_to_shs'] ?? false),
            'apply_to_shs_evening' => (bool) ($raw['apply_to_shs_evening'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    public function toFormValues(): array
    {
        $temp = is_array($this->policy()['temporary_override'] ?? null)
            ? $this->policy()['temporary_override']
            : [];

        return [
            'login_time' => $this->normalizeTimeInput($this->permanentLoginTime()),
            'logout_time' => $this->normalizeTimeInput($this->permanentLogoutTime()),
            'shs_login_time' => $this->normalizeTimeInput($this->shsLoginTime()),
            'shs_logout_time' => $this->normalizeTimeInput($this->shsLogoutTime()),
            'shs_evening_login_time' => $this->normalizeTimeInput($this->shsEveningLoginTime()),
            'shs_evening_logout_time' => $this->normalizeTimeInput($this->shsEveningLogoutTime()),
            'tardy_grace_minutes' => $this->tardyGraceMinutes(),
            'consecutive_late_threshold' => $this->consecutiveLateThreshold(),
            'consecutive_absent_threshold' => $this->consecutiveAbsentThreshold(),
            'temp_enabled' => ! empty($temp['enabled']),
            'temp_login_time' => isset($temp['login_time']) ? $this->normalizeTimeInput((string) $temp['login_time']) : '',
            'temp_logout_time' => isset($temp['logout_time']) ? $this->normalizeTimeInput((string) $temp['logout_time']) : '',
            'temp_starts_on' => (string) ($temp['starts_on'] ?? ''),
            'temp_ends_on' => (string) ($temp['ends_on'] ?? ''),
            'temp_apply_to_default' => (bool) ($temp['apply_to_default'] ?? true),
            'temp_apply_to_shs' => (bool) ($temp['apply_to_shs'] ?? false),
            'temp_apply_to_shs_evening' => (bool) ($temp['apply_to_shs_evening'] ?? false),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function save(array $data): void
    {
        $existing = $this->policy();

        $payload = [
            'login_time' => $this->normalizeTimeInput((string) ($data['login_time'] ?? '07:30')),
            'logout_time' => $this->normalizeTimeInput((string) ($data['logout_time'] ?? '16:00')),
            'shs_login_time' => $this->normalizeTimeInput((string) ($data['shs_login_time'] ?? $this->shsLoginTime())),
            'shs_logout_time' => $this->normalizeTimeInput((string) ($data['shs_logout_time'] ?? $this->shsLogoutTime())),
            'shs_evening_login_time' => $this->normalizeTimeInput((string) ($data['shs_evening_login_time'] ?? $this->shsEveningLoginTime())),
            'shs_evening_logout_time' => $this->normalizeTimeInput((string) ($data['shs_evening_logout_time'] ?? $this->shsEveningLogoutTime())),
            'tardy_grace_minutes' => (int) ($data['tardy_grace_minutes'] ?? 5),
            'consecutive_late_threshold' => (int) ($data['consecutive_late_threshold'] ?? 5),
            'consecutive_absent_threshold' => (int) ($data['consecutive_absent_threshold'] ?? 3),
        ];

        $tempEnabled = ! empty($data['temp_enabled']);
        if ($tempEnabled
            && ! empty($data['temp_login_time'])
            && ! empty($data['temp_logout_time'])
            && ! empty($data['temp_starts_on'])
            && ! empty($data['temp_ends_on'])) {
            $payload['temporary_override'] = [
                'enabled' => true,
                'login_time' => $this->normalizeTimeInput((string) $data['temp_login_time']),
                'logout_time' => $this->normalizeTimeInput((string) $data['temp_logout_time']),
                'starts_on' => (string) $data['temp_starts_on'],
                'ends_on' => (string) $data['temp_ends_on'],
                'apply_to_default' => ! empty($data['temp_apply_to_default']),
                'apply_to_shs' => ! empty($data['temp_apply_to_shs']),
                'apply_to_shs_evening' => ! empty($data['temp_apply_to_shs_evening']),
                // Snapshot of permanent times for audit/clarity (auto-revert is lookup-based).
                'reverts_to' => [
                    'login_time' => $payload['login_time'],
                    'logout_time' => $payload['logout_time'],
                    'shs_login_time' => $payload['shs_login_time'],
                    'shs_logout_time' => $payload['shs_logout_time'],
                    'shs_evening_login_time' => $payload['shs_evening_login_time'],
                    'shs_evening_logout_time' => $payload['shs_evening_logout_time'],
                ],
            ];
        } else {
            $payload['temporary_override'] = [
                'enabled' => false,
            ];
        }

        // Preserve unknown keys from older policy payloads.
        Setting::setAttendancePolicy(array_merge($existing, $payload));
    }

    /** @return array<string, mixed> */
    protected function policy(): array
    {
        return Setting::attendancePolicy();
    }

    /** @return list<string> */
    public function seniorHighYears(): array
    {
        $years = config('patron.senior_high_grades', ['Grade 11', 'Grade 12']);

        return is_array($years) ? array_values($years) : ['Grade 11', 'Grade 12'];
    }

    public function isSeniorHighYear(?string $year): bool
    {
        $year = $this->normalizeLabel($year);

        return $year !== null && in_array($year, $this->seniorHighYears(), true);
    }

    /** @return array{years: list<string>, sections: list<string>, login_time: string, logout_time: string}|null */
    protected function configEveningSchedule(): ?array
    {
        $raw = config('attendance.gate.schedules_by_year_section.0');

        return is_array($raw) ? $raw : null;
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
                    $needle = mb_strtolower(trim($name));
                    $method = $i === 0 ? 'where' : 'orWhere';
                    $sectionQ->{$method}(function (Builder $one) use ($needle) {
                        $one->whereRaw('LOWER(TRIM(section)) = ?', [$needle])
                            ->orWhereRaw('LOWER(TRIM(section)) LIKE ?', ['%'.$needle.'%'])
                            ->orWhereRaw('? LIKE CONCAT(\'%\', LOWER(TRIM(section)), \'%\')', [$needle]);
                    });
                }
            });
    }

    /**
     * Student is NOT on any configured evening/section schedule for their year.
     *
     * @param  list<string>  $sectionNames
     */
    protected function constrainStudentOutsideSections(Builder $studentQuery, array $sectionNames): void
    {
        if ($sectionNames === []) {
            return;
        }

        $studentQuery->where(function (Builder $notEvening) use ($sectionNames) {
            $notEvening->whereNull('section')
                ->orWhere('section', '')
                ->orWhere(function (Builder $sec) use ($sectionNames) {
                    foreach ($sectionNames as $name) {
                        $needle = mb_strtolower(trim($name));
                        $sec->whereRaw('LOWER(TRIM(section)) <> ?', [$needle])
                            ->whereRaw('LOWER(TRIM(section)) NOT LIKE ?', ['%'.$needle.'%'])
                            ->whereRaw('? NOT LIKE CONCAT(\'%\', LOWER(TRIM(section)), \'%\')', [$needle]);
                    }
                });
        });
    }

    /**
     * @param  list<array{years: list<string>, sections: list<string>, login_time: string, logout_time: string}>  $sectionSchedules
     * @return list<string> section names as configured
     */
    protected function sectionsCoveredForYear(string $year, array $sectionSchedules): array
    {
        $sections = [];
        foreach ($sectionSchedules as $sched) {
            if (! in_array($year, $sched['years'], true)) {
                continue;
            }
            foreach ($sched['sections'] as $name) {
                $sections[] = $name;
            }
        }

        return array_values(array_unique($sections));
    }
}
