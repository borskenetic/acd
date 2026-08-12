<?php

namespace App\Services;

use App\Models\AttendanceLog;
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

        // Latest by scanned_at (then id), not MAX(id) — out-of-order gate sync can invert ids.
        $openStudentIds = DB::table('attendance_logs as al')
            ->whereRaw('DATE(al.scanned_at) = ?', [$today])
            ->whereRaw("LOWER(TRIM(al.status)) = 'in'")
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('attendance_logs as newer')
                    ->whereColumn('newer.student_id', 'al.student_id')
                    ->where(function ($inner) {
                        $inner->whereColumn('newer.scanned_at', '>', 'al.scanned_at')
                            ->orWhere(function ($tie) {
                                $tie->whereColumn('newer.scanned_at', '=', 'al.scanned_at')
                                    ->whereColumn('newer.id', '>', 'al.id');
                            });
                    });
            })
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

            $lastIn = AttendanceLog::query()
                ->where('student_id', $student->id)
                ->whereDate('scanned_at', $today)
                ->whereRaw("LOWER(TRIM(status)) = 'in'")
                ->orderByDesc('scanned_at')
                ->orderByDesc('id')
                ->first();

            if ($lastIn && $outAt->lte($lastIn->scanned_at)) {
                $outAt = $lastIn->scanned_at->copy()->addSecond();
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
