<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FixGateSyncScannedAtTimezone extends Command
{
    protected $signature = 'gate-sync:fix-scanned-at-timezone
        {--apply : Actually update rows (default is dry-run)}
        {--force-rerun : Allow running again after a previous apply (dangerous)}
        {--hours=8 : Hours to add to scanned_at}';

    protected $description = 'One-shot: add N hours to scanned_at on gate_sync logs stored in UTC by mistake (idempotent guard)';

    private const CACHE_KEY = 'gate_sync_tz_fix_applied';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $apply = (bool) $this->option('apply');

        if (Cache::has(self::CACHE_KEY) && ! $this->option('force-rerun')) {
            $meta = Cache::get(self::CACHE_KEY);
            $this->error('This fix was already applied'
                .(is_array($meta) ? ' at '.($meta['at'] ?? '?')." (+{$meta['hours']}h, {$meta['updated']} rows)" : '')
                .'. Re-running would shift times again. Use --force-rerun only if you are sure.');

            return self::FAILURE;
        }

        $query = DB::table('attendance_logs')
            ->where('source', 'gate_sync')
            ->whereNotNull('scanned_at');

        $count = (clone $query)->count();

        if (! $apply) {
            $this->info("Dry run: would add {$hours} hour(s) to {$count} gate_sync attendance log(s).");
            $this->comment('Re-run with --apply to perform the update (once).');

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('No gate_sync attendance logs to update.');

            return self::SUCCESS;
        }

        if (! $this->option('force-rerun')
            && ! $this->confirm("Add {$hours} hour(s) to {$count} gate_sync row(s)? This should only run once.", false)
        ) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $updated = $query->update([
            'scanned_at' => DB::raw("DATE_ADD(scanned_at, INTERVAL {$hours} HOUR)"),
        ]);

        Cache::forever(self::CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'hours' => $hours,
            'updated' => $updated,
        ]);

        $this->info("Updated {$updated} attendance log(s). Guard stored so this will not re-run without --force-rerun.");

        return self::SUCCESS;
    }
}
