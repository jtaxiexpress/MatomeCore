<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackInTraffic
{
    private const MAX_DAILY_LIMIT = 100;

    private const CACHE_DURATION_SECONDS = 3600;

    private const DAILY_SECONDS = 86400;

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

        $siteId = $this->resolveSiteId($request);

        if ($siteId) {
            $ip = $request->ip();

            // 1日あたりの最大IN回数（100回）の制限
            $dailyLimiterKey = "in_daily_limit_{$siteId}_{$ip}";
            if (RateLimiter::tooManyAttempts($dailyLimiterKey, self::MAX_DAILY_LIMIT)) {
                return $next($request);
            }

            $cacheKey = "in_hit_{$siteId}_{$ip}";

            if (! Cache::has($cacheKey)) {
                // 連続流入を1時間防止
                Cache::put($cacheKey, true, self::CACHE_DURATION_SECONDS);

                // 日次リミットをカウントアップ (24時間保持)
                RateLimiter::hit($dailyLimiterKey, self::DAILY_SECONDS);

                // メモリ上でINトラフィックをカウントアップ
                Redis::hIncrBy('traffic:in:'.now()->toDateString(), (string) $siteId, 1);
            }
        }

        return $next($request);
    }

    private function resolveSiteId(Request $request): ?int
    {
        $siteId = $this->resolveSiteIdFromQuery($request);

        if ($siteId !== null) {
            return $siteId;
        }

        return $this->resolveSiteIdFromReferer($request);
    }

    private function resolveSiteIdFromQuery(Request $request): ?int
    {
        $siteId = $this->resolveSiteIdFromQueryId($request);

        if ($siteId !== null) {
            return $siteId;
        }

        return $this->resolveSiteIdFromQuerySlug($request);
    }

    private function resolveSiteIdFromQueryId(Request $request): ?int
    {
        $rawSiteId = $request->query('in_site_id');

        if ($rawSiteId === null || $rawSiteId === '') {
            return null;
        }

        $siteId = filter_var($rawSiteId, FILTER_VALIDATE_INT);

        if ($siteId === false || $siteId <= 0) {
            return null;
        }

        return Site::query()->whereKey($siteId)->value('id');
    }

    private function resolveSiteIdFromQuerySlug(Request $request): ?int
    {
        $rawSlug = trim((string) $request->query('in_site_slug', ''));

        if ($rawSlug === '') {
            return null;
        }

        $slug = Str::lower($rawSlug);

        return Site::query()
            ->where('api_slug', $slug)
            ->value('id');
    }

    private function resolveSiteIdFromReferer(Request $request): ?int
    {
        $referer = $request->headers->get('referer');

        if ($referer === null || $referer === '') {
            return null;
        }

        $parsedUrl = parse_url($referer);
        $host = $parsedUrl['host'] ?? null;

        if ($host === null || $host === '') {
            return null;
        }

        $normalizedHost = Str::lower($host);

        return Cache::remember("site_host_{$normalizedHost}", self::CACHE_DURATION_SECONDS, function () use ($normalizedHost): ?int {
            return Site::query()
                ->where('url', 'like', "%{$normalizedHost}%")
                ->value('id');
        });
    }
}
