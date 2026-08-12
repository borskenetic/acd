<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\Visitor;
use App\Models\VisitorLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceSessionService
{
    public const TZ = 'Asia/Manila';

    /** Source tag for OUTs invented by close-stale (rollback can target these). */
    public const SOURCE_STALE_OUT = 'auto_stale_out';

    public function isInStatus(?string $status): bool
    {
        return $status !== null && strtolower(trim((string) $status)) === 'in';
    }

    public function isOutStatus(?string $status): bool
    {
        return $status !== null && strtolower(trim((string) $status)) === 'out';
    }

    public function closeStaleOpenInForStudent(Student $student): bool
    {
        $last = AttendanceLog::query()
            ->where('student_id', $student->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        if (! $last || ! $this->isInStatus($last->status)) {
            return false;
        }

        $inDayStart = $last->scanned_at->copy()->timezone(self::TZ)->startOfDay();
        $todayStart = Carbon::now(self::TZ)->startOfDay();

        if ($inDayStart->greaterThanOrEqualTo($todayStart)) {
            return false;
        }

        $outAt = $last->scanned_at->copy()->endOfDay();

        AttendanceLog::create([
            'student_id' => $student->id,
            'status' => 'OUT',
            'scanned_at' => $outAt,
            'source' => self::SOURCE_STALE_OUT,
        ]);

        return true;
    }

    public function closeStaleOpenInForVisitor(Visitor $visitor): bool
    {
        $last = VisitorLog::query()
            ->where('visitor_id', $visitor->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        if (! $last || ! $this->isInStatus($last->status)) {
            return false;
        }

        $inDayStart = $last->scanned_at->copy()->timezone(self::TZ)->startOfDay();
        $todayStart = Carbon::now(self::TZ)->startOfDay();

        if ($inDayStart->greaterThanOrEqualTo($todayStart)) {
            return false;
        }

        $outAt = $last->scanned_at->copy()->endOfDay();

        VisitorLog::create([
            'visitor_id' => $visitor->id,
            'status' => 'OUT',
            'scanned_at' => $outAt,
        ]);

        return true;
    }

    public function closeAllStaleOpenIns(): int
    {
        if (! Schema::hasTable('attendance_logs')) {
            return 0;
        }

        $closed = 0;

        $today = Carbon::now(self::TZ)->toDateString();

        $staleStudentIds = DB::table('attendance_logs as al')
            ->whereRaw("LOWER(TRIM(al.status)) = 'in'")
            ->whereRaw('DATE(al.scanned_at) < ?', [$today])
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

        foreach ($staleStudentIds as $sid) {
            $student = Student::query()->find($sid);
            if (! $student) {
                continue;
            }
            if ($this->closeStaleOpenInForStudent($student)) {
                $closed++;
            }
        }

        if (Schema::hasTable('visitor_logs')) {
            $staleVisitorIds = DB::table('visitor_logs as vl')
                ->whereRaw("LOWER(TRIM(vl.status)) = 'in'")
                ->whereRaw('DATE(vl.scanned_at) < ?', [$today])
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('visitor_logs as newer')
                        ->whereColumn('newer.visitor_id', 'vl.visitor_id')
                        ->where(function ($inner) {
                            $inner->whereColumn('newer.scanned_at', '>', 'vl.scanned_at')
                                ->orWhere(function ($tie) {
                                    $tie->whereColumn('newer.scanned_at', '=', 'vl.scanned_at')
                                        ->whereColumn('newer.id', '>', 'vl.id');
                                });
                        });
                })
                ->pluck('vl.visitor_id');

            foreach ($staleVisitorIds as $vid) {
                $visitor = Visitor::query()->find($vid);
                if (! $visitor) {
                    continue;
                }
                if ($this->closeStaleOpenInForVisitor($visitor)) {
                    $closed++;
                }
            }
        }

        return $closed;
    }
}
