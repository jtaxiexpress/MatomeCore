<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\App;
use App\Models\Article;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ArticleRedirectTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_article_redirect_records_click_and_redirects(): void
    {
        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();
        $article = Article::factory()->recycle([$app, $site])->create([
            'url' => 'https://example.com/test-article',
        ]);

        $this->assertDatabaseEmpty('article_clicks');

        $response = $this->get(route('front.go', [
            'app' => $app,
            'article' => $article,
        ]));

        $response->assertRedirect('https://example.com/test-article');

        $this->assertDatabaseHas('article_clicks', [
            'article_id' => $article->id,
        ]);
    }

    public function test_article_redirect_returns_404_if_article_belongs_to_different_app(): void
    {
        $app1 = App::factory()->create(['is_active' => true]);
        $app2 = App::factory()->create(['is_active' => true]);

        $site = Site::factory()->recycle($app2)->create();
        $article = Article::factory()->recycle([$app2, $site])->create();

        // Access via app1 but article belongs to app2
        $response = $this->get(route('front.go', [
            'app' => $app1,
            'article' => $article,
        ]));

        $response->assertNotFound();
        $this->assertDatabaseEmpty('article_clicks');
    }
}
