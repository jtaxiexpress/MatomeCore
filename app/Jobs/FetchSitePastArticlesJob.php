<?php

namespace App\Jobs;

use App\Actions\SendArticleFetchResultNotificationAction;
use App\Models\Article;
use App\Models\Site;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class FetchSitePastArticlesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public ?string $output = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Site $site,
        public int $limit = 10
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): string
    {
        $this->site->loadMissing('app');
        $this->shareLogContext();

        Log::info("{$this->site->name} の過去記事一括取得処理を開始しました");

        try {
            $url = $this->site->url;
            if (empty($url)) {
                throw new Exception('サイトURLが設定されていません。');
            }

            $dispatchedCount = 0;
            $sourceType = $this->site->crawler_type === 'sitemap' ? 'fetch_past_sitemap' : 'fetch_past_html';
            $targetUrls = [];

            if ($this->site->crawler_type === 'sitemap') {
                try {
                    $targetUrls = $this->collectUrlsFromSitemap();
                } catch (Exception $e) {
                    Log::warning("{$this->site->name} - サイトマップ取得に失敗したためHTML抽出へフォールバックします", [
                        'message' => $e->getMessage(),
                    ]);
                }

                if ($targetUrls === []) {
                    $sourceType = 'fetch_past_html';
                    Log::info("{$this->site->name} - サイトマップで記事URLを取得できなかったため、一覧ページ抽出へ切り替えます。");
                    $targetUrls = $this->collectUrlsFromHtmlPages($this->limit);
                } elseif ($this->limit > 0) {
                    $targetUrls = array_slice($targetUrls, 0, $this->limit);
                }
            } else {
                $targetUrls = $this->collectUrlsFromHtmlPages($this->limit);
            }

            $dispatchedCount = count($targetUrls);

            if ($targetUrls !== []) {
                $this->dispatchArticleBatches($targetUrls, $sourceType);
            }

            if ($this->limit > 0 && $dispatchedCount >= $this->limit) {
                Log::info("[Fetch: {$this->site->name}] 指定された上限（{$this->limit}件）に到達したため終了します。");
            }

            $limitLog = $this->limit === 0 ? 'なし' : $this->limit.'件';
            $successMessage = "[Scraper] {$this->site->name} から新しい記事を {$dispatchedCount} 件取得し、バッチキューに投入しました（上限設定: {$limitLog}）";
            Log::info($successMessage);
            $this->output = $successMessage;

            if ($dispatchedCount === 0) {
                app(SendArticleFetchResultNotificationAction::class)->execute(
                    site: $this->site,
                    fetchSource: $sourceType,
                    savedCount: 0,
                    missedCount: 0,
                    detail: '新規記事はありませんでした。',
                );
            }

            return $successMessage;

        } catch (Exception $e) {
            $errorMessage = "失敗: {$this->site->name} の過去記事一括取得処理中にエラーが発生しました: ".$e->getMessage();
            Log::error($errorMessage."\n".$e->getTraceAsString());
            $this->output = $errorMessage;

            app(SendArticleFetchResultNotificationAction::class)->execute(
                site: $this->site,
                fetchSource: $this->site->crawler_type === 'sitemap' ? 'fetch_past_sitemap' : 'fetch_past_html',
                savedCount: 0,
                missedCount: 0,
                detail: '過去記事一括取得の処理中にエラーが発生しました: '.$e->getMessage(),
                failed: true,
            );

            return $errorMessage;
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectUrlsFromSitemap(): array
    {
        $xmlUrl = $this->site->sitemap_url ?? $this->site->rss_url ?? $this->site->url;

        if (empty($xmlUrl)) {
            throw new Exception('サイトマップ/RSSのURLが設定されていません。');
        }

        Log::info("{$this->site->name} - XML取得中: {$xmlUrl}");

        $xmlResponse = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
        ])->timeout(15)->get($xmlUrl);

        if (! $xmlResponse->successful()) {
            throw new Exception('XML取得に失敗しました (HTTP '.$xmlResponse->status()."): {$xmlUrl}");
        }

        $xml = @simplexml_load_string($xmlResponse->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            throw new Exception("{$this->site->name} - XMLのパースに失敗しました");
        }

        $extractedUrls = [];

        if (isset($xml->sitemap)) {
            Log::info("{$this->site->name} - sitemapindex を検出。子サイトマップを巡回します");
            foreach ($xml->sitemap as $childSitemap) {
                $childLoc = trim((string) ($childSitemap->loc ?? ''));

                if (empty($childLoc) || ! filter_var($childLoc, FILTER_VALIDATE_URL)) {
                    continue;
                }

                Log::info("{$this->site->name} - 子サイトマップ取得中: {$childLoc}");
                $childResponse = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                ])->timeout(15)->get($childLoc);

                if (! $childResponse->successful()) {
                    Log::warning("{$this->site->name} - 子サイトマップ取得失敗 (HTTP {$childResponse->status()}): {$childLoc}");

                    continue;
                }

                $extractedUrls = array_merge($extractedUrls, $this->extractUrlsFromXml($childResponse->body()));
            }
        } else {
            $extractedUrls = $this->extractUrlsFromXml($xmlResponse->body());
        }

        $extractedUrls = $this->filterCandidateUrlsForSite($extractedUrls, false);

        Log::info("{$this->site->name} - XMLから ".count($extractedUrls).' 件のURLを抽出しました');

        if ($extractedUrls === []) {
            return [];
        }

        $existingUrls = $this->pluckExistingUrlsChunked($extractedUrls);
        $newUrls = array_values(array_filter($extractedUrls, fn (string $candidateUrl): bool => ! in_array($candidateUrl, $existingUrls, true)));

        Log::info("[Fetch: {$this->site->name}] 新規URL: ".count($newUrls).'件 / 重複スキップ: '.(count($extractedUrls) - count($newUrls)).'件');

        return $newUrls;
    }

    /**
     * @return array<int, string>
     */
    private function collectUrlsFromHtmlPages(int $limit): array
    {
        $maxPages = 100;
        $collectedHtmlUrls = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $targetUrl = $this->buildPaginationTargetUrl($page);
            Log::info("{$this->site->name} - ページ {$page} をクロール中: {$targetUrl}");

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
            ])->timeout(10)->get($targetUrl);

            if (! $response->successful()) {
                Log::warning("{$this->site->name} - ページ {$page} の取得に失敗しました (HTTP ".$response->status().')');
                break;
            }

            $crawler = new Crawler($response->body(), $targetUrl);
            $pageUrls = $this->extractArticleUrlsFromCrawler($crawler);
            $cleanedPageUrls = $this->filterCandidateUrlsForSite($pageUrls, true);

            if ($cleanedPageUrls === []) {
                Log::info("[Fetch: {$this->site->name}] ページ {$page} から記事URLを抽出できませんでした。");

                continue;
            }

            $existingUrls = $this->pluckExistingUrlsChunked($cleanedPageUrls);
            $newUrls = array_values(array_filter(
                $cleanedPageUrls,
                fn (string $candidateUrl): bool => ! in_array($candidateUrl, $existingUrls, true)
                    && ! in_array($candidateUrl, $collectedHtmlUrls, true)
            ));
            $skippedCount = count($cleanedPageUrls) - count($newUrls);

            Log::info("[Fetch: {$this->site->name}] ページ {$page} を解析中... (新規: ".count($newUrls)."件 / 重複スキップ: {$skippedCount}件)");

            if ($newUrls === []) {
                Log::info("[Fetch: {$this->site->name}] 全てのURLが重複だったためスキップしました（Page {$page} で探索終了）");
                break;
            }

            foreach ($newUrls as $newUrl) {
                $collectedHtmlUrls[] = $newUrl;

                if ($limit > 0 && count($collectedHtmlUrls) >= $limit) {
                    Log::info("[Fetch: {$this->site->name}] 指定された上限（{$limit}件）の新規記事を獲得したため、探索を終了します。");

                    break 2;
                }
            }
        }

        return $collectedHtmlUrls;
    }

    /**
     * @param  array<int, string>  $targetUrls
     */
    private function dispatchArticleBatches(array $targetUrls, string $sourceType): void
    {
        $articlesBatch = array_map(fn (string $articleUrl): array => ['url' => $articleUrl, 'metaData' => []], $targetUrls);

        foreach (array_chunk($articlesBatch, 10) as $chunk) {
            ProcessArticleBatchJob::dispatch($this->site->id, $chunk, $sourceType);
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractArticleUrlsFromCrawler(Crawler $crawler): array
    {
        $listItemSelector = trim((string) ($this->site->list_item_selector ?? ''));
        $linkSelector = trim((string) ($this->site->link_selector ?? ''));

        if ($listItemSelector !== '') {
            try {
                return $this->extractUrlsFromListSelector($crawler, $listItemSelector, $linkSelector);
            } catch (Exception $e) {
                Log::warning("{$this->site->name} - list_item_selector での抽出に失敗しました", [
                    'selector' => $listItemSelector,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($linkSelector !== '') {
            try {
                return $this->extractUrlsFromLinkSelector($crawler, $linkSelector);
            } catch (Exception $e) {
                Log::warning("{$this->site->name} - link_selector での抽出に失敗しました", [
                    'selector' => $linkSelector,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->extractUrlsFromFallbackSelectors($crawler);
    }

    /**
     * @return array<int, string>
     */
    private function extractUrlsFromListSelector(Crawler $crawler, string $listItemSelector, string $linkSelector): array
    {
        $urls = [];
        $items = $crawler->filter($listItemSelector);

        $items->each(function (Crawler $node) use (&$urls, $linkSelector): void {
            try {
                $linkUrl = null;

                if ($linkSelector !== '' && $node->filter($linkSelector)->count() > 0) {
                    $linkUrl = $node->filter($linkSelector)->first()->link()->getUri();
                } elseif ($node->nodeName() === 'a') {
                    $linkUrl = $node->link()->getUri();
                } elseif ($node->filter('a')->count() > 0) {
                    $linkUrl = $node->filter('a')->first()->link()->getUri();
                }

                if (is_string($linkUrl) && $linkUrl !== '') {
                    $urls[] = $linkUrl;
                }
            } catch (Exception) {
                // ignore node parse error
            }
        });

        return array_values(array_unique($urls));
    }

    /**
     * @return array<int, string>
     */
    private function extractUrlsFromLinkSelector(Crawler $crawler, string $linkSelector): array
    {
        $urls = [];
        $links = $crawler->filter($linkSelector);

        $links->each(function (Crawler $node) use (&$urls): void {
            try {
                $linkUrl = null;

                if ($node->nodeName() === 'a') {
                    $linkUrl = $node->link()->getUri();
                } elseif ($node->filter('a')->count() > 0) {
                    $linkUrl = $node->filter('a')->first()->link()->getUri();
                }

                if (is_string($linkUrl) && $linkUrl !== '') {
                    $urls[] = $linkUrl;
                }
            } catch (Exception) {
                // ignore node parse error
            }
        });

        return array_values(array_unique($urls));
    }

    /**
     * @return array<int, string>
     */
    private function extractUrlsFromFallbackSelectors(Crawler $crawler): array
    {
        $selectors = ['article a', 'h2 a', '.entry-title a', '.post-title a', '.list-item a', 'main a'];

        foreach ($selectors as $selector) {
            $urls = [];

            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $crawler->filter($selector)->each(function (Crawler $node) use (&$urls): void {
                try {
                    $linkUrl = $node->link()->getUri();

                    if ($linkUrl !== '') {
                        $urls[] = $linkUrl;
                    }
                } catch (Exception) {
                    // ignore node parse error
                }
            });

            if ($urls !== []) {
                return array_values(array_unique($urls));
            }
        }

        return [];
    }

    private function buildPaginationTargetUrl(int $page): string
    {
        if (! empty($this->site->pagination_url_template)) {
            return str_replace('{page}', (string) $page, $this->site->pagination_url_template);
        }

        $startUrl = (string) ($this->site->crawl_start_url ?: $this->site->url);
        $baseUrl = rtrim((string) preg_replace('/\/page\/\d+$/i', '', $startUrl), '/');

        return $page === 1 ? $baseUrl : $baseUrl.'/page/'.$page;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function filterCandidateUrlsForSite(array $urls, bool $enforceHost): array
    {
        $siteHost = (string) parse_url($this->site->url, PHP_URL_HOST);
        $normalizedSiteUrl = rtrim((string) $this->site->url, '/');
        $normalizedStartUrl = rtrim((string) ($this->site->crawl_start_url ?? ''), '/');
        $ngWords = is_array($this->site->ng_url_keywords) ? $this->site->ng_url_keywords : [];

        return collect($urls)
            ->map(static fn (string $url): string => explode('#', $url)[0])
            ->filter(static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->filter(function (string $url) use ($siteHost, $enforceHost): bool {
                if (! $enforceHost) {
                    return true;
                }

                $candidateHost = (string) parse_url($url, PHP_URL_HOST);

                return $candidateHost === $siteHost;
            })
            ->reject(function (string $url) use ($normalizedSiteUrl, $normalizedStartUrl): bool {
                $cleanUrl = rtrim($url, '/');

                if ($cleanUrl === $normalizedSiteUrl || ($normalizedStartUrl !== '' && $cleanUrl === $normalizedStartUrl)) {
                    Log::info("[Fetch: {$this->site->name}] トップページURLのため除外: {$url}");

                    return true;
                }

                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($path === '' || $path === '/') {
                    Log::info("[Fetch: {$this->site->name}] パスが存在しないため除外: {$url}");

                    return true;
                }

                if (str_contains($url, '/page/') || str_contains($url, '?page=')) {
                    return true;
                }

                return false;
            })
            ->reject(function (string $url) use ($ngWords): bool {
                foreach ($ngWords as $word) {
                    if (! empty($word) && str_contains($url, (string) $word)) {
                        Log::info("[Fetch: {$this->site->name}] NGワード({$word})を含むため除外: {$url}");

                        return true;
                    }
                }

                return false;
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * XML文字列からURLを抽出する。
     * サイトマップ (<url><loc>)、RSS (<item><link>)、Atom (<entry><link href="...">) に対応。
     *
     * @return string[]
     */
    private function extractUrlsFromXml(string $xmlContent): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            $errors = array_map(fn (\LibXMLError $e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            Log::warning("{$this->site->name} - XMLのパースに失敗しました", ['errors' => $errors]);

            return [];
        }

        libxml_clear_errors();

        $urls = [];

        // RSS (item) / Atom (entry) 形式
        $entries = $xml->xpath('//item | //entry | //*[local-name()="item"] | //*[local-name()="entry"]') ?: [];

        if (! empty($entries)) {
            foreach ($entries as $entry) {
                $entryUrl = null;
                $links = $entry->xpath('link | *[local-name()="link"]') ?: [];

                if (! empty($links)) {
                    $linkObj = $links[0];
                    if ((string) $linkObj !== '') {
                        $entryUrl = trim((string) $linkObj); // <link>URL</link>
                    } elseif (isset($linkObj['href'])) {
                        $entryUrl = trim((string) $linkObj['href']); // <link href="URL"/>
                    }
                }

                // フォールバック: <guid> / <id>
                if (empty($entryUrl)) {
                    $guid = $entry->xpath('guid | id | *[local-name()="guid"] | *[local-name()="id"]') ?: [];
                    if (! empty($guid) && filter_var((string) $guid[0], FILTER_VALIDATE_URL)) {
                        $entryUrl = trim((string) $guid[0]);
                    }
                }

                if (! empty($entryUrl) && filter_var($entryUrl, FILTER_VALIDATE_URL)) {
                    $urls[] = $entryUrl;
                }
            }

            return $urls;
        }

        // サイトマップ (<url><loc>) 形式へのフォールバック
        $locEntries = $xml->xpath('//loc | //*[local-name()="loc"]') ?: [];
        foreach ($locEntries as $loc) {
            $u = trim((string) $loc);
            if (! empty($u) && filter_var($u, FILTER_VALIDATE_URL)) {
                $urls[] = $u;
            }
        }

        return $urls;
    }

    /**
     * @param  string[]  $urls
     * @return string[]
     */
    private function pluckExistingUrlsChunked(array $urls): array
    {
        $existingUrls = [];

        foreach (array_chunk($urls, 1000) as $chunkedUrls) {
            $existingUrls = array_merge(
                $existingUrls,
                Article::whereIn('url', $chunkedUrls)->pluck('url')->toArray()
            );
        }

        return array_values(array_unique($existingUrls));
    }

    private function shareLogContext(): void
    {
        Log::withContext([
            'site_id' => $this->site->getKey(),
            'app_id' => $this->site->app_id,
            'app_slug' => (string) data_get($this->site, 'app.api_slug'),
        ]);
    }
}
