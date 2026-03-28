<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Site;
use App\Models\Article;
use App\Jobs\ProcessArticleJob;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Exception;

class CrawlSiteCommand extends Command
{
    protected $signature = 'app:crawl-site {site_id} {--max-pages=5}';

    protected $description = 'Crawl a site for articles using either sitemap or html parser';

    public function handle()
    {
        $siteId = $this->argument('site_id');
        $maxPages = (int) $this->option('max-pages');

        $site = Site::find($siteId);
        if (!$site) {
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

    protected function crawlSitemap(Site $site)
    {
        if (empty($site->sitemap_url)) {
            $this->error('Sitemap URL is not set for this site.');
            return;
        }

        $this->info("Fetching sitemap: {$site->sitemap_url}");
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ])->timeout(30)->get($site->sitemap_url);
            
            if (!$response->successful()) {
                $this->error('Failed to fetch sitemap: HTTP ' . $response->status());
                return;
            }

            $xml = simplexml_load_string($response->body());
            if ($xml === false) {
                $this->error('Failed to parse XML sitemap.');
                return;
            }

            if (isset($xml->sitemap)) {
                $sitemaps = $xml->sitemap;
                $lastSitemapUrl = (string)$sitemaps[count($sitemaps) - 1]->loc;
                
                $this->info("Detected sitemap index. Fetching sub-sitemap: {$lastSitemapUrl}");
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ])->timeout(30)->get($lastSitemapUrl);
                
                if (!$response->successful()) {
                    $this->error('Failed to fetch sub-sitemap: HTTP ' . $response->status());
                    return;
                }
                
                $xml = simplexml_load_string($response->body());
                if ($xml === false) {
                    $this->error('Failed to parse sub-sitemap XML.');
                    return;
                }
            }

            $urls = $xml->url ?? [];
            if (empty($urls)) {
                $this->error('No <url> entries found in the sitemap.');
                return;
            }

            $count = 0;
            foreach ($urls as $urlEntry) {
                $url = (string)$urlEntry->loc;
                $dateStr = (string)($urlEntry->lastmod ?? $urlEntry->pubDate ?? '');
                
                $publishedAt = null;
                if (!empty($dateStr)) {
                    try {
                        $publishedAt = Carbon::parse($dateStr);
                    } catch (Exception $e) {}
                }



                $this->processArticle($site, [
                    'url' => $url,
                    'title' => null,
                    'thumbnail' => null,
                    'published_at' => $publishedAt
                ]);
                $count++;
            }

            $this->info("Processed {$count} URLs from sitemap.");

        } catch (Exception $e) {
            $this->error('Error parsing sitemap: ' . $e->getMessage());
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
            if (!empty($site->pagination_url_template)) {
                $currentUrl = str_replace('{page}', $page, $site->pagination_url_template);
            } else {
                $currentUrl = $page === 1 ? ($site->crawl_start_url ?? $site->url) : $baseUrl . '/page/' . $page;
            }

            $this->info("Fetching page {$page}: {$currentUrl}");

            try {
                $response = Http::withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                ])->timeout(30)->get($currentUrl);

                if (!$response->successful()) {
                    $this->error("Failed to fetch page. HTTP " . $response->status());
                    break;
                }

                $crawler = new Crawler($response->body(), $currentUrl);

                if (empty($site->list_item_selector)) {
                    $items = $crawler->filter($site->link_selector);
                } else {
                    $items = $crawler->filter($site->list_item_selector);
                }

                if ($items->count() === 0) {
                    $this->info("No items found on this page. Stopping.");
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

                        if (!$url) {
                            return;
                        }

                        $this->processArticle($site, [
                            'url'          => $url,
                            'title'        => null,
                            'thumbnail'    => null,
                            'published_at' => null,
                        ]);

                    } catch (Exception $e) {
                        $this->warn("Error parsing an item: " . $e->getMessage());
                    }
                });

                $this->info("Waiting 2 seconds before next page...");
                sleep(2);

            } catch (Exception $e) {
                $this->error("Error crawling HTML: " . $e->getMessage());
                break;
            }
        }
    }

    protected function processArticle(Site $site, array $data)
    {
        $url = $data['url'];
        
        if (empty($url)) return;

        // 1. Required substring check
        $requiredSubstring = null;
        if (!empty($site->link_selector) && preg_match('/href\*?=[\'"]([^\'"]+)[\'"]/', $site->link_selector, $matches)) {
            $requiredSubstring = $matches[1];
        }

        if ($requiredSubstring && !str_contains($url, $requiredSubstring)) {
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
        ProcessArticleJob::dispatch($site, $url, [
            'raw_title' => $data['title'],
            'thumbnail_url' => $data['thumbnail'],
            'published_at' => $data['published_at']?->toDateTimeString()
        ]);
    }
}
