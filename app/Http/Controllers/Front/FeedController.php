<?php

declare(strict_types=1);

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class FeedController extends Controller
{
    public function index(): Response
    {
        $activeAppIds = Cache::remember('active_app_ids', now()->addMinutes(60), function () {
            return App::where('is_active', true)->pluck('id');
        });

        $cacheKey = 'rss_feed_index_articles';
        $articles = Cache::tags(['articles'])->get($cacheKey);

        if ($articles === null) {
            $articles = Cache::lock("{$cacheKey}_lock", 10)->block(5, function () use ($activeAppIds, $cacheKey) {
                return Cache::tags(['articles'])->remember($cacheKey, now()->addMinutes(5), function () use ($activeAppIds) {
                    return Article::query()
                        ->whereIn('app_id', $activeAppIds)
                        ->with(['app:id,api_slug', 'site:id,name'])
                        ->trafficFiltered()
                        ->orderByDesc('published_at')
                        ->orderByDesc('id')
                        ->limit(50)
                        ->get();
                });
            });
        }

        $content = view('rss', [
            'title' => 'ゆにこーんアンテナ - 横断アンテナ',
            'description' => '最新のまとめ記事を横断して配信します。',
            'link' => url('/'),
            'articles' => $articles,
        ])->render();

        return response($content, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function app(App $app): Response
    {
        abort_unless($app->is_active, 404);

        $cacheKey = "rss_feed_app_{$app->id}_articles";
        $articles = Cache::tags(['articles'])->get($cacheKey);

        if ($articles === null) {
            $articles = Cache::lock("{$cacheKey}_lock", 10)->block(5, function () use ($app, $cacheKey) {
                return Cache::tags(['articles'])->remember($cacheKey, now()->addMinutes(5), function () use ($app) {
                    return Article::query()
                        ->whereBelongsTo($app)
                        ->with(['app:id,api_slug', 'site:id,name'])
                        ->trafficFiltered()
                        ->orderByDesc('published_at')
                        ->orderByDesc('id')
                        ->limit(50)
                        ->get();
                });
            });
        }

        $content = view('rss', [
            'title' => $app->name,
            'description' => $app->name.'の最新まとめ記事を配信します。',
            'link' => route('front.home', $app),
            'articles' => $articles,
        ])->render();

        return response($content, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function category(App $app, Category $category): Response
    {
        abort_unless($app->is_active, 404);
        abort_unless($category->app_id === $app->id, 404);

        $cacheKey = "rss_feed_category_{$category->id}_articles";
        $articles = Cache::tags(['articles'])->get($cacheKey);

        if ($articles === null) {
            $articles = Cache::lock("{$cacheKey}_lock", 10)->block(5, function () use ($app, $category, $cacheKey) {
                return Cache::tags(['articles'])->remember($cacheKey, now()->addMinutes(5), function () use ($app, $category) {
                    return Article::query()
                        ->whereBelongsTo($app)
                        ->whereBelongsTo($category)
                        ->with(['app:id,api_slug', 'site:id,name'])
                        ->trafficFiltered()
                        ->orderByDesc('published_at')
                        ->orderByDesc('id')
                        ->limit(50)
                        ->get();
                });
            });
        }

        $content = view('rss', [
            'title' => $category->name.' - '.$app->name,
            'description' => $category->name.'カテゴリの最新まとめ記事を配信します。',
            'link' => route('front.home', ['app' => $app, 'cat' => $category->api_slug]),
            'articles' => $articles,
        ])->render();

        return response($content, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
