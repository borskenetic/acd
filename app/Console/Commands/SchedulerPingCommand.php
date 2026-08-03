<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Heartbeat for the Laravel scheduler. When Hostinger cron calls
 * schedule:run every minute, this writes one line so you can confirm
 * it is alive without waiting for 22:00 auto-out.
 */
class SchedulerPingCommand extends Command
{
    protected $signature = 'attendance:scheduler-ping';

    protected $description = 'Write a one-line heartbeat to storage/logs/scheduler.log (cron health check)';

    public function handle(): int
    {
        $tz = (string) config('attendance_sessions.timezone', 'Asia/Manila');
        $now = Carbon::now($tz)->format('Y-m-d H:i:s T');
        $line = "[{$now}] scheduler-ping OK\n";

        $path = storage_path('logs/scheduler.log');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, $line);

        $this->info(trim($line));

        return self::SUCCESS;
    }
}
