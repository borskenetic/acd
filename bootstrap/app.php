<?php

use App\Support\SessionExpiry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

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
            return SessionExpiry::guestRedirect($request);
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

        $schedule->command('attendance:check-consecutive-absences')
            ->dailyAt('16:30')
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);

        // Friday online SHS: end-of-day fill for anyone still missing IN/OUT.
        // Runs before EOD so timestamps stay at scheduled login/logout (on time),
        // while real scans during the day are left alone.
        $eodAt = config('attendance_sessions.eod_auto_out_at', '22:00');
        $schedule->command('attendance:friday-auto-present')
            ->weeklyOn(5, $eodAt) // Friday 22:00 Asia/Manila (default)
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);

        $schedule->command('attendance:auto-eod-out')
            ->dailyAt($eodAt)
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);

        // Drain pending gate SMS when the modem queue was full (503).
        // --failed-503 also picks up today's earlier hard failures from before retries existed.
        $schedule->command('sms:retry-pending --failed-503')
            ->everyMinute()
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Sign in again.',
                ], 419);
            }

            return response()->view('auth.session-expired', [], 419);
        });
    })->create();
