<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\LogPlatformActivity::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('zendy') || $request->is('zendy/*')) {
                return route('zendy.login');
            }

            return route('login');
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedulerLog = storage_path('logs/scheduler.log');

        // Heartbeat: proves Hostinger cron is calling schedule:run (every minute).
        $schedule->command('attendance:scheduler-ping')
            ->everyMinute()
            ->timezone('Asia/Manila')
            ->appendOutputTo($schedulerLog);

        $schedule->command('attendance:close-stale-ins')
            ->dailyAt('00:05')
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);

        $schedule->command('attendance:autofill-lunch')
            ->dailyAt(config('attendance_sessions.lunch_autofill_at', '13:00'))
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);

        $schedule->command('attendance:auto-eod-out')
            ->dailyAt(config('attendance_sessions.eod_auto_out_at', '22:00'))
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);

        $schedule->command('attendance:check-consecutive-absences')
            ->dailyAt('16:30')
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
