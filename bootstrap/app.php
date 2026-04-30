<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminAccess::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('deal-chat:prune')->daily();
        $schedule->command('deal-disputes:prune')->daily();
        $schedule->command('receipt-checksums:prune')->daily();
        $schedule->command('transactions:prune')->daily();
        $schedule->command('email-attachments-tmp:prune')->daily();
        $schedule->command('email-attachments-dates:prune')->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
