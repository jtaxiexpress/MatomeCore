<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexAppFeedRequest;
use App\Http\Requests\Api\V1\IndexCategoryArticlesRequest;
use App\Http\Requests\Api\V1\IndexTrendingArticlesRequest;
use App\Http\Requests\Api\V1\SearchAppArticlesRequest;
use App\Http\Resources\Api\V1\AppConfigResource;
use App\Http\Resources\Api\V1\AppResource;
use App\Http\Resources\Api\V1\ArticleResource;
use App\Http\Resources\Api\V1\CategoryTabResource;
use App\Http\Resources\Api\V1\PublicArticleListResource;
use App\Models\App;
use App\Models\Article;
use App\Models\Category;
use App\Services\PublicApiService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PublicFeedController extends Controller
{
    public function __construct(
        private readonly PublicApiService $publicApiService,
    ) {}

    public function apps(): AnonymousResourceCollection|JsonResponse
    {
        try {
            $apps = App::query()
                ->select(['id', 'name', 'api_slug', 'icon_path', 'theme_color', 'is_active'])
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            return AppResource::collection($apps);
        } catch (Throwable $e) {
            return $this->internalServerError($e, 'アプリ一覧の取得に失敗しました。');
        }
    }

    public function config(App $app): JsonResponse
    {
        try {
            return response()->json([
                'data' => (new AppConfigResource($app))->resolve(),
            ]);
        } catch (Throwable $e) {
            return $this->internalServerError($e, 'アプリ設定の取得に失敗しました。');
        }
    }

    public function categories(App $app): AnonymousResourceCollection|JsonResponse
    {
        try {
            $categories = $this->publicApiService->categories($app);

            return CategoryTabResource::collection($categories);
        } catch (Throwable $e) {
            return $this->internalServerError($e, 'カテゴリ一覧の取得に失敗しました。');
        }
    }

    public function feed(IndexAppFeedRequest $request, App $app): AnonymousResourceCollection|JsonResponse
    {
        try {
            $categorySlug = $request->validated('category_slug');
            $articles = $this->publicApiService->feed(
                app: $app,
                categorySlug: is_string($categorySlug) ? $categorySlug : null,
            );

            return PublicArticleListResource::collection($articles);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('指定されたカテゴリが見つかりません。');
        } catch (Throwable $e) {
            return $this->internalServerError($e, '記事フィードの取得に失敗しました。');
        }
    }

    public function search(SearchAppArticlesRequest $request, App $app): AnonymousResourceCollection|JsonResponse
    {
        try {
            $keyword = (string) $request->validated('keyword');
            $articles = $this->publicApiService->search($app, $keyword);

            return PublicArticleListResource::collection($articles);
        } catch (Throwable $e) {
            return $this->internalServerError($e, '記事検索に失敗しました。');
        }
    }

    public function trending(IndexTrendingArticlesRequest $request, App $app): JsonResponse
    {
        try {
            $period = (string) $request->validated('period', 'daily');
            $limit = (int) $request->validated('limit', 20);

            $articles = $this->publicApiService->trending($app, $period, $limit);

            return response()->json([
                'data' => PublicArticleListResource::collection($articles)->resolve(),
            ]);
        } catch (Throwable $e) {
            return $this->internalServerError($e, '人気記事の取得に失敗しました。');
        }
    }

    public function click(Article $article): JsonResponse
    {
        try {
            $click = $this->publicApiService->trackClick($article);

            return response()->json([
                'message' => '記事クリックを記録しました。',
                'data' => [
                    'article_id' => $article->id,
                    'clicked_at' => $click->clicked_at?->toISOString(),
                ],
            ], 201);
        } catch (Throwable $e) {
            return $this->internalServerError($e, '記事クリックの記録に失敗しました。');
        }
    }

    public function articles(
        IndexCategoryArticlesRequest $request,
        App $app,
        Category $category,
    ): AnonymousResourceCollection|JsonResponse {
        try {
            abort_if($category->app_id !== $app->id, 404);

            $perPage = (int) $request->validated('per_page', 30);
            $page = (int) $request->input('page', 1);

            $cacheKey = "articles.app_{$app->id}.category_{$category->id}.page_{$page}.perPage_{$perPage}";

            $articles = Cache::tags(['articles'])->flexible($cacheKey, [600, 1200], function () use ($category, $perPage, $cacheKey) {
                return Cache::lock($cacheKey.'_lock', 10)->block(10, function () use ($category, $perPage) {
                    return $category->articles()
                        ->select([
                            'id',
                            'app_id',
                            'category_id',
                            'site_id',
                            'title',
                            'summary',
                            'lead_text',
                            'url',
                            'thumbnail_url',
                            'fetch_source',
                            'published_at',
                            'created_at',
                            'updated_at',
                        ])
                        ->with(['category:id,default_image_path'])
                        ->orderByDesc('published_at')
                        ->orderByDesc('id')
                        ->paginate($perPage)
                        ->withQueryString();
                });
            });

            return ArticleResource::collection($articles);
        } catch (Throwable $e) {
            return $this->internalServerError($e, 'カテゴリ記事の取得に失敗しました。');
        }
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 404);
    }

    private function internalServerError(Throwable $e, string $message): JsonResponse
    {
        report($e);

        return response()->json([
            'message' => $message,
        ], 500);
    }
}
