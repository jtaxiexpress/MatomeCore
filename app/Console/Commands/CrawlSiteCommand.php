<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SendArticleFetchResultNotificationAction;
use App\Jobs\ProcessArticleBatchJob;
use App\Models\Article;
use App\Models\Site;
use App\Services\Crawlers\CrawlerStrategy;
use App\Services\Crawlers\HtmlCrawler;
use App\Services\Crawlers\SitemapCrawler;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CrawlSiteCommand extends Command
{
    protected $signature = 'app:crawl-site {site_id} {--max-pages=5}';

    protected $description = 'Crawl a site for articles using either sitemap or html parser';

    private array $batchArticles = [];

    public function __construct(
        private readonly SitemapCrawler $sitemapCrawler,
        private readonly HtmlCrawler $htmlCrawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
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

        $articles = $this->resolveCrawlerStrategy($site)->crawl($site, $maxPages);

        foreach ($articles as $article) {
            $this->processArticle($site, $article);
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
        } else {
            app(SendArticleFetchResultNotificationAction::class)->execute(
                site: $site,
                fetchSource: 'rss',
                savedCount: 0,
                missedCount: 0,
                detail: 'RSS新規記事はありませんでした。',
            );
        }

        return 0;
    }

    private function resolveCrawlerStrategy(Site $site): CrawlerStrategy
    {
        if ($site->crawler_type === 'sitemap') {
            return $this->sitemapCrawler;
        }

        return $this->htmlCrawler;
    }

    /**
     * @param  array{url: string, title?: string|null, thumbnail?: string|null, published_at?: mixed}  $data
     */
    protected function processArticle(Site $site, array $data): void
    {
        $url = (string) ($data['url'] ?? '');

        if ($url === '') {
            return;
        }

        $requiredSubstring = null;
        if (! empty($site->link_selector) && preg_match('/href\*?=[\'\"]([^\'\"]+)[\'\"]/', $site->link_selector, $matches)) {
            $requiredSubstring = $matches[1];
        }

        if ($requiredSubstring && ! str_contains($url, $requiredSubstring)) {
            return;
        }

        $siteUrl = rtrim((string) $site->url, '/');
        $startUrl = rtrim((string) ($site->crawl_start_url ?? ''), '/');
        $cleanUrl = rtrim($url, '/');

        if ($cleanUrl === $siteUrl || ($startUrl !== '' && $cleanUrl === $startUrl)) {
            return;
        }

        if (str_contains($url, '/page/') || str_contains($url, '?page=')) {
            return;
        }

        $ngKeywords = $site->ng_url_keywords ?? [];
        foreach ($ngKeywords as $ng) {
            if ($ng !== '' && str_contains($url, (string) $ng)) {
                $this->line("Skipped by NG word: {$url}");

                return;
            }
        }

        $exists = Article::where('url', $url)->exists();

        if ($exists) {
            $this->line("Skipped existing: {$url}");

            return;
        }

        $publishedAt = $data['published_at'] ?? null;
        $publishedAtString = null;

        if ($publishedAt instanceof CarbonInterface) {
            $publishedAtString = $publishedAt->toDateTimeString();
        } elseif ($publishedAt instanceof \DateTimeInterface) {
            $publishedAtString = $publishedAt->format('Y-m-d H:i:s');
        } elseif (is_string($publishedAt)) {
            $publishedAtString = $publishedAt;
        }

        $this->info("Queuing new article for batch: {$url}");
        Log::info("CrawlSiteCommand: Queuing article for batch: {$url} (Site ID: {$site->id})");

        $this->batchArticles[] = [
            'url' => $url,
            'metaData' => [
                'raw_title' => isset($data['title']) && is_string($data['title']) ? $data['title'] : null,
                'thumbnail_url' => isset($data['thumbnail']) && is_string($data['thumbnail']) ? $data['thumbnail'] : null,
                'published_at' => $publishedAtString,
            ],
        ];
    }
}
