<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('attendance:close-stale-ins', function (\App\Services\AttendanceSessionService $sessions) {
    $n = $sessions->closeAllStaleOpenIns();
    $this->info("Inserted {$n} automatic end-of-day OUT row(s).");

    return 0;
})->purpose('Auto OUT for patrons still IN from a prior calendar day (Asia/Manila).');

Artisan::command('attendance:autofill-lunch', function (\App\Services\AttendanceLunchAutofillService $service) {
    $result = $service->run();
    $this->info("Lunch autofill: {$result['filled']} student(s) filled, {$result['skipped']} skipped.");

    return 0;
})->purpose('Auto lunch OUT + afternoon IN for students who stayed in during lunch.');

Artisan::command('attendance:auto-eod-out', function (\App\Services\AttendanceEodAutoOutService $service) {
    $result = $service->run();
    $this->info("EOD auto-out: closed {$result['closed']} open IN session(s).");

    return 0;
})->purpose('Auto OUT at 10:00 PM for students still IN, with guardian SMS.');
