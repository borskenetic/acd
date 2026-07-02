<?php

namespace Database\Seeders;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\AttendanceLog;
use App\Models\Student;
use App\Services\AttendancePolicyService;
use App\Services\Sf2SchoolCalendar;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds a Grade 7 / St. Francis cohort plus June attendance IN logs for SF2 auto-generate testing.
 *
 * Run: php artisan db:seed --class=Sf2TestDataSeeder
 */
class Sf2TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $year = (int) now($tz)->format('Y');
        $month = (int) now($tz)->format('n');

        $schoolDays = app(Sf2SchoolCalendar::class)->schoolDaysInMonth($year, $month);

        if ($schoolDays === []) {
            $this->command?->warn('No school days in the current month — nothing to seed.');

            return;
        }

        $cohort = $this->cohortDefinition();
        $qrNumber = $this->nextQrSequence();
        $studentIds = [];

        foreach ($cohort as $row) {
            $fullName = trim($row['firstname'].' '.$row['lastname']);
            $row['normalized_name'] = NormalizeStudentNames::normalizeFullName($fullName);
            $row['role_id'] = null;

            $existing = Student::where('student_id', $row['student_id'])->first();
            if (! $existing) {
                $row['qrcode'] = 'S-'.str_pad((string) $qrNumber, 8, '0', STR_PAD_LEFT);
                $qrNumber++;
            }

            $student = Student::updateOrCreate(
                ['student_id' => $row['student_id']],
                $row
            );

            $studentIds[$row['student_id']] = $student->id;
        }

        AttendanceLog::query()
            ->whereIn('student_id', array_values($studentIds))
            ->whereBetween('scanned_at', [
                Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfDay(),
                Carbon::create($year, $month, 1, 0, 0, 0, $tz)->endOfMonth()->endOfDay(),
            ])
            ->delete();

        $patterns = $this->attendancePatterns($schoolDays, $tz);
        $policy = app(AttendancePolicyService::class);
        $lateCount = 0;

        foreach ($patterns as $studentId => $dayTimes) {
            $dbId = $studentIds[$studentId] ?? null;
            if (! $dbId) {
                continue;
            }

            foreach ($dayTimes as $date => $time) {
                $scannedAt = Carbon::parse($date.' '.$time, $tz);
                if ($policy->isLateIn($scannedAt)) {
                    $lateCount++;
                }

                AttendanceLog::create([
                    'student_id' => $dbId,
                    'status' => 'IN',
                    'section' => null,
                    'scanned_at' => $scannedAt,
                ]);
            }
        }

        $lateCutoffLabel = Carbon::today($tz)
            ->setTimeFromTimeString($policy->lateCutoffTimeString())
            ->format('g:i A');

        $monthLabel = config('sf2.month_names')[$month] ?? (string) $month;

        $this->command?->info(sprintf(
            'SF2 test data ready: Grade 7 / St. Francis (%d learners), %s %d (%d school days, %d late IN logs after %s).',
            count($cohort),
            $monthLabel,
            $year,
            count($schoolDays),
            $lateCount,
            $lateCutoffLabel
        ));
        $this->command?->line('SF2 → Create → Grade 7, St. Francis, current month → Load from attendance logs.');
        $this->command?->line('Attendance logs → filter Late: Reyes (5 consecutive lates), Lopez (Fridays), Santos (every other day).');
    }

    /** @return list<array<string, mixed>> */
    private function cohortDefinition(): array
    {
        return [
            [
                'student_id' => 'SF2-TEST-001',
                'firstname' => 'Antonio',
                'lastname' => 'Cruz',
                'middle_initial' => 'M',
                'sex' => 'male',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111001',
                'emergency_number' => '09181111001',
                'birth_date' => '2012-04-10',
            ],
            [
                'student_id' => 'SF2-TEST-002',
                'firstname' => 'Rafael',
                'lastname' => 'Reyes',
                'middle_initial' => 'D',
                'sex' => 'male',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111002',
                'emergency_number' => '09181111002',
                'birth_date' => '2012-08-22',
            ],
            [
                'student_id' => 'SF2-TEST-003',
                'firstname' => 'Diego',
                'lastname' => 'Lopez',
                'middle_initial' => 'A',
                'sex' => 'male',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111003',
                'emergency_number' => '09181111003',
                'birth_date' => '2012-01-05',
            ],
            [
                'student_id' => 'SF2-TEST-004',
                'firstname' => 'Carmela',
                'lastname' => 'Garcia',
                'middle_initial' => 'L',
                'sex' => 'female',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111004',
                'emergency_number' => '09181111004',
                'birth_date' => '2012-11-30',
            ],
            [
                'student_id' => 'SF2-TEST-005',
                'firstname' => 'Beatriz',
                'lastname' => 'Santos',
                'middle_initial' => 'R',
                'sex' => 'female',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111005',
                'emergency_number' => '09181111005',
                'birth_date' => '2012-06-18',
            ],
            [
                'student_id' => 'SF2-TEST-006',
                'firstname' => 'Isabel',
                'lastname' => 'Mendoza',
                'middle_initial' => 'C',
                'sex' => 'female',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111006',
                'emergency_number' => '09181111006',
                'birth_date' => '2012-09-09',
            ],
        ];
    }

    /**
     * Per-student scan times by date. Omitted dates = absent.
     * On time: 8 minutes before login. Late: 15+ minutes after login + grace.
     *
     * @param  list<string>  $schoolDays
     * @return array<string, array<string, string>>
     */
    private function attendancePatterns(array $schoolDays, string $tz): array
    {
        $policy = app(AttendancePolicyService::class);
        $onTime = Carbon::today($tz)
            ->setTimeFromTimeString($policy->loginTime())
            ->subMinutes(8)
            ->format('H:i:s');
        $tardy = Carbon::today($tz)
            ->setTimeFromTimeString($policy->lateCutoffTimeString())
            ->addMinutes(15)
            ->format('H:i:s');
        $veryLate = Carbon::today($tz)
            ->setTimeFromTimeString($policy->lateCutoffTimeString())
            ->addMinutes(35)
            ->format('H:i:s');

        $patterns = [
            'SF2-TEST-001' => [], // perfect on-time attendance
            'SF2-TEST-002' => [], // absent first 2 days, then 5 consecutive lates
            'SF2-TEST-003' => [], // late every Friday
            'SF2-TEST-004' => [], // perfect on-time attendance
            'SF2-TEST-005' => [], // present every other day — mix on time and late
            'SF2-TEST-006' => [], // absent last 3 school days
        ];

        foreach ($schoolDays as $i => $date) {
            $dow = Carbon::parse($date, $tz)->dayOfWeekIso;

            $patterns['SF2-TEST-001'][$date] = $onTime;

            if ($i >= 2) {
                if ($i >= 2 && $i <= 6) {
                    $patterns['SF2-TEST-002'][$date] = ($i % 2 === 0) ? $tardy : $veryLate;
                } else {
                    $patterns['SF2-TEST-002'][$date] = $onTime;
                }
            }

            $patterns['SF2-TEST-003'][$date] = ($dow === 5) ? $veryLate : $onTime;

            $patterns['SF2-TEST-004'][$date] = $onTime;

            if ($i % 2 === 0) {
                $patterns['SF2-TEST-005'][$date] = ($i % 4 === 0) ? $veryLate : $tardy;
            }

            $lastIndex = count($schoolDays) - 1;
            if ($i < $lastIndex - 2) {
                $patterns['SF2-TEST-006'][$date] = $onTime;
            }
        }

        return $patterns;
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
