<?php

namespace Database\Seeders;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\User;
use App\Models\UserAdvisory;
use App\Services\AttendancePolicyService;
use App\Services\Sf2SchoolCalendar;
use App\Services\StudentConsecutiveAttendanceService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Demo students with streak logs so Students list shows orange (late) / red (absent) rows.
 *
 * Run:
 *   php artisan db:seed --class=StreakHighlightDemoSeeder
 */
class StreakHighlightDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $asOf = Carbon::now($tz)->startOfDay();
        $policy = app(AttendancePolicyService::class);
        $calendar = app(Sf2SchoolCalendar::class);
        $streaks = app(StudentConsecutiveAttendanceService::class);

        $lateThreshold = $policy->consecutiveLateThreshold();
        $absentThreshold = $policy->consecutiveAbsentThreshold();

        $schoolDays = $this->attendanceDaysBack($calendar, $asOf, max(20, $lateThreshold + $absentThreshold + 10));

        if (count($schoolDays) < max($lateThreshold, $absentThreshold)) {
            $this->command?->warn('Not enough attendance days to build streaks. Check calendar / timezone.');

            return;
        }

        $cohort = $this->cohortDefinition();
        $qrNumber = $this->nextQrSequence();
        $students = [];

        foreach ($cohort as $row) {
            $fullName = trim($row['firstname'].' '.$row['lastname']);
            $row['normalized_name'] = NormalizeStudentNames::normalizeFullName($fullName);
            $row['role_id'] = null;

            $existing = Student::where('student_id', $row['student_id'])->first();
            if (! $existing) {
                $row['qrcode'] = 'S-'.str_pad((string) $qrNumber, 8, '0', STR_PAD_LEFT);
                $qrNumber++;
            }

            $students[$row['student_id']] = Student::updateOrCreate(
                ['student_id' => $row['student_id']],
                $row
            );
        }

        $ids = collect($students)->pluck('id')->all();

        // Clear recent demo logs for these students so seeding is re-runnable.
        $from = Carbon::parse($schoolDays[0], $tz)->startOfDay();
        AttendanceLog::query()
            ->whereIn('student_id', $ids)
            ->where('scanned_at', '>=', $from)
            ->where(function ($q) {
                $q->where('section', 'Streak demo')
                    ->orWhere('source', 'streak_demo');
            })
            ->delete();

        // Also remove any leftover auto/demo INs for clean re-runs on these IDs in the window.
        AttendanceLog::query()
            ->whereIn('student_id', $ids)
            ->where('scanned_at', '>=', $from)
            ->delete();

        $recent = array_slice($schoolDays, -max($lateThreshold, $absentThreshold) - 2);

        // ── Absent demo: no IN on the last N attendance days (non-Friday preferred) ──
        $absentDays = $this->lastNonFridayDays($schoolDays, $absentThreshold, $calendar);
        // No logs for Absent Annie on those days (and none on intervening Fridays either is fine —
        // Friday online resets absent streak, so avoid Fridays in the streak window).
        // Fill older days with on-time IN so only the tail is consecutive-absent.
        foreach ($schoolDays as $date) {
            if (in_array($date, $absentDays, true)) {
                continue;
            }
            $this->seedIn($students['DEMO-ABSENT-001']->id, $date, '07:20', $tz);
        }

        // ── Late demo: late first IN every day for the last N attendance days ──
        $lateDays = array_slice($schoolDays, -$lateThreshold);
        foreach ($schoolDays as $date) {
            if (in_array($date, $lateDays, true)) {
                // After default grace: login 07:30 + 5 min = 07:35 → 09:00 is clearly late.
                $this->seedIn($students['DEMO-LATE-001']->id, $date, '09:15', $tz);
            } else {
                $this->seedIn($students['DEMO-LATE-001']->id, $date, '07:15', $tz);
            }
        }

        // ── Control student: always on time (no highlight) ──
        foreach ($schoolDays as $date) {
            $this->seedIn($students['DEMO-OK-001']->id, $date, '07:10', $tz);
        }

        // ── Both thresholds met historically then absent wins: last 3 absent ──
        // Build late streak first on older days, then absent tail (red wins).
        $mixedLateBody = array_slice($schoolDays, -($lateThreshold + $absentThreshold), $lateThreshold);
        $mixedAbsentTail = $this->lastNonFridayDays($schoolDays, $absentThreshold, $calendar);
        foreach ($schoolDays as $date) {
            if (in_array($date, $mixedAbsentTail, true)) {
                continue; // absent
            }
            if (in_array($date, $mixedLateBody, true)) {
                $this->seedIn($students['DEMO-MIXED-001']->id, $date, '09:20', $tz);
            } else {
                $this->seedIn($students['DEMO-MIXED-001']->id, $date, '07:12', $tz);
            }
        }

        // Faculty adviser bound to the demo class (optional, for roles UI).
        if (Schema::hasTable('users')) {
            $faculty = User::updateOrCreate(
                ['email' => 'faculty.demo@library.local'],
                [
                    'fname' => 'Demo',
                    'lname' => 'Adviser',
                    'password' => Hash::make('password'),
                    'role' => 'faculty',
                    'advisory_year' => 'Grade 8',
                    'advisory_section' => 'St. Clare',
                ]
            );

            if (Schema::hasTable('user_advisories')) {
                UserAdvisory::updateOrCreate(
                    [
                        'user_id' => $faculty->id,
                        'year' => 'Grade 8',
                        'section' => 'St. Clare',
                    ],
                    ['access_level' => UserAdvisory::LEVEL_ADVISER]
                );
            }
        }

        $report = [];
        foreach ($students as $code => $student) {
            $counts = $streaks->countsForStudent($student, $asOf);
            $report[] = sprintf(
                '  %s %s, %s — late:%d absent:%d%s%s',
                $code,
                $student->lastname,
                $student->firstname,
                $counts['consecutive_late'],
                $counts['consecutive_absent'],
                $counts['consecutive_absent'] >= $absentThreshold ? ' [RED]' : '',
                $counts['consecutive_late'] >= $lateThreshold
                    && $counts['consecutive_absent'] < $absentThreshold ? ' [ORANGE]' : ''
            );
        }

        $this->command?->info('Streak highlight demo data ready (Grade 8 · St. Clare).');
        $this->command?->line(sprintf(
            'Thresholds: %d consecutive lates (orange), %d consecutive absences (red).',
            $lateThreshold,
            $absentThreshold
        ));
        foreach ($report as $line) {
            $this->command?->line($line);
        }
        $this->command?->line('Open Students list — filter Year = Grade 8 or search DEMO- / Absent / Latey / Mixed.');
        if (Schema::hasTable('users')) {
            $this->command?->line('Faculty login: faculty.demo@library.local / password (adviser of Grade 8 · St. Clare).');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cohortDefinition(): array
    {
        return [
            [
                'student_id' => 'DEMO-ABSENT-001',
                'firstname' => 'Annie',
                'lastname' => 'Absent',
                'middle_initial' => 'R',
                'sex' => 'female',
                'section' => 'St. Clare',
                'educational_level' => 'high_school_junior',
                'course' => 'General',
                'year' => 'Grade 8',
                'mobile_number' => '09190001001',
                'emergency_number' => '09190001091',
                'emergency_person' => 'Parent Absent',
                'birth_date' => '2012-04-01',
            ],
            [
                'student_id' => 'DEMO-LATE-001',
                'firstname' => 'Larry',
                'lastname' => 'Latey',
                'middle_initial' => 'T',
                'sex' => 'male',
                'section' => 'St. Clare',
                'educational_level' => 'high_school_junior',
                'course' => 'General',
                'year' => 'Grade 8',
                'mobile_number' => '09190001002',
                'emergency_number' => '09190001092',
                'emergency_person' => 'Parent Latey',
                'birth_date' => '2012-05-15',
            ],
            [
                'student_id' => 'DEMO-OK-001',
                'firstname' => 'Oliver',
                'lastname' => 'Ontime',
                'middle_initial' => 'K',
                'sex' => 'male',
                'section' => 'St. Clare',
                'educational_level' => 'high_school_junior',
                'course' => 'General',
                'year' => 'Grade 8',
                'mobile_number' => '09190001003',
                'emergency_number' => '09190001093',
                'emergency_person' => 'Parent Ontime',
                'birth_date' => '2012-06-20',
            ],
            [
                'student_id' => 'DEMO-MIXED-001',
                'firstname' => 'Mia',
                'lastname' => 'Mixed',
                'middle_initial' => 'S',
                'sex' => 'female',
                'section' => 'St. Clare',
                'educational_level' => 'high_school_junior',
                'course' => 'General',
                'year' => 'Grade 8',
                'mobile_number' => '09190001004',
                'emergency_number' => '09190001094',
                'emergency_person' => 'Parent Mixed',
                'birth_date' => '2012-08-08',
            ],
        ];
    }

    /**
     * Attendance days ending at $asOf (oldest → newest).
     *
     * @return list<string>
     */
    private function attendanceDaysBack(Sf2SchoolCalendar $calendar, Carbon $asOf, int $max): array
    {
        $days = [];
        $cursor = $asOf->copy()->startOfDay();
        $guard = 0;
        while (count($days) < $max && $guard < $max * 4) {
            $key = $cursor->toDateString();
            if ($calendar->isAttendanceDay($key)) {
                $days[] = $key;
            }
            $cursor->subDay();
            $guard++;
        }

        return array_reverse($days);
    }

    /**
     * Last N attendance days that are not Friday online days (so absence streak is not broken).
     *
     * @param  list<string>  $schoolDays
     * @return list<string>
     */
    private function lastNonFridayDays(array $schoolDays, int $need, Sf2SchoolCalendar $calendar): array
    {
        $picked = [];
        for ($i = count($schoolDays) - 1; $i >= 0 && count($picked) < $need; $i--) {
            $date = $schoolDays[$i];
            if ($calendar->isFridayOnlineDay($date)) {
                continue;
            }
            $picked[] = $date;
        }

        return array_reverse($picked);
    }

    private function seedIn(int $studentId, string $date, string $time, string $tz): void
    {
        AttendanceLog::create([
            'student_id' => $studentId,
            'status' => 'IN',
            'section' => 'Streak demo',
            'scanned_at' => Carbon::parse($date.' '.$time, $tz),
            'source' => 'streak_demo',
        ]);
    }

    private function nextQrSequence(): int
    {
        $last = Student::query()
            ->whereNotNull('qrcode')
            ->where('qrcode', 'like', 'S-%')
            ->orderByDesc('id')
            ->value('qrcode');

        if ($last && preg_match('/S-(\d+)/', $last, $matches)) {
            return (int) $matches[1] + 1;
        }

        return 1;
    }
}
