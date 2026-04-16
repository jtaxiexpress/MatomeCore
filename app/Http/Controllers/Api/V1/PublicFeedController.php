<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexCategoryArticlesRequest;
use App\Http\Resources\Api\V1\AppResource;
use App\Http\Resources\Api\V1\ArticleResource;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\App;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicFeedController extends Controller
{
    public function apps(): AnonymousResourceCollection
    {
        $apps = App::query()
            ->select(['id', 'name', 'api_slug', 'theme_color', 'is_active'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return AppResource::collection($apps);
    }

    public function categories(App $app): AnonymousResourceCollection
    {
        $categories = $app->categories()
            ->select(['id', 'app_id', 'parent_id', 'name', 'api_slug', 'sort_order', 'default_image_path'])
            ->whereNull('parent_id')
            ->with([
                'children' => fn ($query) => $query
                    ->select(['id', 'app_id', 'parent_id', 'name', 'api_slug', 'sort_order', 'default_image_path'])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function articles(
        IndexCategoryArticlesRequest $request,
        App $app,
        Category $category,
    ): AnonymousResourceCollection {
        abort_if($category->app_id !== $app->id, 404);

        $perPage = (int) $request->validated('per_page', 30);

        $articles = $category->articles()
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

        return ArticleResource::collection($articles);
    }
}
