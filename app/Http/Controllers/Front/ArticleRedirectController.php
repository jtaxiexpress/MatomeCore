<?php

declare(strict_types=1);

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOutTraffic;
use App\Models\App;
use App\Models\Article;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

            // 非同期でクリックを記録
            ProcessOutTraffic::dispatch($article->id, now()->toDateTimeString());
        }

        $targetUrl = $request->query('to_site') ? $article->site->url : $article->url;

        return redirect()->away($targetUrl);
    }
}
