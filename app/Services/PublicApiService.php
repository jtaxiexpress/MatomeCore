<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\App;
use App\Models\Article;
use App\Models\ArticleClick;
use App\Models\Category;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PublicApiService
{
    private const DEFAULT_PER_PAGE = 30;

    public function appConfig(App $app): array
    {
        return [
            'id' => $app->id,
            'name' => $app->name,
            'slug' => $app->api_slug,
            'icon_url' => $app->icon_url,
            'theme_color' => $app->theme_color,
        ];
    }

    public function categories(App $app): Collection
    {
        return $app->categories()
            ->select(['id', 'app_id', 'name', 'api_slug', 'sort_order'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function feed(App $app, ?string $categorySlug = null): LengthAwarePaginator
    {
        $query = $this->baseArticlesQuery($app);

        if (filled($categorySlug)) {
            $category = Category::query()
                ->select(['id'])
                ->whereBelongsTo($app)
                ->where('api_slug', $categorySlug)
                ->first();

            if ($category === null) {
                throw (new ModelNotFoundException)->setModel(Category::class, [$categorySlug]);
            }

            $query->where('category_id', $category->id);
        }

        return $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::DEFAULT_PER_PAGE)
            ->withQueryString();
    }

    public function search(App $app, string $keyword): LengthAwarePaginator
    {
        $keywords = $this->extractKeywords($keyword);

        $query = $this->baseArticlesQuery($app)
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($keywords->isEmpty()) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where(function (Builder $builder) use ($keywords): void {
                foreach ($keywords as $word) {
                    $escapedKeyword = addcslashes($word, '\\%_');
                    $builder->where('title', 'like', '%'.$escapedKeyword.'%');
                }
            });
        }

        return $query
            ->paginate(self::DEFAULT_PER_PAGE)
            ->withQueryString();
    }

    public function trackClick(Article $article): ArticleClick
    {
        return $article->clicks()->create([
            'clicked_at' => now(),
        ]);
    }

    public function trending(App $app, string $period, int $limit): Collection
    {
        $cacheKey = "api_trending_app_{$app->id}_period_{$period}_limit_{$limit}";

        return Cache::tags(['articles'])->flexible($cacheKey, [600, 1800], function () use ($app, $period, $limit, $cacheKey) {
            return Cache::lock($cacheKey.'_lock', 10)->block(10, function () use ($app, $period, $limit) {
                $periodStartAt = $this->periodStartAt($period);

                $clickStatisticsSubQuery = ArticleClick::query()
                    ->select([
                        'article_id',
                        DB::raw('COUNT(*) as click_count'),
                    ])
                    ->when($periodStartAt, fn (Builder $query) => $query->where('clicked_at', '>=', $periodStartAt))
                    ->groupBy('article_id');

                return Article::query()
                    ->select([
                        'articles.id',
                        'articles.app_id',
                        'articles.category_id',
                        'articles.site_id',
                        'articles.title',
                        'articles.original_title',
                        'articles.url',
                        'articles.thumbnail_url',
                        'articles.published_at',
                        'click_stats.click_count',
                    ])
                    ->joinSub($clickStatisticsSubQuery, 'click_stats', function (JoinClause $join): void {
                        $join->on('click_stats.article_id', '=', 'articles.id');
                    })
                    ->whereBelongsTo($app)
                    ->with(['category:id,default_image_path', 'site:id,name'])
                    ->orderByDesc('click_stats.click_count')
                    ->orderByDesc('articles.published_at')
                    ->orderByDesc('articles.id')
                    ->limit($limit)
                    ->get();
            });
        });
    }

    private function baseArticlesQuery(App $app): Builder
    {
        return Article::query()
            ->select([
                'id',
                'app_id',
                'category_id',
                'site_id',
                'title',
                'original_title',
                'url',
                'thumbnail_url',
                'published_at',
            ])
            ->whereBelongsTo($app)
            ->with(['category:id,default_image_path', 'site:id,name']);
    }

    /**
     * @return SupportCollection<int, string>
     */
    private function extractKeywords(string $keyword): SupportCollection
    {
        return collect(preg_split('/[\s　]+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($word): bool => is_string($word) && $word !== '')
            ->values();
    }

    private function periodStartAt(string $period): ?CarbonInterface
    {
        return match ($period) {
            'daily' => now()->subDay(),
            'weekly' => now()->subDays(7),
            'monthly' => now()->subDays(30),
            default => null,
        };
    }
}
