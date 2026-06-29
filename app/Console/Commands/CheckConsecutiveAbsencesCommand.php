<?php

namespace App\Console\Commands;

use App\Services\AttendanceSmsService;
use Illuminate\Console\Command;

class CheckConsecutiveAbsencesCommand extends Command
{
    protected $signature = 'attendance:check-consecutive-absences';

    protected $description = 'Notify parents when a student reaches consecutive absent days (SF2 grades).';

    public function handle(AttendanceSmsService $sms): int
    {
        $sent = $sms->checkConsecutiveAbsentAlerts();

        $this->info("Sent {$sent} consecutive-absence alert(s).");

        return self::SUCCESS;
    }
}
