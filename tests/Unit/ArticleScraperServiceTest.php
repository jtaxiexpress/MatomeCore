<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ArticleScraperService;
use App\Services\CrawlHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleScraperServiceTest extends TestCase
{
    public function test_extracts_date_from_og_published_time_meta(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(
                '<html><head><meta property="article:published_time" content="2026-04-01 10:20:30"></head><body><article>content</article></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $service = new ArticleScraperService(new CrawlHttpClient);

        $result = $service->scrape('https://example.com/with-ogp-date');

        $this->assertTrue($result->success);
        $this->assertSame('2026-04-01 10:20:30', $result->date);
    }

    public function test_extracts_date_from_site_specific_date_selector(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(
                '<html><body><div class="entry-meta">2026/04/12 08:10</div></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $service = new ArticleScraperService(new CrawlHttpClient);

        $result = $service->scrape('https://example.com/with-selector-date', '.entry-meta');

        $this->assertTrue($result->success);
        $this->assertSame('2026-04-12 08:10:00', $result->date);
    }

    public function test_does_not_extract_date_from_body_text_only(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(
                '<html><head><title>sample</title></head><body><p>公開日: 2026年04月23日 10:30</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $service = new ArticleScraperService(new CrawlHttpClient);

        $result = $service->scrape('https://example.com/body-date-only');

        $this->assertTrue($result->success);
        $this->assertNull($result->date);
    }
}
