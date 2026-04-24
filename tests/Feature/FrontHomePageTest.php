<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\App;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FrontHomePageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_root_displays_cross_app_home(): void
    {
        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();
        $category = Category::factory()->recycle($app)->create();

        Article::factory()->recycle([$app, $site, $category])->create([
            'title' => 'クロスアプリテスト記事',
            'published_at' => now(),
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('ゆにこーんアンテナ');
        $response->assertDontSee('ゆにこーんアンテナ 全体記事');
        $response->assertSee('クロスアプリテスト記事');
    }

    public function test_root_displays_empty_state_when_no_active_apps(): void
    {
        App::factory()->create(['is_active' => false]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('記事が見つかりませんでした');
    }

    public function test_home_page_renders_for_valid_app(): void
    {
        $app = App::factory()->create(['is_active' => true]);

        $response = $this->get(route('front.home', $app));

        $response->assertOk();
        $response->assertSee($app->name);
    }

    public function test_home_page_title_uses_app_name_only(): void
    {
        $app = App::factory()->create(['is_active' => true]);

        $response = $this->get(route('front.home', $app));

        $response->assertOk();
        $response->assertSee('<title>'.e($app->name).'</title>', false);
        $response->assertSee('property="og:title" content="'.e($app->name).'"', false);
        $response->assertSee('name="twitter:title" content="'.e($app->name).'"', false);
        $response->assertDontSee($app->name.' |', false);
    }

    public function test_home_page_returns_404_for_invalid_slug(): void
    {
        $response = $this->get('/s/nonexistent-app-slug');

        $response->assertNotFound();
    }

    public function test_home_page_displays_articles(): void
    {
        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();
        $category = Category::factory()->recycle($app)->create();

        Article::factory()->recycle([$app, $site, $category])->create([
            'title' => 'テスト記事タイトル',
            'published_at' => now(),
        ]);

        $response = $this->get(route('front.home', $app));

        $response->assertOk();
        $response->assertSee('テスト記事タイトル');
    }

    public function test_home_page_displays_category_tabs(): void
    {
        $app = App::factory()->create(['is_active' => true]);
        Category::factory()->recycle($app)->create(['name' => 'ニュース']);
        Category::factory()->recycle($app)->create(['name' => 'エンタメ']);

        $response = $this->get(route('front.home', $app));

        $response->assertOk();
        $response->assertSee('総合');
        $response->assertSee('ニュース');
        $response->assertSee('エンタメ');
    }

    public function test_home_page_renders_compact_sidebar_ranking_and_pagination(): void
    {
        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();
        $category = Category::factory()->recycle($app)->create();

        Article::factory()
            ->count(51)
            ->recycle([$app, $site, $category])
            ->create([
                'published_at' => now(),
            ]);

        $response = $this->get(route('front.home', $app));

        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('text-[13px] font-bold text-text-primary dark:text-white', $content);
        $this->assertStringContainsString('class="flex w-full flex-col items-center gap-3"', $content);
        $this->assertStringContainsString('1–50 件 / 全 51 件', $content);

        $paginationPosition = strpos($content, 'class="hidden w-full sm:flex sm:justify-center"');
        $countPosition = strpos($content, 'text-xs text-text-secondary dark:text-text-tertiary');

        $this->assertNotFalse($paginationPosition);
        $this->assertNotFalse($countPosition);
        $this->assertGreaterThan($paginationPosition, $countPosition);
    }

    public function test_home_page_displays_empty_state_when_no_articles(): void
    {
        $app = App::factory()->create(['is_active' => true]);

        $response = $this->get(route('front.home', $app));

        $response->assertOk();
        $response->assertSee('記事が見つかりませんでした');
    }

    public function test_root_sidebar_hot_entry_links_use_app_slug(): void
    {
        Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create(['traffic_score' => 75]);
        $category = Category::factory()->recycle($app)->create();

        $article = Article::factory()->recycle([$app, $site, $category])->create([
            'title' => 'ホット記事リンクテスト',
            'published_at' => now(),
            'daily_out_count' => 18,
        ]);

        $response = $this->get('/');

        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('注目の記事', $content);
        $this->assertStringContainsString(
            route('front.go', ['app' => $app->api_slug, 'article' => $article->id]),
            $content
        );
        $this->assertStringNotContainsString(
            route('front.go', ['app' => $app->id, 'article' => $article->id]),
            $content
        );
    }

    public function test_root_layout_includes_favicon_and_header_icon_assets(): void
    {
        App::factory()->create(['is_active' => true]);

        $response = $this->get('/');

        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString(asset('images/favicon.ico'), $content);
        $this->assertStringContainsString(asset('images/apple-touch-icon.png'), $content);
        $this->assertStringContainsString(asset('images/icon.avif'), $content);
    }

    public function test_home_page_shows_loading_skeleton_markup(): void
    {
        $app = App::factory()->create(['is_active' => true]);

        $response = $this->get(route('front.home', $app));

        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('wire:loading.delay.short', $content);
        $this->assertStringContainsString('animate-pulse', $content);
        $this->assertStringContainsString('opacity-60', $content);
    }
}
