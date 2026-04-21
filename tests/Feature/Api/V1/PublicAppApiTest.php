<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\ArticleClick;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicAppApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_endpoint_returns_app_basic_settings(): void
    {
        $app = AppModel::factory()->create([
            'name' => 'Demo App',
            'api_slug' => 'demo-app',
            'icon_path' => 'app-icons/demo.svg',
            'theme_color' => '#2563EB',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/apps/'.$app->api_slug.'/config');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Demo App')
            ->assertJsonPath('data.slug', 'demo-app')
            ->assertJsonPath('data.icon_url', Storage::disk('public')->url('app-icons/demo.svg'))
            ->assertJsonPath('data.theme_color', '#2563EB');
    }

    public function test_feed_endpoint_returns_paginated_articles_for_app(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'news-app', 'is_active' => true]);
        $category = Category::factory()->for($app)->create(['name' => 'Tech', 'api_slug' => 'tech']);
        $site = Site::factory()->for($app)->create(['name' => 'Tech Media']);

        Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'Older Post',
            'url' => 'https://example.com/older-post',
            'published_at' => now()->subDay(),
        ]);

        Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'Latest Post',
            'url' => 'https://example.com/latest-post',
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/apps/news-app/feed');

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Latest Post')
            ->assertJsonPath('data.0.site_name', 'Tech Media')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_feed_endpoint_can_filter_by_category_slug(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'app-with-tabs', 'is_active' => true]);
        $tech = Category::factory()->for($app)->create(['name' => 'Tech', 'api_slug' => 'tech']);
        $sports = Category::factory()->for($app)->create(['name' => 'Sports', 'api_slug' => 'sports']);
        $site = Site::factory()->for($app)->create(['name' => 'Media']);

        Article::factory()->for($app)->for($tech)->for($site)->create([
            'title' => 'AI Update',
            'url' => 'https://example.com/ai-update',
        ]);

        Article::factory()->for($app)->for($sports)->for($site)->create([
            'title' => 'Sports Update',
            'url' => 'https://example.com/sports-update',
        ]);

        $response = $this->getJson('/api/v1/apps/app-with-tabs/feed?category_slug=tech');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'AI Update');
    }

    public function test_feed_endpoint_returns_404_for_unknown_category_slug(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'unknown-category-app', 'is_active' => true]);

        $response = $this->getJson('/api/v1/apps/'.$app->api_slug.'/feed?category_slug=missing');

        $response->assertNotFound()
            ->assertJsonPath('message', '指定されたカテゴリが見つかりません。');
    }

    public function test_search_endpoint_supports_space_separated_and_search(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'search-app', 'is_active' => true]);
        $category = Category::factory()->for($app)->create(['api_slug' => 'general']);
        $site = Site::factory()->for($app)->create(['name' => 'Search Media']);

        Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'AI 最新 ニュース',
            'url' => 'https://example.com/ai-news',
        ]);

        Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'AI 経済 解説',
            'url' => 'https://example.com/ai-economy',
        ]);

        $response = $this->getJson('/api/v1/apps/search-app/articles/search?keyword=AI ニュース');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'AI 最新 ニュース')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_search_endpoint_requires_keyword_parameter(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'search-required-app', 'is_active' => true]);

        $response = $this->getJson('/api/v1/apps/'.$app->api_slug.'/articles/search');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['keyword']);
    }

    public function test_click_endpoint_records_article_click_event(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'click-app', 'is_active' => true]);
        $category = Category::factory()->for($app)->create();
        $site = Site::factory()->for($app)->create();
        $article = Article::factory()->for($app)->for($category)->for($site)->create();

        $response = $this->postJson('/api/v1/articles/'.$article->id.'/click');

        $response->assertCreated()
            ->assertJsonPath('data.article_id', $article->id);

        $this->assertDatabaseHas('article_clicks', [
            'article_id' => $article->id,
        ]);
    }

    public function test_trending_endpoint_returns_articles_ordered_by_click_count(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'trending-app', 'is_active' => true]);
        $otherApp = AppModel::factory()->create(['api_slug' => 'other-app', 'is_active' => true]);

        $category = Category::factory()->for($app)->create();
        $site = Site::factory()->for($app)->create(['name' => 'Trend Media']);

        $otherCategory = Category::factory()->for($otherApp)->create();
        $otherSite = Site::factory()->for($otherApp)->create();

        $first = Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'Most Clicked',
            'url' => 'https://example.com/most-clicked',
            'published_at' => now()->subHour(),
        ]);

        $second = Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'Second Clicked',
            'url' => 'https://example.com/second-clicked',
            'published_at' => now()->subHours(2),
        ]);

        $ignored = Article::factory()->for($otherApp)->for($otherCategory)->for($otherSite)->create([
            'title' => 'Other App Article',
            'url' => 'https://example.com/other-app-article',
            'published_at' => now()->subHour(),
        ]);

        ArticleClick::query()->create(['article_id' => $first->id, 'clicked_at' => now()->subHour()]);
        ArticleClick::query()->create(['article_id' => $first->id, 'clicked_at' => now()->subMinutes(30)]);
        ArticleClick::query()->create(['article_id' => $first->id, 'clicked_at' => now()->subMinutes(10)]);
        ArticleClick::query()->create(['article_id' => $second->id, 'clicked_at' => now()->subMinutes(20)]);
        ArticleClick::query()->create(['article_id' => $ignored->id, 'clicked_at' => now()->subMinutes(5)]);

        $response = $this->getJson('/api/v1/apps/trending-app/articles/trending?period=daily&limit=20');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Most Clicked')
            ->assertJsonPath('data.0.click_count', 3)
            ->assertJsonPath('data.1.title', 'Second Clicked')
            ->assertJsonPath('data.1.click_count', 1);
    }

    public function test_trending_endpoint_applies_period_filter(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'period-app', 'is_active' => true]);
        $category = Category::factory()->for($app)->create();
        $site = Site::factory()->for($app)->create(['name' => 'Period Media']);

        $article = Article::factory()->for($app)->for($category)->for($site)->create([
            'title' => 'Weekly Hit',
            'url' => 'https://example.com/weekly-hit',
        ]);

        ArticleClick::query()->create(['article_id' => $article->id, 'clicked_at' => now()->subDays(2)]);

        $daily = $this->getJson('/api/v1/apps/period-app/articles/trending?period=daily');
        $weekly = $this->getJson('/api/v1/apps/period-app/articles/trending?period=weekly');

        $daily->assertOk()->assertJsonCount(0, 'data');
        $weekly->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_trending_endpoint_validates_limit_upper_bound(): void
    {
        $app = AppModel::factory()->create(['api_slug' => 'limit-app', 'is_active' => true]);

        $response = $this->getJson('/api/v1/apps/'.$app->api_slug.'/articles/trending?limit=51');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
    }
}
