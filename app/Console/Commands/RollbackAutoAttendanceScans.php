<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Undo automatic attendance rows created by lunch/EOD/stale/friday jobs.
 *
 * Safe target: source in auto_eod_out, auto_lunch_out, auto_afternoon_in,
 * friday_auto, auto_stale_out.
 * (Manual scans use web/gate_sync and are left alone.)
 */
class RollbackAutoAttendanceScans extends Command
{
    protected $signature = 'attendance:rollback-auto-scans
        {--since= : Only rows created at/after this datetime (default: today 00:00 Asia/Manila)}
        {--hours= : Alternative: only rows created within the last N hours}
        {--dry-run : List rows only; do not delete}
        {--force : Delete without interactive confirmation}';

    protected $description = 'Delete automatic attendance log rows (auto_eod_out / auto_lunch_out / auto_afternoon_in / friday_auto / auto_stale_out)';

    public function handle(): int
    {
        $tz = (string) config('attendance_sessions.timezone', 'Asia/Manila');
        $sources = ['auto_eod_out', 'auto_lunch_out', 'auto_afternoon_in', 'friday_auto', 'auto_stale_out'];

        if ($this->option('hours') !== null) {
            $since = Carbon::now($tz)->subHours(max(1, (int) $this->option('hours')));
        } elseif ($this->option('since')) {
            $since = Carbon::parse((string) $this->option('since'), $tz);
        } else {
            $since = Carbon::today($tz)->startOfDay();
        }

        $query = AttendanceLog::query()
            ->whereIn('source', $sources)
            ->where('created_at', '>=', $since->copy()->timezone(config('app.timezone', $tz)))
            ->orderByDesc('id');

        $count = (clone $query)->count();
        $bySource = (clone $query)
            ->selectRaw('source, COUNT(*) as c')
            ->groupBy('source')
            ->pluck('c', 'source')
            ->all();

        $this->info("Timezone context: {$tz}");
        $this->info('Since: '.$since->toDateTimeString());
        $this->info("Matching automatic rows: {$count}");
        foreach ($bySource as $source => $c) {
            $this->line("  - {$source}: {$c}");
        }

        if ($count === 0) {
            $this->warn('Nothing to roll back. Remaining OUTs may be real scans (web / web_kiosk / gate_sync).');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['id', 'student_id', 'status', 'source', 'scanned_at', 'created_at'],
                $query->limit(50)->get(['id', 'student_id', 'status', 'source', 'scanned_at', 'created_at'])
                    ->map(fn (AttendanceLog $log) => [
                        $log->id,
                        $log->student_id,
                        $log->status,
                        $log->source,
                        optional($log->scanned_at)->toDateTimeString(),
                        optional($log->created_at)->toDateTimeString(),
                    ])
                    ->all()
            );
            if ($count > 50) {
                $this->line('… (showing first 50 of '.$count.')');
            }
            $this->comment('Dry run only — re-run without --dry-run to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$count} automatic attendance row(s)?", true)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} row(s). Students who only had those auto OUTs should show open/IN again based on prior scans.");

        return self::SUCCESS;
    }
}
