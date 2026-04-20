<?php

declare(strict_types=1);

namespace App\Services\Crawlers;

use App\Actions\SendArticleFetchResultNotificationAction;
use App\Models\Article;
use App\Models\Site;
use App\Services\CrawlHttpClient;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class SitemapCrawler implements CrawlerStrategy
{
    public function __construct(
        private readonly CrawlHttpClient $crawlHttpClient,
        private readonly SendArticleFetchResultNotificationAction $sendArticleFetchResultNotificationAction,
    ) {}

    /**
     * @return array<int, array{url: string, title: string|null, thumbnail: string|null, published_at: Carbon|string|null}>
     */
    public function crawl(Site $site, int $maxPages = 5): array
    {
        $feedUrl = $site->rss_url ?? $site->sitemap_url ?? $site->url;
        $articles = [];

        if (empty($feedUrl)) {
            return [];
        }

        try {
            libxml_use_internal_errors(true);

            $response = $this->crawlHttpClient->get(
                url: $feedUrl,
                headers: [
                    'Accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                ],
                timeoutSeconds: 30,
            );

            $usedMorssFallback = false;

            if (! $response->successful()) {
                $fallbackFeed = $this->tryMorssFallbackFeed($site, $feedUrl);

                if ($fallbackFeed === null) {
                    if (is_null($site->failing_since)) {
                        $site->update(['failing_since' => now()]);
                    }

                    return [];
                }

                $feedUrl = $fallbackFeed['url'];
                $response = $fallbackFeed['response'];
                $usedMorssFallback = true;
            }

            $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
            libxml_clear_errors();

            if ($xml === false) {
                if (! $usedMorssFallback) {
                    $fallbackFeed = $this->tryMorssFallbackFeed($site, $feedUrl);

                    if ($fallbackFeed !== null) {
                        $feedUrl = $fallbackFeed['url'];
                        $response = $fallbackFeed['response'];
                        $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                        libxml_clear_errors();
                        $usedMorssFallback = true;
                    }
                }

                if ($xml === false) {
                    if (is_null($site->failing_since)) {
                        $site->update(['failing_since' => now()]);
                    }

                    return [];
                }
            }

            if (! is_null($site->failing_since)) {
                $site->update(['failing_since' => null]);
            }

            $entries = $xml->xpath('//*[local-name()="item"] | //*[local-name()="entry"]') ?: [];
            $isSitemap = false;

            if (empty($entries)) {
                $entries = $xml->xpath('//*[local-name()="loc"]') ?: [];
                if (empty($entries)) {
                    if (is_null($site->failing_since)) {
                        $site->update(['failing_since' => now()]);
                    }

                    return [];
                }

                $isSitemap = true;
            }

            $allUrls = [];
            foreach ($entries as $entry) {
                if ($isSitemap) {
                    $url = trim((string) $entry);
                } else {
                    $url = (string) $entry->link;
                    if (! $url && isset($entry->link['href'])) {
                        $url = (string) $entry->link['href'];
                    }
                    if (empty($url)) {
                        $guids = $entry->xpath('.//*[local-name()="guid"] | .//*[local-name()="id"]') ?: [];
                        if (! empty($guids) && filter_var((string) $guids[0], FILTER_VALIDATE_URL)) {
                            $url = trim((string) $guids[0]);
                        }
                    }
                }
                if (! empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $allUrls[] = $url;
                }
            }

            if ($isSitemap) {
                $articleUrls = [];
                $sitemapUrls = [];

                foreach ($allUrls as $url) {
                    $path = parse_url($url, PHP_URL_PATH) ?? '';
                    if (str_ends_with($path, '.xml')) {
                        $sitemapUrls[] = $url;
                    } else {
                        $articleUrls[] = $url;
                    }
                }

                $sitemapUrls = array_slice($sitemapUrls, 0, 3);
                foreach ($sitemapUrls as $sitemapUrl) {
                    try {
                        $childResponse = $this->crawlHttpClient->get(
                            url: $sitemapUrl,
                            headers: [
                                'Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.8',
                                'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                            ],
                            timeoutSeconds: 15,
                        );

                        if ($childResponse->successful()) {
                            $childXml = @simplexml_load_string($childResponse->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                            if ($childXml !== false) {
                                $childLocs = $childXml->xpath('//*[local-name()="loc"]') ?: [];
                                foreach ($childLocs as $loc) {
                                    $locUrl = trim((string) $loc);
                                    $locPath = parse_url($locUrl, PHP_URL_PATH) ?? '';
                                    if (! empty($locUrl) && filter_var($locUrl, FILTER_VALIDATE_URL) && ! str_ends_with($locPath, '.xml')) {
                                        $articleUrls[] = $locUrl;
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        Log::warning('SitemapCrawler: 子サイトマップ展開エラー', [
                            'site_id' => $site->id,
                            'sitemap_url' => $sitemapUrl,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $allUrls = array_values(array_unique($articleUrls));
            }

            $existingUrls = Article::whereIn('url', $allUrls)->pluck('url')->toArray();

            if ($isSitemap) {
                foreach ($allUrls as $url) {
                    if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                        continue;
                    }
                    if (in_array($url, $existingUrls, true)) {
                        continue;
                    }

                    $articles[] = [
                        'url' => $url,
                        'title' => null,
                        'thumbnail' => null,
                        'published_at' => null,
                    ];
                }
            } else {
                foreach ($entries as $entry) {
                    $url = (string) $entry->link;
                    if (! $url && isset($entry->link['href'])) {
                        $url = (string) $entry->link['href'];
                    }
                    if (empty($url)) {
                        $guids = $entry->xpath('.//*[local-name()="guid"] | .//*[local-name()="id"]') ?: [];
                        if (! empty($guids) && filter_var((string) $guids[0], FILTER_VALIDATE_URL)) {
                            $url = trim((string) $guids[0]);
                        }
                    }
                    if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                        continue;
                    }

                    if (in_array($url, $existingUrls, true)) {
                        continue;
                    }

                    $titleStr = (string) $entry->title;
                    $title = $titleStr !== '' ? trim($titleStr) : null;

                    $publishedAtRaw = (string) $entry->pubDate
                        ?: (string) $entry->children('dc', true)->date
                        ?: (string) $entry->updated
                        ?: (string) $entry->published
                        ?: (string) $entry->date;

                    $publishedAt = null;
                    if ($publishedAtRaw) {
                        try {
                            $publishedAt = Carbon::parse($publishedAtRaw)->toDateTimeString();
                        } catch (Exception) {
                            $publishedAt = null;
                        }
                    }

                    $thumbnail = null;
                    if (isset($entry->enclosure) && isset($entry->enclosure['url'])) {
                        $thumbnail = (string) $entry->enclosure['url'];
                    } elseif ($entry->children('media', true)->content && isset($entry->children('media', true)->content->attributes()->url)) {
                        $thumbnail = (string) $entry->children('media', true)->content->attributes()->url;
                    } elseif ($entry->children('media', true)->thumbnail && isset($entry->children('media', true)->thumbnail->attributes()->url)) {
                        $thumbnail = (string) $entry->children('media', true)->thumbnail->attributes()->url;
                    }

                    if (! $thumbnail) {
                        $content = (string) $entry->children('content', true)->encoded ?: (string) $entry->description;
                        if (preg_match('/<img[^>]+src=[\'\"]([^\'\"]+)[\'\"]/i', $content, $matches)) {
                            $thumbnail = $matches[1];
                        }
                    }

                    $articles[] = [
                        'url' => $url,
                        'title' => $title,
                        'thumbnail' => $thumbnail,
                        'published_at' => $publishedAt ? Carbon::parse($publishedAt) : null,
                    ];
                }
            }

            return $articles;
        } catch (Exception $e) {
            if (is_null($site->failing_since)) {
                $site->update(['failing_since' => now()]);
            }

            $this->sendArticleFetchResultNotificationAction->execute(
                site: $site,
                fetchSource: 'rss',
                savedCount: 0,
                missedCount: 0,
                detail: 'RSS新規記事取得の処理中にエラーが発生しました: '.$e->getMessage(),
                failed: true,
            );

            return [];
        }
    }

    /**
     * @return array{url: string, response: Response}|null
     */
    private function tryMorssFallbackFeed(Site $site, string $failedFeedUrl): ?array
    {
        $sourceUrl = (string) ($site->url ?: $failedFeedUrl);
        $candidates = $this->buildMorssFallbackCandidates($sourceUrl, $site->list_item_selector);

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $response = $this->crawlHttpClient->get(
                url: $candidate,
                headers: [
                    'Accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                ],
                timeoutSeconds: 15,
            );

            if (! $response->successful()) {
                continue;
            }

            if (! $this->isFeedXml($response->body())) {
                continue;
            }

            Log::info('SitemapCrawler: morss.it fallback succeeded.', [
                'site_id' => $site->id,
                'fallback_url' => $candidate,
            ]);

            return [
                'url' => $candidate,
                'response' => $response,
            ];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function buildMorssFallbackCandidates(string $sourceUrl, ?string $listItemSelector): array
    {
        $candidates = [];
        $morssItemsSelector = $this->toMorssItemsSelector($listItemSelector);

        if ($morssItemsSelector !== null) {
            $candidates[] = $this->buildMorssPathStyleFeedUrl(
                $sourceUrl,
                $this->encodeMorssOptionValue($morssItemsSelector)
            );
        }

        $candidates[] = 'https://morss.it/?url='.rawurlencode($sourceUrl);
        $candidates[] = 'https://morss.it/'.rawurlencode($sourceUrl);

        return array_values(array_unique($candidates));
    }

    private function buildMorssPathStyleFeedUrl(string $sourceUrl, string $encodedItemsSelector): string
    {
        return "https://morss.it/:proxy:items={$encodedItemsSelector}/{$sourceUrl}";
    }

    private function toMorssItemsSelector(?string $listItemSelector): ?string
    {
        $selector = trim((string) $listItemSelector);

        if ($selector === '') {
            return null;
        }

        if (str_starts_with($selector, '||')) {
            return $selector;
        }

        if (preg_match('/^\[class=([^\]]+)\]$/u', $selector, $matches)) {
            return '||*[class='.$matches[1].']';
        }

        if (preg_match('/^\.([A-Za-z0-9_-]+)$/u', $selector, $matches)) {
            return '||*[class='.$matches[1].']';
        }

        if (preg_match('/\.([A-Za-z0-9_-]+)/u', $selector, $matches)) {
            return '||*[class='.$matches[1].']';
        }

        return null;
    }

    private function encodeMorssOptionValue(string $value): string
    {
        return strtr(rawurlencode($value), [
            '%2A' => '*',
            '%3D' => '=',
        ]);
    }

    private function isFeedXml(string $xmlBody): bool
    {
        $xml = @simplexml_load_string($xmlBody, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            return false;
        }

        $rootName = strtolower($xml->getName());

        if (in_array($rootName, ['rss', 'feed', 'rdf'], true)) {
            return true;
        }

        $entries = $xml->xpath('//*[local-name()="item"] | //*[local-name()="entry"]') ?: [];

        return $entries !== [];
    }
}
