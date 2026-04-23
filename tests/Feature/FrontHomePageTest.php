<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\App;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $response->assertSee('MatomeCore 全体記事');
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

    public function test_home_page_displays_empty_state_when_no_articles(): void
    {
        $app = App::factory()->create(['is_active' => true]);

        $response = $this->get(route('front.home', $app));

        $response->assertOk();
        $response->assertSee('記事が見つかりませんでした');
    }
}
