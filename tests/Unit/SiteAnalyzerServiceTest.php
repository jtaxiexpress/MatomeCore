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

    public function test_analyze_prefers_sitemap_for_bulk_extraction_when_available_even_if_rss_exists(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://example.com' => Http::response(<<<'HTML'
<html>
    <head>
        <meta property="og:site_name" content="Example News">
        <title>Example News | Home</title>
        <link rel="alternate" type="application/rss+xml" href="/feed.xml">
    </head>
    <body>home</body>
</html>
HTML),
                'https://example.com/feed.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><item><link>https://example.com/posts/1</link></item></channel></rss>
XML),
                'https://example.com/sitemap.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://example.com/posts/1</loc></url>
</urlset>
XML),
                'https://example.com/posts/1' => Http::response(<<<'HTML'
<html>
    <head>
        <title>Article 1</title>
        <meta property="og:title" content="Article 1">
        <meta property="og:image" content="https://example.com/thumbnail.jpg">
        <meta property="article:published_time" content="2026-04-18T09:00:00+09:00">
    </head>
    <body>
        <article><time datetime="2026-04-18T09:00:00+09:00">2026-04-18</time></article>
    </body>
</html>
HTML),
                default => Http::response(null, 404),
            };
        });

        $result = app(SiteAnalyzerService::class)->analyze('https://example.com');

        $this->assertSame('rss+sitemap', $result['analysis_method']);
        $this->assertSame('Example News', $result['site_title']);
        $this->assertSame('https://example.com/feed.xml', $result['rss_url']);
        $this->assertSame('sitemap', $result['crawler_type']);
        $this->assertSame('https://example.com/sitemap.xml', $result['sitemap_url']);
    }

    public function test_analyze_uses_fixed_urls_for_livedoor_blog(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'http://blog.livedoor.jp/nanjstu/' => Http::response(<<<'HTML'
<html>
    <head>
        <title>ライブドアテストブログ</title>
    </head>
    <body>home</body>
</html>
HTML),
                default => Http::response(null, 404),
            };
        });

        $result = app(SiteAnalyzerService::class)->analyze('http://blog.livedoor.jp/nanjstu/');

        $this->assertSame('http://blog.livedoor.jp/nanjstu/index.rdf', $result['rss_url']);
        $this->assertSame('sitemap', $result['crawler_type']);
        $this->assertSame('http://blog.livedoor.jp/nanjstu/sitemap.xml', $result['sitemap_url']);
        $this->assertNull($result['crawl_start_url']);
        $this->assertSame('rss+sitemap', $result['analysis_method']);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'http://blog.livedoor.jp/nanjstu/index.rdf');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'http://blog.livedoor.jp/nanjstu/sitemap.xml');
    }

    public function test_analyze_falls_back_to_html_when_sitemap_metadata_validation_fails(): void
    {
        Cache::put('site_analyzer_prompt', 'テスト用システムプロンプト');
        Cache::put('gemini_model', 'gemini-2.0-flash');

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://example.com' => Http::response(<<<'HTML'
<html>
    <head>
        <meta property="og:site_name" content="Example Site">
        <link rel="alternate" type="application/rss+xml" href="/feed.xml">
    </head>
    <body>home</body>
</html>
HTML),
                'https://example.com/feed.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><item><link>https://example.com/posts/1</link></item></channel></rss>
XML),
                'https://example.com/sitemap.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://example.com/posts/1</loc></url>
</urlset>
XML),
                'https://example.com/posts/1' => Http::response('<html><body>no metadata</body></html>'),
                default => Http::response(null, 404),
            };
        });

        $this->app->instance(Crawl4AiService::class, new class extends Crawl4AiService
        {
            public function __construct() {}

            public function crawl(string $url): array
            {
                return [
                    'markdown' => "# News\n- [Post 1](https://example.com/posts/1)",
                    'thumbnail_url' => null,
                ];
            }
        });

        StructuredAnonymousAgent::fake([[
            'list_item_selector' => '.article-item:not(.pr)',
            'link_selector' => 'a.article-link',
            'pagination_url_template' => 'https://example.com/news/page/{page}',
            'ng_image_urls' => [],
        ]]);

        $result = app(SiteAnalyzerService::class)->analyze('https://example.com');

        $this->assertSame('rss+llm', $result['analysis_method']);
        $this->assertSame('html', $result['crawler_type']);
        $this->assertSame('.article-item:not(.pr)', $result['list_item_selector']);
        $this->assertSame('a.article-link', $result['link_selector']);
        $this->assertSame('https://example.com/news/page/{page}', $result['pagination_url_template']);
        $this->assertSame('Example Site', $result['site_title']);
        $this->assertContains(
            'サイトマップは検出できましたが、記事メタデータ（タイトル・URL・画像・公開日）を確認できなかったため、一覧ページ抽出へフォールバックします。',
            $result['diagnostics']
        );
    }

    public function test_analyze_uses_morss_feed_when_native_rss_is_missing(): void
    {
        Cache::put('site_analyzer_prompt', 'テスト用システムプロンプト');
        Cache::put('gemini_model', 'gemini-2.0-flash');

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->url() === 'https://example.com') {
                return Http::response('<html><body>home</body></html>');
            }

            if (str_starts_with($request->url(), 'https://morss.it/')) {
                return Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><item><link>https://example.com/posts/42</link></item></channel></rss>
XML);
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

        $this->assertNotNull($result['rss_url']);
        $this->assertTrue(str_starts_with((string) $result['rss_url'], 'https://morss.it/'));
        $this->assertSame('rss+llm', $result['analysis_method']);
        $this->assertSame('html', $result['crawler_type']);
        $this->assertSame('.post-item:not(.pr-item)', $result['list_item_selector']);
    }

    public function test_analyze_builds_selector_based_morss_url_for_dengeki_online(): void
    {
        Cache::put('site_analyzer_prompt', 'テスト用システムプロンプト');
        Cache::put('gemini_model', 'gemini-2.0-flash');

        $sourceUrl = 'https://dengekionline.com/tag/%E3%82%B5%E3%83%BC%E3%83%93%E3%82%B9%E7%B5%82%E4%BA%86/page/1';
        $expectedMorssUrl = 'https://morss.it/:proxy:items=%7C%7C*%5Bclass=ArticleCard_title__IasvF%5D/'.$sourceUrl;

        Http::preventStrayRequests();
        Http::fake(function ($request) use ($sourceUrl, $expectedMorssUrl) {
            return match ($request->url()) {
                $sourceUrl => Http::response('<html><body>listing</body></html>'),
                $expectedMorssUrl => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><item><link>https://dengekionline.com/articles/1</link></item></channel></rss>
XML),
                default => Http::response(null, 404),
            };
        });

        $this->app->instance(Crawl4AiService::class, new class extends Crawl4AiService
        {
            public function __construct() {}

            public function crawl(string $url): array
            {
                return [
                    'markdown' => "# Dengeki\n- [記事1](https://dengekionline.com/articles/1)",
                    'thumbnail_url' => null,
                ];
            }
        });

        StructuredAnonymousAgent::fake([[
            'list_item_selector' => '.ArticleCard_title__IasvF',
            'link_selector' => 'a',
            'pagination_url_template' => null,
            'ng_image_urls' => [],
        ]]);

        $result = app(SiteAnalyzerService::class)->analyze($sourceUrl);

        $this->assertSame($expectedMorssUrl, $result['rss_url']);
        Http::assertSent(fn ($request): bool => $request->url() === $expectedMorssUrl);
    }

    public function test_analyze_uses_html_extraction_rules_when_sitemap_is_not_detected(): void
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
