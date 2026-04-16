<?php

namespace App\Console\Commands;

use App\Jobs\ProcessArticleBatchJob;
use App\Models\Article;
use App\Models\Site;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class CrawlSiteCommand extends Command
{
    protected $signature = 'app:crawl-site {site_id} {--max-pages=5}';

    protected $description = 'Crawl a site for articles using either sitemap or html parser';

    private array $batchArticles = [];

    public function handle()
    {
        $siteId = $this->argument('site_id');
        $maxPages = (int) $this->option('max-pages');

        $site = Site::find($siteId);
        if (! $site) {
            $this->error("Site ID {$siteId} not found.");
            Log::warning("CrawlSiteCommand: Site ID {$siteId} not found.");

            return 1;
        }

        $this->info("Starting crawl for site: {$site->name} ({$site->crawler_type} mode)");
        Log::info("CrawlSiteCommand: Starting crawl for Site ID: {$site->id} ({$site->crawler_type} mode)");

        if ($site->crawler_type === 'sitemap') {
            $this->crawlSitemap($site);
        } else {
            $this->crawlHtml($site, $maxPages);
        }

        $this->info('Crawl completed. Dispatching batches...');
        Log::info("CrawlSiteCommand: Crawl completed for Site ID: {$site->id}. Dispatching batches.");

        if (! empty($this->batchArticles)) {
            $chunks = array_chunk($this->batchArticles, 10);
            foreach ($chunks as $chunk) {
                ProcessArticleBatchJob::dispatch($site->id, $chunk, 'rss');
            }
            $this->info('Dispatched '.count($chunks).' batch jobs.');
            Log::info('CrawlSiteCommand: Dispatched '.count($chunks)." batch jobs for Site ID: {$site->id}.");
        }

        return 0;
    }

    protected function crawlSitemap(Site $site): void
    {
        $feedUrl = $site->rss_url ?? $site->sitemap_url ?? $site->url;
        if (empty($feedUrl)) {
            $this->error('RSS/Atom フィードのURLが設定されていません。');

            return;
        }

        $this->info("フィード取得中: {$feedUrl}");

        try {
            libxml_use_internal_errors(true);

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                'Accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
            ])->timeout(30)->get($feedUrl);

            if (! $response->successful()) {
                if (is_null($site->failing_since)) {
                    $site->update(['failing_since' => now()]);
                }
                $this->error('フィード取得失敗: HTTP '.$response->status());

                return;
            }

            $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
            libxml_clear_errors();

            if ($xml === false) {
                if (is_null($site->failing_since)) {
                    $site->update(['failing_since' => now()]);
                }
                $this->error('XMLのパースに失敗しました。');

                return;
            }

            if (! is_null($site->failing_since)) {
                $site->update(['failing_since' => null]);
            }

            // RSS 1.0 (RDF) / RSS 2.0 / Atom のフォーマット差異を local-name() で吸収
            $entries = $xml->xpath('//*[local-name()="item"] | //*[local-name()="entry"]') ?: [];
            $isSitemap = false;

            // エントリが空の場合は sitemap.xml（<loc> タグのみ）として再試行
            if (empty($entries)) {
                $entries = $xml->xpath('//*[local-name()="loc"]') ?: [];
                if (empty($entries)) {
                    $this->warn('フィード内に記事エントリが見つかりませんでした。');

                    return;
                }
                $isSitemap = true;
                $this->info('sitemap.xml 形式として処理します。');
            }

            $totalEntries = count($entries);
            $this->info("{$totalEntries} 件のエントリを検出しました。重複チェックを行います。");

            // ── パス1: 全URLを先に抽出 ───────────────────────────────────────────
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

            // ── sitemapindex 展開: .xml URL を子サイトマップとして記事URLを収集 ────
            if ($isSitemap) {
                $articleUrls = [];
                $sitemapUrls = [];

                // 記事URLと子サイトマップURLに振り分け
                foreach ($allUrls as $u) {
                    $path = parse_url($u, PHP_URL_PATH) ?? '';
                    if (str_ends_with($path, '.xml')) {
                        $sitemapUrls[] = $u;
                    } else {
                        $articleUrls[] = $u;
                    }
                }

                // 子サイトマップは最大3件展開
                $sitemapUrls = array_slice($sitemapUrls, 0, 3);
                foreach ($sitemapUrls as $sitemapUrl) {
                    $this->info("子サイトマップを展開: {$sitemapUrl}");
                    try {
                        $childResponse = Http::withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                            'Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.8',
                            'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                        ])->timeout(15)->get($sitemapUrl);

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
                        $this->warn("子サイトマップ展開エラー ({$sitemapUrl}): ".$e->getMessage());
                    }
                }

                // 重複排除して $allUrls を上書き
                $allUrls = array_values(array_unique($articleUrls));
                $this->info(count($allUrls).' 件の記事URLを収集しました。');
            }

            // ── バルク重複チェック ───────────────────────────────────────────────
            $existingUrls = Article::whereIn('url', $allUrls)->pluck('url')->toArray();
            $this->info(count($existingUrls).' 件は既存記事のためスキップします。');

            // ── パス2: 新規記事のみ処理 ──────────────────────────────────────────
            $count = 0;

            if ($isSitemap) {
                // sitemap 系は $allUrls を直接ループ（メタデータはスクレイピング補完）
                foreach ($allUrls as $url) {
                    if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                        continue;
                    }
                    if (in_array($url, $existingUrls, true)) {
                        continue;
                    }

                    $this->processArticle($site, [
                        'url' => $url,
                        'title' => null,
                        'thumbnail' => null,
                        'published_at' => null,
                    ]);

                    $count++;
                }
            } else {
                // RSS / Atom は $entries をループしてメタデータも抽出
                foreach ($entries as $entry) {
                    // ── URL ──────────────────────────────────────────────
                    $url = (string) $entry->link;
                    if (! $url && isset($entry->link['href'])) {
                        $url = (string) $entry->link['href']; // Atom対応
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

                    // ── タイトル ─────────────────────────────────────────
                    $titleStr = (string) $entry->title;
                    $title = $titleStr !== '' ? trim($titleStr) : null;

                    // ── 公開日 ───────────────────────────────────────────
                    $publishedAtRaw = (string) $entry->pubDate
                        ?: (string) $entry->children('dc', true)->date
                        ?: (string) $entry->updated
                        ?: (string) $entry->published
                        ?: (string) $entry->date;

                    $publishedAt = null;
                    if ($publishedAtRaw) {
                        try {
                            $publishedAt = Carbon::parse($publishedAtRaw)->toDateTimeString();
                        } catch (Exception $e) {
                            $publishedAt = null;
                        }
                    }

                    // ── サムネイル ───────────────────────────────────────
                    $thumbnail = null;
                    if (isset($entry->enclosure) && isset($entry->enclosure['url'])) {
                        $thumbnail = (string) $entry->enclosure['url'];
                    } elseif ($entry->children('media', true)->content && isset($entry->children('media', true)->content->attributes()->url)) {
                        $thumbnail = (string) $entry->children('media', true)->content->attributes()->url;
                    } elseif ($entry->children('media', true)->thumbnail && isset($entry->children('media', true)->thumbnail->attributes()->url)) {
                        $thumbnail = (string) $entry->children('media', true)->thumbnail->attributes()->url;
                    }

                    // 本文から探すフォールバック
                    if (! $thumbnail) {
                        $content = (string) $entry->children('content', true)->encoded ?: (string) $entry->description;
                        if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
                            $thumbnail = $matches[1];
                        }
                    }

                    $this->processArticle($site, [
                        'url' => $url,
                        'title' => $title,
                        'thumbnail' => $thumbnail,
                        'published_at' => $publishedAt ? Carbon::parse($publishedAt) : null,
                    ]);

                    $count++;
                }
            }

            $this->info("{$count} 件の新規記事をキューに投入しました。");

        } catch (Exception $e) {
            if (is_null($site->failing_since)) {
                $site->update(['failing_since' => now()]);
            }
            $this->error('フィード解析エラー: '.$e->getMessage());
        }
    }

    protected function crawlHtml(Site $site, int $maxPages): void
    {
        if (empty($site->crawl_start_url) && empty($site->url)) {
            $this->error('Start URL is missing for HTML crawl.');

            return;
        }

        if (empty($site->list_item_selector) && empty($site->link_selector)) {
            $this->error('Both List Item Selector and Link Selector are missing for HTML crawl.');

            return;
        }

        $baseUrl = rtrim(preg_replace('/\/page\/\d+$/i', '', $site->crawl_start_url ?? $site->url), '/');

        for ($page = 1; $page <= $maxPages; $page++) {
            // ページURLをCSSセレクタに頼らず算数的に生成
            if (! empty($site->pagination_url_template)) {
                $currentUrl = str_replace('{page}', $page, $site->pagination_url_template);
            } else {
                $currentUrl = $page === 1 ? ($site->crawl_start_url ?? $site->url) : $baseUrl.'/page/'.$page;
            }

            $this->info("Fetching page {$page}: {$currentUrl}");

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                ])->timeout(30)->get($currentUrl);

                if (! $response->successful()) {
                    if ($page === 1 && is_null($site->failing_since)) {
                        $site->update(['failing_since' => now()]);
                    }
                    $this->error('Failed to fetch page. HTTP '.$response->status());
                    break;
                }

                if ($page === 1 && ! is_null($site->failing_since)) {
                    $site->update(['failing_since' => null]);
                }

                $crawler = new Crawler($response->body(), $currentUrl);

                if (empty($site->list_item_selector)) {
                    $items = $crawler->filter($site->link_selector);
                } else {
                    $items = $crawler->filter($site->list_item_selector);
                }

                if ($items->count() === 0) {
                    $this->info('No items found on this page. Stopping.');
                    break;
                }

                $items->each(function (Crawler $node) use ($site) {
                    try {
                        // URL
                        $url = null;
                        if (empty($site->list_item_selector)) {
                            if ($node->nodeName() === 'a') {
                                $url = $node->link()->getUri();
                            } elseif ($node->filter('a')->count() > 0) {
                                $url = $node->filter('a')->first()->link()->getUri();
                            }
                        } else {
                            if ($site->link_selector && $node->filter($site->link_selector)->count() > 0) {
                                $url = $node->filter($site->link_selector)->first()->link()->getUri();
                            } elseif ($node->nodeName() === 'a') {
                                $url = $node->link()->getUri();
                            } elseif ($node->filter('a')->count() > 0) {
                                $url = $node->filter('a')->first()->link()->getUri();
                            }
                        }

                        if (! $url) {
                            return;
                        }

                        $this->processArticle($site, [
                            'url' => $url,
                            'title' => null,
                            'thumbnail' => null,
                            'published_at' => null,
                        ]);

                    } catch (Exception $e) {
                        $this->warn('Error parsing an item: '.$e->getMessage());
                    }
                });

                $this->info('Waiting 2 seconds before next page...');
                sleep(2);

            } catch (Exception $e) {
                if ($page === 1 && is_null($site->failing_since)) {
                    $site->update(['failing_since' => now()]);
                }
                $this->error('Error crawling HTML: '.$e->getMessage());
                break;
            }
        }
    }

    protected function processArticle(Site $site, array $data)
    {
        $url = $data['url'];

        if (empty($url)) {
            return;
        }

        // 1. Required substring check
        $requiredSubstring = null;
        if (! empty($site->link_selector) && preg_match('/href\*?=[\'"]([^\'"]+)[\'"]/', $site->link_selector, $matches)) {
            $requiredSubstring = $matches[1];
        }

        if ($requiredSubstring && ! str_contains($url, $requiredSubstring)) {
            return;
        }

        // 2. Reject root/start URL and pagination
        $siteUrl = rtrim($site->url, '/');
        $startUrl = rtrim($site->crawl_start_url ?? '', '/');
        $cleanUrl = rtrim($url, '/');

        if ($cleanUrl === $siteUrl || ($startUrl !== '' && $cleanUrl === $startUrl)) {
            return;
        }

        if (str_contains($url, '/page/') || str_contains($url, '?page=')) {
            return;
        }

        // 3. NG Keywords check
        $ngKeywords = $site->ng_url_keywords ?? [];
        foreach ($ngKeywords as $ng) {
            if ($ng !== '' && str_contains($url, $ng)) {
                $this->line("Skipped by NG word: {$url}");

                return;
            }
        }

        $exists = Article::where('url', $url)->exists();

        if ($exists) {
            $this->line("Skipped existing: {$url}");

            return;
        }

        $this->info("Queuing new article for batch: {$url}");
        Log::info("CrawlSiteCommand: Queuing article for batch: {$url} (Site ID: {$site->id})");

        $this->batchArticles[] = [
            'url' => $url,
            'metaData' => [
                'raw_title' => $data['title'],
                'thumbnail_url' => $data['thumbnail'],
                'published_at' => $data['published_at']?->toDateTimeString(),
            ],
        ];
    }
}
