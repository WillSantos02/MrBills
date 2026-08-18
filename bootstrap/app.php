<?php

use App\Console\Commands\AlertFailedJobs;
use App\Console\Commands\SendBillDueSoonNotifications;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(SendBillDueSoonNotifications::class)->dailyAt('08:00');
        $schedule->command(AlertFailedJobs::class)->dailyAt('09:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Traefik terminates TLS and forwards plain HTTP inside the sail Docker
        // network; trust its X-Forwarded-* headers so Laravel knows requests are
        // secure (correct asset/URL scheme, secure cookies).
        $middleware->trustProxies(at: '*', headers: SymfonyRequest::HEADER_X_FORWARDED_FOR
            | SymfonyRequest::HEADER_X_FORWARDED_HOST
            | SymfonyRequest::HEADER_X_FORWARDED_PORT
            | SymfonyRequest::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
