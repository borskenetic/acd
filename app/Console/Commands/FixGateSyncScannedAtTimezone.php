<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixGateSyncScannedAtTimezone extends Command
{
    protected $signature = 'gate-sync:fix-scanned-at-timezone {--dry-run : Preview how many rows would be updated}';

    protected $description = 'Add 8 hours to scanned_at on gate_sync attendance logs stored in UTC by mistake';

    public function handle(): int
    {
        $query = DB::table('attendance_logs')
            ->where('source', 'gate_sync')
            ->whereNotNull('scanned_at');

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Would update {$count} attendance log(s).");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('No gate_sync attendance logs to update.');

            return self::SUCCESS;
        }

        $updated = $query->update([
            'scanned_at' => DB::raw('DATE_ADD(scanned_at, INTERVAL 8 HOUR)'),
        ]);

        $this->info("Updated {$updated} attendance log(s).");

        return self::SUCCESS;
    }
}
