<?php

namespace App\Console\Commands;

use App\Jobs\ProcessArticleJob;
use App\Models\Article;
use App\Models\Site;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class CrawlSiteCommand extends Command
{
    protected $signature = 'app:crawl-site {site_id} {--max-pages=5}';

    protected $description = 'Crawl a site for articles using either sitemap or html parser';

    public function handle()
    {
        $siteId = $this->argument('site_id');
        $maxPages = (int) $this->option('max-pages');

        $site = Site::find($siteId);
        if (! $site) {
            $this->error("Site ID {$siteId} not found.");

            return 1;
        }

        $this->info("Starting crawl for site: {$site->name} ({$site->crawler_type} mode)");

        if ($site->crawler_type === 'sitemap') {
            $this->crawlSitemap($site);
        } else {
            $this->crawlHtml($site, $maxPages);
        }

        $this->info('Crawl completed.');

        return 0;
    }

    protected function crawlSitemap(Site $site): void
    {
        $feedUrl = $site->sitemap_url ?? $site->url;
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
                $this->error('フィード取得失敗: HTTP '.$response->status());

                return;
            }

            $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
            libxml_clear_errors();

            if ($xml === false) {
                $this->error('XMLのパースに失敗しました。');

                return;
            }

            // RSS 1.0 (RDF) / RSS 2.0 / Atom のフォーマット差異を local-name() で吸収
            $entries = $xml->xpath('//*[local-name()="item"] | //*[local-name()="entry"]') ?: [];

            if (empty($entries)) {
                $this->warn('フィード内に記事エントリが見つかりませんでした。');

                return;
            }

            $this->info(count($entries).' 件のエントリを検出しました（最大20件を処理）');

            $count = 0;
            foreach ($entries as $entry) {
                if ($count >= 20) {
                    break;
                }

                // ── URL ──────────────────────────────────────────────
                $url = null;
                $links = $entry->xpath('*[local-name()="link"]') ?: [];
                if (! empty($links)) {
                    $linkNode = $links[0];
                    $linkText = trim((string) $linkNode);
                    if ($linkText !== '') {
                        $url = $linkText; // <link>URL</link>
                    } elseif (isset($linkNode['href'])) {
                        $url = trim((string) $linkNode['href']); // <link href="URL"/>
                    }
                }
                if (empty($url)) {
                    $guids = $entry->xpath('*[local-name()="guid"] | *[local-name()="id"]') ?: [];
                    if (! empty($guids) && filter_var((string) $guids[0], FILTER_VALIDATE_URL)) {
                        $url = trim((string) $guids[0]);
                    }
                }
                if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                    continue;
                }

                // ── タイトル ─────────────────────────────────────────
                $titleNodes = $entry->xpath('*[local-name()="title"]') ?: [];
                $title = ! empty($titleNodes) ? trim((string) $titleNodes[0]) : null;

                // ── 公開日 ───────────────────────────────────────────
                $publishedAt = null;
                $dateFields = ['pubDate', 'date', 'updated', 'lastmod', 'published'];
                foreach ($dateFields as $field) {
                    $dateNodes = $entry->xpath("*[local-name()=\"{$field}\"]") ?: [];
                    if (! empty($dateNodes)) {
                        $dateStr = trim((string) $dateNodes[0]);
                        if ($dateStr !== '') {
                            try {
                                $publishedAt = Carbon::parse($dateStr)->toDateTimeString();
                            } catch (Exception $e) {
                                $publishedAt = null;
                            }
                            break;
                        }
                    }
                }

                // ── サムネイル ───────────────────────────────────────
                $thumbnail = null;

                // ① enclosure / thumbnail / media:content などの url 属性
                $mediaNodes = $entry->xpath(
                    '*[local-name()="enclosure"] | *[local-name()="thumbnail"] | *[local-name()="content"]'
                ) ?: [];
                foreach ($mediaNodes as $mediaNode) {
                    if (isset($mediaNode['url']) && ! empty((string) $mediaNode['url'])) {
                        $thumbnail = trim((string) $mediaNode['url']);
                        break;
                    }
                }

                // ② encoded / description / content テキスト内の <img src="..."> から正規表現で抽出
                if (empty($thumbnail)) {
                    $textNodes = $entry->xpath(
                        '*[local-name()="encoded"] | *[local-name()="description"] | *[local-name()="content"]'
                    ) ?: [];
                    foreach ($textNodes as $textNode) {
                        $html = (string) $textNode;
                        if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $imgMatches)) {
                            $thumbnail = $imgMatches[1];
                            break;
                        }
                    }
                }

                $this->processArticle($site, [
                    'url' => $url,
                    'title' => $title ?: null,
                    'thumbnail' => $thumbnail,
                    'published_at' => $publishedAt ? Carbon::parse($publishedAt) : null,
                ]);

                $count++;
            }

            $this->info("{$count} 件の記事をキューに投入しました。");

        } catch (Exception $e) {
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
                    $this->error('Failed to fetch page. HTTP '.$response->status());
                    break;
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

        $this->info("Dispatching new article: {$url}");

        // Here we dispatch the job to handle Crawl4AI and AI categorization
        ProcessArticleJob::dispatch($site->id, $url, [
            'raw_title' => $data['title'],
            'thumbnail_url' => $data['thumbnail'],
            'published_at' => $data['published_at']?->toDateTimeString(),
        ], 'gemini', 'RSS定期取得');
    }
}
