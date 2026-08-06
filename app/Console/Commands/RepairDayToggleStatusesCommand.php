<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Services\StudentScanService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fix IN/OUT sequences that drifted (e.g. double IN when a stale kiosk synced).
 * Walks each student's logs for a day and restores alternating IN → OUT → IN…
 */
class RepairDayToggleStatusesCommand extends Command
{
    protected $signature = 'attendance:repair-day-toggle-statuses
        {--date= : Calendar day to repair (Y-m-d), default: today Asia/Manila}
        {--dry-run : List planned changes only; do not update}
        {--force : Apply without interactive confirmation}';

    protected $description = 'Repair inconsistent IN/OUT sequences for student scans on a given day';

    public function handle(StudentScanService $scanService): int
    {
        $tz = (string) config('attendance_sessions.timezone', 'Asia/Manila');
        $dateOpt = $this->option('date');
        $day = $dateOpt
            ? Carbon::parse((string) $dateOpt, $tz)->startOfDay()
            : Carbon::today($tz)->startOfDay();

        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();

        $studentIds = AttendanceLog::query()
            ->whereBetween('scanned_at', [$dayStart, $dayEnd])
            ->distinct()
            ->orderBy('student_id')
            ->pluck('student_id');

        $this->info("Timezone: {$tz}");
        $this->info('Day: '.$day->toDateString());
        $this->info('Students with scans that day: '.$studentIds->count());

        if ($studentIds->isEmpty()) {
            $this->warn('No attendance logs for that day.');

            return self::SUCCESS;
        }

        $allChanges = [];
        foreach ($studentIds as $studentId) {
            $student = Student::query()->find($studentId);
            if (! $student) {
                continue;
            }
            $changes = $scanService->planStudentDayToggleRepairs($student, $day);
            foreach ($changes as $change) {
                $allChanges[] = $change + [
                    'name' => trim($student->lastname.', '.$student->firstname),
                ];
            }
        }

        $this->info('Rows that need a status fix: '.count($allChanges));

        if ($allChanges === []) {
            $this->comment('Nothing inconsistent — statuses already alternate correctly for that day.');

            return self::SUCCESS;
        }

        $this->table(
            ['log_id', 'student', 'from', 'to', 'scanned_at', 'kiosk', 'source'],
            collect($allChanges)->map(fn (array $c) => [
                $c['id'],
                ($c['name'] ?? '').' (#'.$c['student_id'].')',
                $c['from'],
                $c['to'],
                $c['scanned_at'] ?? '',
                $c['kiosk_name'] ?? '',
                $c['source'] ?? '',
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run only — re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply these status fixes?', true)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $updated = 0;
        $touchedStudents = [];
        foreach ($studentIds as $studentId) {
            $student = Student::query()->find($studentId);
            if (! $student) {
                continue;
            }
            $n = $scanService->reconcileStudentDayToggleStatuses($student, $day);
            if ($n > 0) {
                $updated += $n;
                $touchedStudents[] = $studentId;
            }
        }

        $this->info("Updated {$updated} row(s) across ".count($touchedStudents).' student(s). SMS not re-sent.');

        return self::SUCCESS;
    }
}
