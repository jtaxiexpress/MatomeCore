<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFeedApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Arrange: 有効・無効のアプリを作成
     * Act: 一覧APIを呼ぶ
     * Assert: 有効アプリのみ返り、slugを含む
     */
    public function test_apps_endpoint_returns_only_active_apps_with_slug(): void
    {
        $activeApp = AppModel::factory()->create([
            'name' => 'Main App',
            'api_slug' => 'main-app',
            'icon_path' => 'app-icons/main-app-icon.svg',
            'theme_color' => '#2563EB',
            'is_active' => true,
        ]);

        AppModel::factory()->create([
            'name' => 'Disabled App',
            'api_slug' => 'disabled-app',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/apps');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'main-app')
            ->assertJsonPath('data.0.name', 'Main App')
            ->assertJsonPath('data.0.theme_color', '#2563EB')
            ->assertJsonPath('data.0.icon_url', Storage::disk('public')->url('app-icons/main-app-icon.svg'))
            ->assertJsonPath(
                'data.0.links.categories',
                url('/api/v1/apps/'.$activeApp->api_slug.'/categories')
            );
    }

    /**
     * Arrange: 2つのアプリにカテゴリを作成
     * Act: 対象アプリのカテゴリAPIを呼ぶ
     * Assert: 対象アプリ配下のみ返却され、フラット配列でソートされる
     */
    public function test_categories_endpoint_returns_only_target_app_categories_as_flat_sorted_array(): void
    {
        $targetApp = AppModel::factory()->create([
            'api_slug' => 'target-app',
            'is_active' => true,
        ]);
        $otherApp = AppModel::factory()->create([
            'api_slug' => 'other-app',
            'is_active' => true,
        ]);

        $rootCategory = Category::factory()->for($targetApp)->create([
            'name' => 'Root',
            'api_slug' => 'root',
            'sort_order' => 1,
        ]);
        Category::factory()->for($targetApp)->create([
            'name' => 'Child',
            'api_slug' => 'child',
            'parent_id' => $rootCategory->id,
            'sort_order' => 2,
        ]);
        Category::factory()->for($otherApp)->create([
            'name' => 'Ignored',
            'api_slug' => 'ignored',
        ]);

        $response = $this->getJson('/api/v1/apps/target-app/categories');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'root')
            ->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.1.slug', 'child')
            ->assertJsonPath('data.1.sort_order', 2);
    }

    /**
     * Arrange: 対象カテゴリに複数記事を作成
     * Act: per_page付きで記事APIを呼ぶ
     * Assert: ページングされ、新しい記事が先頭に返る
     */
    public function test_articles_endpoint_returns_paginated_articles_for_slugged_app_and_category(): void
    {
        $app = AppModel::factory()->create([
            'api_slug' => 'news-app',
            'is_active' => true,
        ]);
        $category = Category::factory()->for($app)->create([
            'api_slug' => 'tech',
            'default_image_path' => 'https://cdn.example.com/category-default.png',
        ]);
        $site = Site::factory()->for($app)->create();

        Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'Older',
            'url' => 'https://example.com/older',
            'thumbnail_url' => null,
            'published_at' => now()->subDay(),
        ]);
        Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'Latest',
            'url' => 'https://example.com/latest',
            'thumbnail_url' => null,
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/apps/news-app/categories/tech/articles?per_page=1&page=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Latest')
            ->assertJsonPath('data.0.thumbnail_url', 'https://cdn.example.com/category-default.png')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);
    }

    /**
     * Arrange: 異なるアプリに同一カテゴリslugを作成
     * Act: 別アプリ配下としてカテゴリ記事APIを呼ぶ
     * Assert: scoped bindingで404になる
     */
    public function test_articles_endpoint_returns_404_for_category_outside_the_app_scope(): void
    {
        $appA = AppModel::factory()->create([
            'api_slug' => 'app-a',
            'is_active' => true,
        ]);
        $appB = AppModel::factory()->create([
            'api_slug' => 'app-b',
            'is_active' => true,
        ]);

        Category::factory()->for($appA)->create(['api_slug' => 'local-only']);
        Category::factory()->for($appB)->create(['api_slug' => 'common']);

        $response = $this->getJson('/api/v1/apps/app-a/categories/common/articles');

        $response->assertNotFound();
    }

    /**
     * Arrange: 不正なページサイズを指定
     * Act: 記事APIを呼ぶ
     * Assert: 422でバリデーションエラーになる
     */
    public function test_articles_endpoint_validates_per_page_query_parameter(): void
    {
        $app = AppModel::factory()->create([
            'api_slug' => 'validate-app',
            'is_active' => true,
        ]);
        Category::factory()->for($app)->create(['api_slug' => 'validate-category']);

        $response = $this->getJson('/api/v1/apps/validate-app/categories/validate-category/articles?per_page=0');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    /**
     * Arrange: アプリ・カテゴリのslugを更新
     * Act: 旧slug/新slugのAPIを呼ぶ
     * Assert: 旧slugは404、新slugは200
     */
    public function test_updated_app_and_category_slug_are_reflected_in_api_routes(): void
    {
        $app = AppModel::factory()->create([
            'api_slug' => 'old-app-slug',
            'is_active' => true,
        ]);
        $category = Category::factory()->for($app)->create([
            'api_slug' => 'old-category-slug',
        ]);

        $app->update(['api_slug' => 'new-app-slug']);
        $category->update(['api_slug' => 'new-category-slug']);

        $oldResponse = $this->getJson('/api/v1/apps/old-app-slug/categories/old-category-slug/articles');
        $newResponse = $this->getJson('/api/v1/apps/new-app-slug/categories/new-category-slug/articles');

        $oldResponse->assertNotFound();
        $newResponse->assertOk();
    }

    public function test_read_only_api_rejects_post_requests(): void
    {
        $response = $this->postJson('/api/v1/apps', []);

        $response->assertStatus(405);
    }

    public function test_read_only_api_rejects_delete_requests(): void
    {
        $app = AppModel::factory()->create([
            'api_slug' => 'delete-app',
            'is_active' => true,
        ]);
        $category = Category::factory()->for($app)->create([
            'api_slug' => 'delete-category',
        ]);

        $response = $this->deleteJson('/api/v1/apps/'.$app->api_slug.'/categories/'.$category->api_slug.'/articles');

        $response->assertStatus(405);
    }

    public function test_public_feed_api_rate_limit_is_enforced(): void
    {
        AppModel::factory()->create([
            'api_slug' => 'rate-limit-app',
            'is_active' => true,
        ]);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '10.20.30.40',
            ])->getJson('/api/v1/apps')->assertOk();
        }

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.20.30.40',
        ])->getJson('/api/v1/apps');

        $response->assertTooManyRequests();
    }

    public function test_ai_configuration_supports_only_ollama(): void
    {
        $this->assertSame(['ollama'], array_keys(config('ai.providers')));
        $this->assertSame('ollama', config('ai.default'));
        $this->assertSame('ollama', config('ai.default_for_audio'));
        $this->assertSame('ollama', config('ai.default_for_transcription'));
        $this->assertSame('ollama', config('ai.default_for_embeddings'));
        $this->assertSame('ollama', config('ai.default_for_reranking'));

    }
}
