<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\ProcessInTraffic;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class TrackInTraffic
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userAgent = $request->userAgent();
        if ($userAgent && preg_match('/bot|crawler|spider|slurp|facebookexternalhit|snippet|headless/i', $userAgent)) {
            return $next($request);
        }

        $referer = $request->headers->get('referer');

        if ($referer) {
            $parsedUrl = parse_url($referer);
            $host = $parsedUrl['host'] ?? null;

            if ($host) {
                // Hostに一致するSiteを探す（キャッシュを利用して高速化）
                $siteId = Cache::remember("site_host_{$host}", 3600, function () use ($host) {
                    $site = Site::where('url', 'like', "%{$host}%")->first();

                    return $site ? $site->id : null;
                });

                if ($siteId) {
                    $ip = $request->ip();

                    // 1日あたりの最大IN回数（100回）の制限
                    $dailyLimiterKey = "in_daily_limit_{$siteId}_{$ip}";
                    if (RateLimiter::tooManyAttempts($dailyLimiterKey, 100)) {
                        return $next($request);
                    }

                    $cacheKey = "in_hit_{$siteId}_{$ip}";

                    if (! Cache::has($cacheKey)) {
                        // 連続流入を1時間防止
                        Cache::put($cacheKey, true, 3600);

                        // 日次リミットをカウントアップ (24時間保持)
                        RateLimiter::hit($dailyLimiterKey, 86400);

                        // 非同期で流入を記録
                        ProcessInTraffic::dispatch($siteId, now()->toDateTimeString());
                    }
                }
            }
        }

        return $next($request);
    }
}
