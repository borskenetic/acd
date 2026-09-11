<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Support\AdvisoryScope;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MissedOutsReportService
{
    /**
     * Students who scanned IN but never scanned OUT — closed by EOD auto-out.
     *
     * @return Builder<\App\Models\AttendanceLog>
     */
    public function query(Request $request): Builder
    {
        $tz = config('app.timezone', 'Asia/Manila');

        $query = AttendanceLog::query()
            ->with(['student:id,firstname,lastname,student_id,year,section,course'])
            ->where('source', 'auto_eod_out')
            ->whereRaw("LOWER(TRIM(status)) = 'out'");

        AdvisoryScope::applyToAttendanceLogs($query);

        $query
            ->when($request->filled('from'), function ($q) use ($request, $tz) {
                $start = Carbon::parse($request->from, $tz)->startOfDay()->format('Y-m-d H:i:s');
                $q->where('scanned_at', '>=', $start);
            })
            ->when($request->filled('to'), function ($q) use ($request, $tz) {
                $end = Carbon::parse($request->to, $tz)->endOfDay()->format('Y-m-d H:i:s');
                $q->where('scanned_at', '<=', $end);
            })
            ->when($request->filled('year'), function ($q) use ($request) {
                $q->whereHas('student', fn ($s) => $s->where('year', $request->year));
            })
            ->when($request->filled('homeroom_section'), function ($q) use ($request) {
                $q->whereHas('student', fn ($s) => $s->where('section', $request->homeroom_section));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->whereHas('student', function ($s) use ($search) {
                    $s->where('firstname', 'like', "%{$search}%")
                        ->orWhere('lastname', 'like', "%{$search}%")
                        ->orWhere('student_id', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('scanned_at')
            ->orderByDesc('id');

        return $query;
    }

    public function paginate(Request $request, int $perPage = 25): LengthAwarePaginator
    {
        $logs = $this->query($request)->paginate($perPage)->withQueryString();
        $this->attachLastIns(collect($logs->items()));

        return $logs;
    }

    /** @return Collection<int, AttendanceLog> */
    public function collect(Request $request, int $limit = 5000): Collection
    {
        $logs = $this->query($request)->limit($limit)->get();
        $this->attachLastIns($logs);

        return $logs;
    }

    /**
     * @return array{total: int, today: int, unique_students: int}
     */
    public function summary(Request $request): array
    {
        if (! Schema::hasTable('attendance_logs')) {
            return ['total' => 0, 'today' => 0, 'unique_students' => 0];
        }

        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->toDateString();
        $base = $this->query($request);

        return [
            'total' => (clone $base)->count(),
            'today' => (clone $base)->whereDate('scanned_at', $today)->count(),
            'unique_students' => (int) (clone $base)->toBase()->reorder()->distinct()->count('student_id'),
        ];
    }

    public function streamCsvResponse(Request $request): StreamedResponse
    {
        $logs = $this->collect($request);
        $filename = 'missed-outs-'.now()->format('Y-m-d').'.csv';
        $tz = config('app.timezone', 'Asia/Manila');

        return response()->streamDownload(function () use ($logs, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Student ID',
                'Last name',
                'First name',
                'Grade',
                'Section',
                'Course',
                'Last IN',
                'Auto OUT (missed)',
                'Date',
            ]);

            foreach ($logs as $log) {
                $student = $log->student;
                $outAt = $log->scanned_at?->timezone($tz);
                $inAt = $log->last_in_at instanceof Carbon
                    ? $log->last_in_at->timezone($tz)
                    : null;

                fputcsv($out, [
                    $student->student_id ?? '',
                    $student->lastname ?? '',
                    $student->firstname ?? '',
                    $student->year ?? '',
                    $student->section ?? '',
                    $student->course ?? '',
                    $inAt?->format('Y-m-d h:i A') ?? '',
                    $outAt?->format('Y-m-d h:i A') ?? '',
                    $outAt?->format('Y-m-d') ?? '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Attach the latest same-day IN scan time as last_in_at on each log.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     */
    protected function attachLastIns(Collection $logs): void
    {
        if ($logs->isEmpty()) {
            return;
        }

        $tz = config('app.timezone', 'Asia/Manila');
        $byDate = $logs->groupBy(fn (AttendanceLog $log) => $log->scanned_at?->timezone($tz)->toDateString());

        foreach ($byDate as $date => $dayLogs) {
            if (! $date) {
                continue;
            }

            $studentIds = $dayLogs->pluck('student_id')->unique()->values()->all();
            if ($studentIds === []) {
                continue;
            }

            $ins = AttendanceLog::query()
                ->whereIn('student_id', $studentIds)
                ->whereDate('scanned_at', $date)
                ->whereRaw("LOWER(TRIM(status)) = 'in'")
                ->orderByDesc('scanned_at')
                ->orderByDesc('id')
                ->get(['student_id', 'scanned_at']);

            $latestInByStudent = [];
            foreach ($ins as $in) {
                if (! isset($latestInByStudent[$in->student_id])) {
                    $latestInByStudent[$in->student_id] = $in->scanned_at;
                }
            }

            foreach ($dayLogs as $log) {
                $log->setAttribute('last_in_at', $latestInByStudent[$log->student_id] ?? null);
            }
        }
    }
}
