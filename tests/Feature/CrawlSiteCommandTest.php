<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessArticleBatchJob;
use App\Models\App as AppModel;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlSiteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_uses_morss_fallback_when_primary_rss_is_unavailable(): void
    {
        /** @var AppModel $appModel */
        $appModel = AppModel::factory()->create();
        /** @var Site $site */
        $site = Site::factory()->for($appModel)->create([
            'url' => 'https://example.com',
            'crawler_type' => 'sitemap',
            'rss_url' => 'https://example.com/feed.xml',
        ]);

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match (true) {
                $request->url() === 'https://example.com/feed.xml' => Http::response(null, 500),
                str_starts_with($request->url(), 'https://morss.it/?url=') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <title>Fallback item</title>
            <link>https://example.com/articles/1</link>
            <pubDate>Fri, 18 Apr 2026 10:00:00 +0900</pubDate>
        </item>
    </channel>
</rss>
XML, 200),
                default => Http::response(null, 404),
            };
        });

        $this->artisan('app:crawl-site', ['site_id' => $site->id])
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://morss.it/?url='));

        Queue::assertPushed(ProcessArticleBatchJob::class, function (ProcessArticleBatchJob $job) use ($site): bool {
            $queuedUrls = array_column($job->articles, 'url');

            return $job->siteId === $site->id
                && $job->fetchSource === 'rss'
                && in_array('https://example.com/articles/1', $queuedUrls, true);
        });

        $site->refresh();

        $this->assertNull($site->failing_since);
    }

    public function test_command_uses_selector_based_morss_fallback_when_list_item_selector_exists(): void
    {
        $sourceUrl = 'https://dengekionline.com/tag/%E3%82%B5%E3%83%BC%E3%83%93%E3%82%B9%E7%B5%82%E4%BA%86/page/1';
        $expectedMorssUrl = 'https://morss.it/:proxy:items=%7C%7C*%5Bclass=ArticleCard_title__IasvF%5D/'.$sourceUrl;

        /** @var AppModel $appModel */
        $appModel = AppModel::factory()->create();
        /** @var Site $site */
        $site = Site::factory()->for($appModel)->create([
            'url' => $sourceUrl,
            'crawler_type' => 'sitemap',
            'rss_url' => 'https://example.com/feed.xml',
            'list_item_selector' => '.ArticleCard_title__IasvF',
        ]);

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($expectedMorssUrl) {
            return match (true) {
                $request->url() === 'https://example.com/feed.xml' => Http::response(null, 500),
                $request->url() === $expectedMorssUrl => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <title>Fallback item</title>
            <link>https://dengekionline.com/articles/1</link>
            <pubDate>Fri, 18 Apr 2026 10:00:00 +0900</pubDate>
        </item>
    </channel>
</rss>
XML, 200),
                str_starts_with($request->url(), 'https://morss.it/') => Http::response(null, 404),
                default => Http::response(null, 404),
            };
        });

        $this->artisan('app:crawl-site', ['site_id' => $site->id])
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => $request->url() === $expectedMorssUrl);

        Queue::assertPushed(ProcessArticleBatchJob::class, function (ProcessArticleBatchJob $job) use ($site): bool {
            $queuedUrls = array_column($job->articles, 'url');

            return $job->siteId === $site->id
                && $job->fetchSource === 'rss'
                && in_array('https://dengekionline.com/articles/1', $queuedUrls, true);
        });

        $site->refresh();

        $this->assertNull($site->failing_since);
    }

    public function test_command_marks_site_as_failing_when_primary_and_morss_feeds_both_fail(): void
    {
        /** @var AppModel $appModel */
        $appModel = AppModel::factory()->create();
        /** @var Site $site */
        $site = Site::factory()->for($appModel)->create([
            'url' => 'https://example.com',
            'crawler_type' => 'sitemap',
            'rss_url' => 'https://example.com/feed.xml',
            'failing_since' => null,
        ]);

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match (true) {
                $request->url() === 'https://example.com/feed.xml' => Http::response(null, 500),
                str_starts_with($request->url(), 'https://morss.it/') => Http::response(null, 404),
                default => Http::response(null, 404),
            };
        });

        $this->artisan('app:crawl-site', ['site_id' => $site->id])
            ->assertExitCode(0);

        Queue::assertNothingPushed();

        $site->refresh();

        $this->assertNotNull($site->failing_since);
    }
}
