<?php

namespace Database\Seeders;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\AttendanceLog;
use App\Models\GradeSection;
use App\Models\SchoolCalendarDay;
use App\Models\Sf2Report;
use App\Models\Sf2ReportStudent;
use App\Models\Student;
use App\Services\AttendancePolicyService;
use App\Services\Sf2AttendanceLogMapper;
use App\Services\Sf2SchoolCalendar;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds demo cohorts + IN logs for testing both SF2 exports:
 * - K–10: Grade 4 / St. Carlo Acutis (half-day afternoon INs, absences, tardies)
 * - SHS:  Grade 11 / St. Ignatius
 *
 * Also creates ready SF2 report rows for the current month so you can
 * open SF2 list → Download Excel immediately.
 *
 * Run: php artisan db:seed --class=Sf2TestDataSeeder
 * Then:  php artisan migrate  (if half_day_dates column is missing)
 */
class Sf2TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $year = (int) now($tz)->format('Y');
        $month = (int) now($tz)->format('n');
        $calendar = app(Sf2SchoolCalendar::class);
        $schoolDays = $calendar->schoolDaysInMonth($year, $month);

        if ($schoolDays === []) {
            $this->command?->warn('No school days in the current month — nothing to seed.');

            return;
        }

        $this->seedHolidayMidMonth($year, $month, $tz, $schoolDays);
        // Refresh after holiday — removed non-class day.
        $schoolDays = $calendar->schoolDaysInMonth($year, $month);

        $qrNumber = $this->nextQrSequence();
        $mapper = app(Sf2AttendanceLogMapper::class);
        $policy = app(AttendancePolicyService::class);

        $classes = [
            $this->k10Class(),
            $this->shsClass(),
        ];

        foreach ($classes as $class) {
            $studentIds = $this->upsertStudents($class['learners'], $qrNumber);
            $qrNumber += count($class['learners']);

            $this->seedGradeSection($class);

            AttendanceLog::query()
                ->whereIn('student_id', array_values($studentIds))
                ->whereBetween('scanned_at', [
                    Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfDay(),
                    Carbon::create($year, $month, 1, 0, 0, 0, $tz)->endOfMonth()->endOfDay(),
                ])
                ->delete();

            $patterns = $class['patterns']($schoolDays, $tz, $policy);
            $logCount = 0;
            foreach ($patterns as $sid => $dayTimes) {
                $dbId = $studentIds[$sid] ?? null;
                if (! $dbId) {
                    continue;
                }
                foreach ($dayTimes as $date => $time) {
                    AttendanceLog::create([
                        'student_id' => $dbId,
                        'status' => 'IN',
                        'section' => null,
                        'scanned_at' => Carbon::parse($date.' '.$time, $tz),
                        'source' => 'sf2_seed',
                    ]);
                    $logCount++;
                }
            }

            $report = $this->createSf2Report(
                $class,
                $year,
                $month,
                $schoolDays,
                $mapper,
                $policy
            );

            $this->command?->info(sprintf(
                '✓ %s / %s — %d learners, %d IN logs, SF2 report #%d (%s template).',
                $class['grade_level'],
                $class['section'],
                count($class['learners']),
                $logCount,
                $report->id,
                $report->usesShsTemplate() ? 'SHS' : 'K–10'
            ));
        }

        $monthLabel = config('sf2.month_names')[$month] ?? (string) $month;
        $this->command?->newLine();
        $this->command?->info("Month: {$monthLabel} {$year} (".count($schoolDays).' school days)');
        $this->command?->line('Try:');
        $this->command?->line('  1) SF2 index → open seeded reports → Download Excel');
        $this->command?->line('  2) SF2 Create → Grade 4 / St. Carlo Acutis → Load from logs');
        $this->command?->line('  3) SF2 Create → Grade 11 / St. Ignatius → Load from logs');
    }

    /**
     * One weekday mid-month as holiday so K–10 export shows a merged "holiday" column.
     *
     * @param  list<string>  $schoolDays
     */
    private function seedHolidayMidMonth(int $year, int $month, string $tz, array $schoolDays): void
    {
        if (! Schema::hasTable('school_calendar_days') || count($schoolDays) < 5) {
            return;
        }

        try {
            $pick = $schoolDays[(int) floor(count($schoolDays) / 2)];
            SchoolCalendarDay::updateOrCreate(
                ['date' => $pick],
                [
                    'type' => SchoolCalendarDay::TYPE_HOLIDAY,
                    'label' => 'holiday',
                    'notes' => 'SF2 demo holiday (seed)',
                ]
            );
            $this->command?->line("Calendar holiday seeded: {$pick}");
        } catch (\Throwable $e) {
            $this->command?->warn('Could not seed calendar holiday: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function k10Class(): array
    {
        return [
            'grade_level' => 'Grade 4',
            'section' => 'St. Carlo Acutis',
            'strand' => '',
            'educational_level' => 'grade_school',
            'course' => null,
            'learner_prefix' => 'SF2-K10',
            'learners' => [
                $this->learner('SF2-K10-001', 'Xander', 'Amil', 'C', 'male', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-002', 'Calvin', 'Cabactulan', 'A', 'male', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-003', 'Jacques', 'Cagulada', 'A', 'male', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-004', 'Rhob', 'Casona', 'D', 'male', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-005', 'John', 'Chiu', 'R', 'male', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-006', 'Rieanne', 'Aledron', 'S', 'female', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-007', 'Auricka', 'Alipin', 'M', 'female', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-008', 'Mary', 'Cabahug', 'V', 'female', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-009', 'Aliazeyah', 'Garbo', 'P', 'female', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
                $this->learner('SF2-K10-010', 'Olivia', 'Olayvar', 'C', 'female', 'Grade 4', 'St. Carlo Acutis', 'grade_school'),
            ],
            'patterns' => function (array $schoolDays, string $tz, AttendancePolicyService $policy): array {
                $onTime = $this->onTime($tz, $policy);
                $tardy = $this->tardyTime($tz, $policy);
                $half = $this->halfDayTime($tz);

                $p = [
                    'SF2-K10-001' => [], // perfect
                    'SF2-K10-002' => [], // some tardy mornings
                    'SF2-K10-003' => [], // half-days (afternoon first IN)
                    'SF2-K10-004' => [], // few absences
                    'SF2-K10-005' => [], // mix
                    'SF2-K10-006' => [], // perfect
                    'SF2-K10-007' => [], // half-day midweek
                    'SF2-K10-008' => [], // perfect
                    'SF2-K10-009' => [], // absent early week
                    'SF2-K10-010' => [], // perfect
                ];

                foreach ($schoolDays as $i => $date) {
                    $p['SF2-K10-001'][$date] = $onTime;
                    $p['SF2-K10-002'][$date] = ($i % 5 === 1) ? $tardy : $onTime;
                    // Afternoon first IN ≈ half-day
                    $p['SF2-K10-003'][$date] = ($i % 4 === 2) ? $half : $onTime;
                    if ($i !== 1 && $i !== 3) {
                        $p['SF2-K10-004'][$date] = $onTime;
                    }
                    $p['SF2-K10-005'][$date] = ($i % 3 === 0) ? $tardy : (($i % 3 === 1) ? $half : $onTime);
                    $p['SF2-K10-006'][$date] = $onTime;
                    $p['SF2-K10-007'][$date] = ($i % 6 === 3) ? $half : $onTime;
                    $p['SF2-K10-008'][$date] = $onTime;
                    if ($i >= 2) {
                        $p['SF2-K10-009'][$date] = $onTime;
                    }
                    $p['SF2-K10-010'][$date] = $onTime;
                }

                return $p;
            },
        ];
    }

    /** @return array<string, mixed> */
    private function shsClass(): array
    {
        return [
            'grade_level' => 'Grade 11',
            'section' => 'St. Ignatius',
            'strand' => 'STEM',
            'educational_level' => 'high_school_senior',
            'course' => 'STEM',
            'learner_prefix' => 'SF2-SHS',
            'learners' => [
                $this->learner('SF2-SHS-001', 'Travis', 'Adlawan', 'F', 'male', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-002', 'Johannes', 'Agocoy', 'R', 'male', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-003', 'Yxian', 'Bornias', 'K', 'male', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-004', 'Charlz', 'Braga', 'B', 'male', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-005', 'Ezekiel', 'Cano', 'C', 'male', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-006', 'Vinz', 'Claro', 'T', 'male', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-007', 'Nikolai', 'Gatchalian', 'A', 'male', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-008', 'Myrhen', 'Fortugaliza', 'L', 'female', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-009', 'Sabella', 'Herbas', 'R', 'female', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-010', 'Mary', 'Lim', 'A', 'female', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-011', 'Janine', 'Mariscal', 'M', 'female', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-012', 'Casey', 'Natividad', 'V', 'female', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
                $this->learner('SF2-SHS-013', 'Breanna', 'Necor', 'T', 'female', 'Grade 11', 'St. Ignatius', 'high_school_senior', 'STEM'),
            ],
            'patterns' => function (array $schoolDays, string $tz, AttendancePolicyService $policy): array {
                $onTime = $this->onTime($tz, $policy, 'Grade 11', 'St. Ignatius');
                $tardy = $this->tardyTime($tz, $policy, 'Grade 11', 'St. Ignatius');

                $p = [];
                foreach ([
                    'SF2-SHS-001', 'SF2-SHS-002', 'SF2-SHS-003', 'SF2-SHS-004', 'SF2-SHS-005',
                    'SF2-SHS-006', 'SF2-SHS-007', 'SF2-SHS-008', 'SF2-SHS-009', 'SF2-SHS-010',
                    'SF2-SHS-011', 'SF2-SHS-012', 'SF2-SHS-013',
                ] as $sid) {
                    $p[$sid] = [];
                }

                foreach ($schoolDays as $i => $date) {
                    // Most present on time
                    foreach (['SF2-SHS-001', 'SF2-SHS-006', 'SF2-SHS-008', 'SF2-SHS-010', 'SF2-SHS-013'] as $sid) {
                        $p[$sid][$date] = $onTime;
                    }
                    // Occasional tardy
                    $p['SF2-SHS-002'][$date] = ($i % 4 === 0) ? $tardy : $onTime;
                    $p['SF2-SHS-009'][$date] = ($i % 5 === 1) ? $tardy : $onTime;
                    // Spot absences
                    if ($i !== 2) {
                        $p['SF2-SHS-003'][$date] = $onTime;
                    }
                    if ($i !== 4 && $i !== 5) {
                        $p['SF2-SHS-004'][$date] = $onTime;
                    }
                    // Multi-day absence cluster
                    if ($i < 3 || $i > 6) {
                        $p['SF2-SHS-005'][$date] = $onTime;
                    }
                    $p['SF2-SHS-007'][$date] = ($i % 3 === 0) ? $tardy : $onTime;
                    $p['SF2-SHS-011'][$date] = $onTime;
                    if ($i % 2 === 0) {
                        $p['SF2-SHS-012'][$date] = $onTime;
                    }
                }

                return $p;
            },
        ];
    }

    /** @return array<string, mixed> */
    private function learner(
        string $studentId,
        string $first,
        string $last,
        string $mi,
        string $sex,
        string $grade,
        string $section,
        string $eduLevel,
        ?string $course = null,
    ): array {
        return [
            'student_id' => $studentId,
            'firstname' => $first,
            'lastname' => $last,
            'middle_initial' => $mi,
            'sex' => $sex,
            'section' => $section,
            'educational_level' => $eduLevel,
            'course' => $course,
            'year' => $grade,
            'mobile_number' => '09'.substr(preg_replace('/\D/', '', $studentId) ?: '170000000', -9),
            'emergency_number' => '09180000000',
            'birth_date' => $eduLevel === 'high_school_senior' ? '2009-05-15' : '2015-03-12',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $learners
     * @return array<string, int> student_id => db id
     */
    private function upsertStudents(array $learners, int $qrStart): array
    {
        $map = [];
        $qrNumber = $qrStart;

        foreach ($learners as $row) {
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
            $map[$row['student_id']] = $student->id;
        }

        return $map;
    }

    /** @param  array<string, mixed>  $class */
    private function seedGradeSection(array $class): void
    {
        if (! Schema::hasTable('grade_sections')) {
            return;
        }

        try {
            GradeSection::firstOrCreate([
                'grade_level' => $class['grade_level'],
                'strand' => (string) ($class['strand'] ?? ''),
                'section' => $class['section'],
            ]);
        } catch (\Throwable) {
            // optional table / unique constraints
        }
    }

    /**
     * @param  array<string, mixed>  $class
     * @param  list<string>  $schoolDays
     */
    private function createSf2Report(
        array $class,
        int $year,
        int $month,
        array $schoolDays,
        Sf2AttendanceLogMapper $mapper,
        AttendancePolicyService $policy,
    ): Sf2Report {
        $preview = $mapper->buildPreview(
            $class['grade_level'],
            $class['section'],
            $year,
            $month
        );

        $school = config('sf2.school', []);

        // Replace existing demo report for this class/month.
        Sf2Report::query()
            ->where('grade_level', $class['grade_level'])
            ->where('section', $class['section'])
            ->where('report_year', $year)
            ->where('report_month', $month)
            ->where(function ($q) {
                $q->where('teacher_name', 'like', '%SF2 Demo%')
                    ->orWhere('teacher_name', 'SF2 Demo Adviser');
            })
            ->each(function (Sf2Report $old) {
                $old->students()->delete();
                $old->delete();
            });

        $report = Sf2Report::create([
            'school_id' => $school['school_id'] ?? '405431',
            'school_name' => $school['name'] ?? 'ASSUMPTION COLLEGE OF DAVAO',
            'school_year' => $this->defaultSchoolYear($year, $month),
            'semester' => $school['semester'] ?? 'FIRST SEMESTER',
            'division' => $school['division'] ?? 'DAVAO CITY',
            'region' => $school['region'] ?? 'XI',
            'report_month' => $month,
            'report_year' => $year,
            'grade_level' => $class['grade_level'],
            'section' => $class['section'],
            'track_and_strand' => $class['strand'] !== ''
                ? $class['strand']
                : ($school['track_and_strand'] ?? null),
            'school_days' => $preview['school_days'] ?: $schoolDays,
            'teacher_name' => 'SF2 Demo Adviser',
            'school_head_name' => 'School Head (Demo)',
        ]);

        $hasHalf = Schema::hasColumn('sf2_report_students', 'half_day_dates');

        foreach ($preview['students'] as $i => $row) {
            $payload = [
                'sf2_report_id' => $report->id,
                'sort_order' => $i,
                'sex' => $row['sex'],
                'last_name' => $row['last_name'],
                'first_name' => $row['first_name'],
                'middle_name' => $row['middle_name'] ?? null,
                'remarks' => $row['remarks'] ?? null,
                'absent_dates' => $this->splitDates($row['absent_dates'] ?? ''),
                'tardy_dates' => $this->splitDates($row['tardy_dates'] ?? ''),
            ];
            if ($hasHalf) {
                $payload['half_day_dates'] = $this->splitDates($row['half_day_dates'] ?? '');
            }
            Sf2ReportStudent::create($payload);
        }

        return $report->fresh(['students']);
    }

    /** @return list<string> */
    private function splitDates(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: [])));
    }

    private function onTime(string $tz, AttendancePolicyService $policy, ?string $year = null, ?string $section = null): string
    {
        return Carbon::today($tz)
            ->setTimeFromTimeString($policy->loginTime($year, $section))
            ->subMinutes(8)
            ->format('H:i:s');
    }

    private function tardyTime(string $tz, AttendancePolicyService $policy, ?string $year = null, ?string $section = null): string
    {
        return Carbon::today($tz)
            ->setTimeFromTimeString($policy->lateCutoffTimeString($year, $section))
            ->addMinutes(20)
            ->format('H:i:s');
    }

    /** Afternoon first IN → K–10 half-day (default noon boundary). */
    private function halfDayTime(string $tz): string
    {
        $start = (string) config('sf2.half_day_start_time', '12:00');

        return Carbon::today($tz)
            ->setTimeFromTimeString($start)
            ->addHour()
            ->format('H:i:s');
    }

    private function defaultSchoolYear(int $year, int $month): string
    {
        if ($month >= 6) {
            return $year.'-'.($year + 1);
        }

        return ($year - 1).'-'.$year;
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
