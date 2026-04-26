<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\ScrapedArticleData;
use App\Models\Site;
use App\Services\ArticleMetadataResolverService;
use App\Services\ArticleScraperService;
use App\Services\CrawlHttpClient;
use Carbon\Carbon;
use Tests\TestCase;

class ArticleMetadataResolverServiceTest extends TestCase
{
    public function test_it_keeps_existing_metadata_without_scraping_when_complete(): void
    {
        $scraper = new class(new CrawlHttpClient) extends ArticleScraperService
        {
            public bool $called = false;

            public function scrape(string $url, ?string $siteDateSelector = null, array $siteNgImages = []): ScrapedArticleData
            {
                $this->called = true;

                return new ScrapedArticleData(
                    url: $url,
                    title: 'スクレイプ済みタイトル',
                    image: 'https://example.com/scraped.jpg',
                    date: '2026-04-02 12:00:00',
                    success: true,
                );
            }
        };

        $site = (new Site)->forceFill([
            'date_selector' => '.entry-date',
            'ng_image_urls' => ['https://example.com/ng.jpg'],
        ]);

        $resolver = new ArticleMetadataResolverService;

        $result = $resolver->resolve(
            scraper: $scraper,
            url: 'https://example.com/article-1',
            rawMetaData: [
                'raw_title' => '既存タイトル',
                'thumbnail_url' => 'https://example.com/existing.jpg',
                'published_at' => '2026-04-01 10:20:30',
            ],
            site: $site,
            logPrefix: '[UnitTest]',
        );

        $this->assertFalse($scraper->called);
        $this->assertSame('既存タイトル', $result->title);
        $this->assertSame('https://example.com/existing.jpg', $result->image);
        $this->assertSame('2026-04-01 10:20:30', $result->date);
    }

    public function test_it_uses_scraped_values_only_for_missing_fields(): void
    {
        $scraper = new class(new CrawlHttpClient) extends ArticleScraperService
        {
            public bool $called = false;

            public function scrape(string $url, ?string $siteDateSelector = null, array $siteNgImages = []): ScrapedArticleData
            {
                $this->called = true;

                return new ScrapedArticleData(
                    url: $url,
                    title: 'スクレイプタイトル',
                    image: 'https://example.com/scraped.jpg',
                    date: '2026-04-03 09:30:00',
                    success: true,
                );
            }
        };

        $site = (new Site)->forceFill([
            'date_selector' => '.entry-date',
            'ng_image_urls' => ['https://example.com/ng.jpg'],
        ]);

        $resolver = new ArticleMetadataResolverService;

        $result = $resolver->resolve(
            scraper: $scraper,
            url: 'https://example.com/article-2',
            rawMetaData: [
                'raw_title' => '既存タイトルを優先',
                'thumbnail_url' => null,
                'published_at' => null,
            ],
            site: $site,
            logPrefix: '[UnitTest]',
        );

        $this->assertTrue($scraper->called);
        $this->assertSame('既存タイトルを優先', $result->title);
        $this->assertSame('https://example.com/scraped.jpg', $result->image);
        $this->assertSame('2026-04-03 09:30:00', $result->date);
    }

    public function test_it_falls_back_to_current_timestamp_when_date_is_unavailable(): void
    {
        Carbon::setTestNow('2026-04-18 12:34:56');

        try {
            $scraper = new class(new CrawlHttpClient) extends ArticleScraperService
            {
                public function scrape(string $url, ?string $siteDateSelector = null, array $siteNgImages = []): ScrapedArticleData
                {
                    return new ScrapedArticleData(
                        url: $url,
                        success: false,
                        errorMessage: 'failed',
                    );
                }
            };

            $site = new Site;

            $resolver = new ArticleMetadataResolverService;

            $result = $resolver->resolve(
                scraper: $scraper,
                url: 'https://example.com/article-3',
                rawMetaData: [],
                site: $site,
                logPrefix: '[UnitTest]',
            );

            $this->assertSame('2026-04-18 12:34:56', $result->date);
        } finally {
            Carbon::setTestNow();
        }
    }
}
