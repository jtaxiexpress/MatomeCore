<?php

use App\Http\Middleware\TrackInTraffic;
use App\Support\Slack\ExceptionAlertReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['api', 'throttle:api'])
                ->prefix('api')
                ->name('api.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->web(append: [
            TrackInTraffic::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();

        $exceptions->report(function (Throwable $exception): void {
            try {
                $isTargetEnv = app()->bound('env')
                    ? app()->environment(['production', 'staging'])
                    : in_array(env('APP_ENV'), ['production', 'staging'], true);

                if (! $isTargetEnv) {
                    return;
                }

                app(ExceptionAlertReporter::class)->report($exception);
            } catch (Throwable $reportError) {
                // Ignore reporting errors to allow the original exception to bubble up
            }
        });
    })->create();
