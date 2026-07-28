<?php

namespace App\Services;

use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AttendanceEodAutoOutService
{
    public function __construct(
        protected StudentSessionScheduleService $schedule,
        protected StudentScanService $scan,
        protected AttendanceSessionService $sessions,
    ) {}

    /**
     * Auto-OUT anyone still IN today at EOD (10:00 PM default), with missed-checkout SMS.
     */
    public function run(?Carbon $asOf = null): array
    {
        if (! Schema::hasTable('attendance_logs')) {
            return ['closed' => 0];
        }

        $tz = $this->schedule->timezone();
        $asOf ??= Carbon::now($tz);
        $asOf = $asOf->copy()->timezone($tz);
        $today = $asOf->toDateString();

        $openStudentIds = DB::table('attendance_logs as al')
            ->join(DB::raw('(
                SELECT student_id, MAX(id) AS max_id
                FROM attendance_logs
                GROUP BY student_id
            ) AS last'), 'last.max_id', '=', 'al.id')
            ->whereRaw("LOWER(TRIM(al.status)) = 'in'")
            ->whereRaw('DATE(al.scanned_at) = ?', [$today])
            ->pluck('al.student_id');

        $closed = 0;

        foreach ($openStudentIds as $sid) {
            $student = Student::query()->find($sid);
            if (! $student) {
                continue;
            }

            $outAt = $asOf->copy();

            if ($this->schedule->usesSessionModel($student)) {
                $schedule = $this->schedule->resolveSchedule($student);
                if ($schedule) {
                    $eod = $this->schedule->eodOutAt($schedule, $asOf);
                    $halfOut = null;
                    if ($this->schedule->isHalfDayToday($student, $asOf)) {
                        $halfOut = $this->schedule->outAllowedAt(
                            $schedule,
                            StudentSessionScheduleService::SESSION_HALF_DAY_OUT,
                            $asOf,
                            true
                        );
                    }
                    if ($halfOut) {
                        $outAt = $halfOut;
                    } elseif ($eod) {
                        $outAt = $eod;
                    }
                }
            }

            try {
                $this->scan->recordAutomaticScan(
                    $student,
                    'OUT',
                    $outAt,
                    'auto_eod_out',
                    StudentSessionScheduleService::SESSION_EOD_OUT,
                    sendSms: true,
                    smsEvent: 'missed_eod',
                );
                $closed++;
            } catch (\Throwable $e) {
                Log::warning('EOD auto-out failed', [
                    'student_id' => $student->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['closed' => $closed];
    }
}
