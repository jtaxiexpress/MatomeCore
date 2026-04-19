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
        $geminiApiKey = (string) config('ai.providers.gemini.key', '');

        if ($geminiApiKey === '') {
            $geminiApiKey = (string) ($_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? '');
        }

        if ($geminiApiKey !== '') {
            config(['ai.providers.gemini.key' => $geminiApiKey]);
        }

        RateLimiter::for('public-feed', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });

        LogViewer::auth(function (Request $request): bool {
            return auth()->user()?->canAccessAdminScreen(AdminScreen::LogViewer) ?? false;
        });
    }
}
