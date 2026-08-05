<?php

namespace App\Console\Commands;

use App\Services\FridayAutoAttendanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FridayAutoAttendanceCommand extends Command
{
    protected $signature = 'attendance:friday-auto-present
        {--date= : Y-m-d to mark (default: today Asia/Manila)}
        {--force : Run even if the date is not Friday}';

    protected $description = 'Mark all students present on Friday (online classes) with IN/OUT at policy times';

    public function handle(FridayAutoAttendanceService $service): int
    {
        $tz = (string) config('sf2.timezone', 'Asia/Manila');
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'), $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();

        if (! $date->isFriday() && ! $this->option('force')) {
            $this->warn($date->toDateString().' is not Friday. Pass --force to run anyway.');

            return self::SUCCESS;
        }

        $result = $service->markForDate($date, (bool) $this->option('force'));

        $this->info(sprintf(
            'Friday auto-present for %s — INs created: %d, OUTs created: %d, already complete: %d',
            $result['date'],
            $result['ins'],
            $result['outs'],
            $result['skipped']
        ));

        return self::SUCCESS;
    }
}
