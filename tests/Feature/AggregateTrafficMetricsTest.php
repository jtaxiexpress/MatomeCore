<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\App;
use App\Models\Article;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AggregateTrafficMetricsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_aggregates_article_and_site_counts_and_score(): void
    {
        $app = App::factory()->create(['is_active' => true]);
        $siteA = Site::factory()->recycle($app)->create(['name' => 'Site A']);
        $siteB = Site::factory()->recycle($app)->create(['name' => 'Site B']);

        $articleA = Article::factory()->recycle([$app, $siteA])->create();
        $articleB = Article::factory()->recycle([$app, $siteB])->create();

        $today = now()->format('Y-m-d');
        Redis::hIncrBy("traffic:out:article:{$today}", (string) $articleA->id, 2);
        Redis::hIncrBy("traffic:out:article:{$today}", (string) $articleB->id, 1);
        Redis::hIncrBy("traffic:out:site:{$today}", (string) $siteA->id, 2);
        Redis::hIncrBy("traffic:out:site:{$today}", (string) $siteB->id, 1);
        Redis::hIncrBy("traffic:in:{$today}", (string) $siteA->id, 2);
        Redis::hIncrBy("traffic:in:{$today}", (string) $siteB->id, 1);

        $this->artisan('traffic:aggregate')->assertExitCode(0);

        $this->assertDatabaseHas('articles', [
            'id' => $articleA->id,
            'daily_out_count' => 2,
        ]);

        $this->assertDatabaseHas('articles', [
            'id' => $articleB->id,
            'daily_out_count' => 1,
        ]);

        $this->assertDatabaseHas('sites', [
            'id' => $siteA->id,
            'daily_in_count' => 2,
            'daily_out_count' => 2,
            'traffic_score' => 1,
        ]);

        $this->assertDatabaseHas('sites', [
            'id' => $siteB->id,
            'daily_in_count' => 1,
            'daily_out_count' => 1,
            'traffic_score' => 0,
        ]);
    }

    public function test_command_ignores_records_older_than_24_hours(): void
    {
        $app = App::factory()->create(['is_active' => true]);
        $site = Site::factory()->recycle($app)->create(['name' => 'Old site']);
        $article = Article::factory()->recycle([$app, $site])->create();

        $twoDaysAgo = now()->subDays(2)->format('Y-m-d');
        Redis::hIncrBy("traffic:out:article:{$twoDaysAgo}", (string) $article->id, 1);
        Redis::hIncrBy("traffic:in:{$twoDaysAgo}", (string) $site->id, 1);

        $this->artisan('traffic:aggregate')->assertExitCode(0);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'daily_out_count' => 0,
        ]);

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'daily_in_count' => 0,
            'daily_out_count' => 0,
            'traffic_score' => 0,
        ]);
    }
}
