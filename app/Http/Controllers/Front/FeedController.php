<?php

declare(strict_types=1);

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Article;
use Illuminate\Http\Response;

class FeedController extends Controller
{
    public function index(): Response
    {
        $activeAppIds = App::where('is_active', true)->pluck('id');

        $articles = Article::query()
            ->whereIn('app_id', $activeAppIds)
            ->with(['app:id,api_slug', 'site:id,name'])
            ->trafficFiltered()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $content = view('rss', [
            'title' => 'MatomeCore - 横断アンテナ',
            'description' => '最新のまとめ記事を横断して配信します。',
            'link' => url('/'),
            'articles' => $articles,
        ]);

        return response($content, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function app(App $app): Response
    {
        abort_unless($app->is_active, 404);

        $articles = Article::query()
            ->whereBelongsTo($app)
            ->with(['app:id,api_slug', 'site:id,name'])
            ->trafficFiltered()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $content = view('rss', [
            'title' => $app->name,
            'description' => $app->name.'の最新まとめ記事を配信します。',
            'link' => route('front.home', $app),
            'articles' => $articles,
        ]);

        return response($content, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
