<?php

namespace App\Providers;

use App\Support\AdminScreen;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-feed', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });

        LogViewer::auth(function (Request $request): bool {
            return auth()->user()?->canAccessAdminScreen(AdminScreen::LogViewer) ?? false;
        });
    }
}
