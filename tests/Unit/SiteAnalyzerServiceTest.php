<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\App as AppModel;
use App\Services\SiteAnalyzerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteAnalyzerServiceTest extends TestCase
{
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

        $result = app(SiteAnalyzerService::class)->analyze('https://example.com');

        $this->assertSame('rss+html', $result['analysis_method']);
        $this->assertSame('html', $result['crawler_type']);
        $this->assertSame('article, .post, .entry, .list-item, li', $result['list_item_selector']);
        $this->assertSame('a', $result['link_selector']);
        $this->assertNull($result['pagination_url_template']);
        $this->assertSame('Example Site', $result['site_title']);
        $this->assertContains(
            'サイトマップは検出できましたが、記事メタデータ（タイトル・URL・画像・公開日）を確認できなかったため、一覧ページ抽出へフォールバックします。',
            $result['diagnostics']
        );
    }

    public function test_analyze_uses_morss_feed_when_native_rss_is_missing(): void
    {
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

        $result = app(SiteAnalyzerService::class)->analyze('https://example.com');

        $this->assertNotNull($result['rss_url']);
        $this->assertTrue(str_starts_with((string) $result['rss_url'], 'https://morss.it/'));
        $this->assertSame('rss+html', $result['analysis_method']);
        $this->assertSame('html', $result['crawler_type']);
        $this->assertSame('article, .post, .entry, .list-item, li', $result['list_item_selector']);
    }

    public function test_analyze_builds_selector_based_morss_url_for_dengeki_online(): void
    {
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

        $result = app(SiteAnalyzerService::class)->analyze($sourceUrl);

        $this->assertSame($expectedMorssUrl, $result['rss_url']);
        Http::assertSent(fn ($request): bool => $request->url() === $expectedMorssUrl);
    }

    public function test_analyze_uses_html_extraction_rules_when_sitemap_is_not_detected(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->url() === 'https://example.com') {
                return Http::response('<html><body>home</body></html>');
            }

            return Http::response(null, 404);
        });

        $result = app(SiteAnalyzerService::class)->analyze('https://example.com');

        $this->assertSame('html', $result['analysis_method']);
        $this->assertSame('html', $result['crawler_type']);
        $this->assertSame('https://example.com', $result['crawl_start_url']);
        $this->assertSame('article, .post, .entry, .list-item, li', $result['list_item_selector']);
        $this->assertSame('a', $result['link_selector']);
        $this->assertNull($result['pagination_url_template']);
        $this->assertSame([], $result['ng_image_urls']);
    }

    public function test_analyze_prefers_app_custom_rule_over_hardcoded_domain_selector(): void
    {
        $url = 'https://dengekionline.com/news';

        $appModel = new AppModel([
            'custom_scrape_rules' => [
                [
                    'domain' => 'dengekionline.com',
                    'list_item_selector' => '.custom-list-item',
                    'link_selector' => '.custom-list-item a',
                ],
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake(function ($request) use ($url) {
            if ($request->url() === $url) {
                return Http::response('<html><body><div class="custom-list-item"><a href="/items/1">Item</a></div></body></html>');
            }

            return Http::response(null, 404);
        });

        $result = app(SiteAnalyzerService::class)->analyze($url, $appModel);

        $this->assertSame('html', $result['crawler_type']);
        $this->assertSame('.custom-list-item', $result['list_item_selector']);
        $this->assertSame('.custom-list-item a', $result['link_selector']);
    }

    public function test_analyze_uses_livedoor_fixed_urls_even_when_app_custom_rule_exists(): void
    {
        $appModel = new AppModel([
            'custom_scrape_rules' => [
                [
                    'domain' => 'blog.livedoor.jp',
                    'list_item_selector' => '.custom-list-item',
                    'link_selector' => '.custom-list-item a',
                ],
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'http://blog.livedoor.jp/nanjstu/' => Http::response('<html><body>home</body></html>'),
                default => Http::response(null, 404),
            };
        });

        $result = app(SiteAnalyzerService::class)->analyze('http://blog.livedoor.jp/nanjstu/', $appModel);

        $this->assertSame('http://blog.livedoor.jp/nanjstu/index.rdf', $result['rss_url']);
        $this->assertSame('http://blog.livedoor.jp/nanjstu/sitemap.xml', $result['sitemap_url']);
        $this->assertNull($result['list_item_selector']);
        $this->assertNull($result['link_selector']);
    }
}
