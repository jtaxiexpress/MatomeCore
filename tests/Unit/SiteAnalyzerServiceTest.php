<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Crawl4AiService;
use App\Services\SiteAnalyzerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class SiteAnalyzerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget('site_analyzer_prompt');
        Cache::forget('gemini_model');

        parent::tearDown();
    }

    public function test_analyze_returns_rss_result_when_feed_is_detected_from_html(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://example.com' => Http::response(<<<'HTML'
<html>
    <head>
        <link rel="alternate" type="application/rss+xml" href="/feed.xml">
    </head>
    <body>home</body>
</html>
HTML),
                'https://example.com/feed.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><item><link>https://example.com/posts/1</link></item></channel></rss>
XML),
                default => Http::response(null, 404),
            };
        });

        $result = app(SiteAnalyzerService::class)->analyze('https://example.com');

        $this->assertSame('rss', $result['analysis_method']);
        $this->assertSame('https://example.com/feed.xml', $result['rss_url']);
        $this->assertSame('html', $result['crawler_type']);
    }

    public function test_analyze_returns_sitemap_result_when_sitemap_is_detected(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://example.com' => Http::response('<html><body>home</body></html>'),
                'https://example.com/sitemap.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://example.com/posts/1</loc></url>
</urlset>
XML),
                default => Http::response(null, 404),
            };
        });

        $result = app(SiteAnalyzerService::class)->analyze('https://example.com');

        $this->assertSame('sitemap', $result['analysis_method']);
        $this->assertSame('sitemap', $result['crawler_type']);
        $this->assertSame('https://example.com/sitemap.xml', $result['sitemap_url']);
        $this->assertNull($result['crawl_start_url']);
    }

    public function test_analyze_falls_back_to_llm_when_rss_and_sitemap_are_not_detected(): void
    {
        Cache::put('site_analyzer_prompt', 'テスト用システムプロンプト');
        Cache::put('gemini_model', 'gemini-2.0-flash');

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->url() === 'https://example.com') {
                return Http::response('<html><body>home</body></html>');
            }

            return Http::response(null, 404);
        });

        $this->app->instance(Crawl4AiService::class, new class extends Crawl4AiService
        {
            public function __construct() {}

            public function crawl(string $url): array
            {
                return [
                    'markdown' => "# Article List\n- [Post 1](https://example.com/posts/1)",
                    'thumbnail_url' => null,
                ];
            }
        });

        StructuredAnonymousAgent::fake([[
            'list_item_selector' => '.post-item:not(.pr-item)',
            'link_selector' => 'a.article-link',
            'pagination_url_template' => 'https://example.com/page/{page}',
            'ng_image_urls' => ['https://example.com/logo.png'],
        ]]);

        $result = app(SiteAnalyzerService::class)->analyze('https://example.com');

        $this->assertSame('llm', $result['analysis_method']);
        $this->assertSame('html', $result['crawler_type']);
        $this->assertSame('https://example.com', $result['crawl_start_url']);
        $this->assertSame('.post-item:not(.pr-item)', $result['list_item_selector']);
        $this->assertSame('a.article-link', $result['link_selector']);
        $this->assertSame('https://example.com/page/{page}', $result['pagination_url_template']);
        $this->assertSame(['https://example.com/logo.png'], $result['ng_image_urls']);
    }
}
