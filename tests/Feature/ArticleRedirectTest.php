<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessOutTraffic;
use App\Models\App;
use App\Models\Article;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class ArticleRedirectTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_article_redirect_records_click_and_redirects(): void
    {
        Queue::fake();
        Cache::flush();

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

        $today = now()->format('Y-m-d');
        $this->assertEquals(1, (int) Redis::hGet("traffic:out:article:{$today}", (string) $article->id));
        $this->assertEquals(1, (int) Redis::hGet("traffic:out:site:{$today}", (string) $site->id));
    }

    public function test_article_redirect_uses_session_id_in_cache_key(): void
    {
        Queue::fake();
        Cache::spy();

        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();
        $article = Article::factory()->recycle([$app, $site])->create([
            'url' => 'https://example.com/test-article',
        ]);

        $this->get(route('front.go', ['app' => $app, 'article' => $article]));

        Cache::shouldHaveReceived('put')->once()->withArgs(function (string $key, bool $value, int $ttl) use ($article): bool {
            return preg_match('/^out_hit_'.$article->id.'_[0-9.]+_.+$/', $key) === 1
                && $value === true
                && $ttl === 3600;
        });
    }

    public function test_article_redirect_allows_same_ip_with_new_session(): void
    {
        Queue::fake();
        Cache::flush();

        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create();
        $article = Article::factory()->recycle([$app, $site])->create([
            'url' => 'https://example.com/test-article',
        ]);

        Session::setId('session-one');
        $this->get(route('front.go', ['app' => $app, 'article' => $article]));

        Session::setId('session-two');
        $this->get(route('front.go', ['app' => $app, 'article' => $article]));

        $today = now()->format('Y-m-d');
        $this->assertEquals(2, (int) Redis::hGet("traffic:out:article:{$today}", (string) $article->id));
        $this->assertEquals(2, (int) Redis::hGet("traffic:out:site:{$today}", (string) $site->id));
    }

    public function test_article_redirect_returns_404_if_article_belongs_to_different_app(): void
    {
        Queue::fake();
        $app1 = App::factory()->create(['is_active' => true]);
        $app2 = App::factory()->create(['is_active' => true]);

        $site = Site::factory()->recycle($app2)->create();
        $article = Article::factory()->recycle([$app2, $site])->create();

        $response = $this->get(route('front.go', [
            'app' => $app1,
            'article' => $article,
        ]));

        $response->assertNotFound();
        $today = now()->format('Y-m-d');
        $this->assertEquals(0, (int) Redis::hGet("traffic:out:articles:{$today}", (string) $article->id));
    }
}
