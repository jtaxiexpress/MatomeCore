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
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();
        $article = Article::factory()->recycle([$app, $site])->create([
            'url' => 'https://example.com/test-article',
        ]);

        $response = $this->get(route('front.go', [
            'app' => $app,
            'article' => $article,
        ]));

        $response->assertRedirect('https://example.com/test-article');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessOutTraffic::class, function ($job) use ($article) {
            return $job->articleId === $article->id;
        });
    }

    public function test_article_redirect_prevents_duplicate_clicks_within_timeframe(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();
        $article = Article::factory()->recycle([$app, $site])->create([
            'url' => 'https://example.com/test-article',
        ]);

        // First click
        $this->get(route('front.go', ['app' => $app, 'article' => $article]));
        
        // Second click from same simulated IP
        $this->get(route('front.go', ['app' => $app, 'article' => $article]));

        // Should only push one job
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessOutTraffic::class, 1);
    }

    public function test_article_redirect_returns_404_if_article_belongs_to_different_app(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
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
