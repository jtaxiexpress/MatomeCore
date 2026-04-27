<?php

declare(strict_types=1);

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Article;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class ArticleRedirectController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, App $app, Article $article): RedirectResponse
    {
        if ($article->site->app_id !== $app->id) {
            abort(404);
        }

        $ip = $request->ip();
        $sessionId = $request->session()->getId();
        $cacheKey = "out_hit_{$article->id}_{$ip}_{$sessionId}";

        if (! Cache::has($cacheKey)) {
            // 連続クリックを1時間防止
            Cache::put($cacheKey, true, 3600);

            defer(function () use ($article) {
                // メモリ上でOUTトラフィックをカウントアップ
                $date = now()->toDateString();
                Redis::hIncrBy("traffic:out:article:{$date}", (string) $article->id, 1);
                Redis::hIncrBy("traffic:out:site:{$date}", (string) $article->site_id, 1);
            });
        }

        $targetUrl = $request->query('to_site') ? $article->site->url : $article->url;

        return redirect()->away($targetUrl);
    }
}
