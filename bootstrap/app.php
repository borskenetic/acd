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
        $schedule->command('attendance:close-stale-ins')
            ->dailyAt('00:05')
            ->timezone('Asia/Manila');
        $schedule->command('attendance:check-consecutive-absences')
            ->dailyAt('16:30')
            ->timezone('Asia/Manila');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
