<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Pages\SystemSettings;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

use function Laravel\Ai\agent;

class SiteAnalyzerService
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const SITEMAP_CANDIDATE_PATHS = [
        '/sitemap.xml',
        '/sitemap_index.xml',
        '/sitemap-index.xml',
        '/wp-sitemap.xml',
        '/sitemap/sitemap.xml',
    ];

    public function __construct(
        private readonly Crawl4AiService $crawl4AiService,
        private readonly ArticleScraperService $articleScraperService,
    ) {}

    /**
     * @return array{
     *     site_title: string|null,
     *     rss_url: string|null,
     *     crawler_type: string,
     *     sitemap_url: string|null,
     *     crawl_start_url: string|null,
     *     list_item_selector: string|null,
     *     link_selector: string|null,
     *     pagination_url_template: string|null,
     *     ng_image_urls: array<int, string>,
     *     analysis_method: string,
     *     diagnostics: array<int, string>
     * }
     */
    public function analyze(string $url): array
    {
        $normalizedUrl = $this->normalizeUrl($url);
        $result = $this->baseAnalysisResult($normalizedUrl);
        $prefetchedHtmlAnalysis = null;

        $siteTitle = $this->detectSiteTitle($normalizedUrl);
        if ($siteTitle !== null) {
            $result['site_title'] = $siteTitle;
            $result['diagnostics'][] = 'サイトタイトル候補を取得しました。';
        }

        if ($this->isLivedoorBlogUrl($normalizedUrl)) {
            $rootUrl = $this->rootUrl($normalizedUrl);

            $result['rss_url'] = $rootUrl.'/index.rdf';
            $result['crawler_type'] = 'sitemap';
            $result['sitemap_url'] = $rootUrl.'/sitemap.xml';
            $result['crawl_start_url'] = null;
            $result['analysis_method'] = 'rss+sitemap';
            $result['diagnostics'][] = 'ライブドアブログを検出したため、RSSを index.rdf、過去記事取得を sitemap.xml で固定設定しました。';

            return $result;
        }

        $rssUrl = $this->detectRssFeedUrl($normalizedUrl);

        if ($rssUrl !== null) {
            $result['rss_url'] = $rssUrl;
            $result['diagnostics'][] = 'サイト内RSSフィードを検出しました。';
        } else {
            $morssListItemSelector = null;

            try {
                $prefetchedHtmlAnalysis = $this->analyzeHtmlStructureWithLlm($normalizedUrl);
                $morssListItemSelector = $this->sanitizeNullableString($prefetchedHtmlAnalysis['list_item_selector'] ?? null);
            } catch (Throwable $e) {
                Log::info('[SiteAnalyzerService] morss 用セレクタ推論に失敗したため、通常候補で継続します。', [
                    'url' => $normalizedUrl,
                    'message' => $e->getMessage(),
                ]);
            }

            $morssUrl = $this->detectMorssFeedUrl($normalizedUrl, $morssListItemSelector);

            if ($morssUrl !== null) {
                $result['rss_url'] = $morssUrl;
                $result['diagnostics'][] = str_contains($morssUrl, ':proxy:items=')
                    ? 'RSSが見つからなかったため、Crawl4AIで推論したセレクタを適用した morss.it フィードを利用します。'
                    : 'RSSが見つからなかったため、morss.it のフィードを利用します。';
            } else {
                $result['diagnostics'][] = 'RSSは検出できませんでした（morss.it でも取得不可）。';
            }
        }

        $sitemapUrl = $this->detectSitemapUrl($normalizedUrl);

        if ($sitemapUrl !== null) {
            $result['diagnostics'][] = '記事URLを抽出可能なサイトマップを検出しました。';

            $sitemapState = [
                'url' => $normalizedUrl,
                'rss_url' => $result['rss_url'],
                'crawler_type' => 'sitemap',
                'sitemap_url' => $sitemapUrl,
                'crawl_start_url' => null,
                'pagination_url_template' => null,
                'list_item_selector' => null,
                'link_selector' => null,
                'next_page_selector' => null,
                'ng_url_keywords' => [],
                'ng_image_urls' => $result['ng_image_urls'],
            ];

            $sitemapPreview = $this->previewCrawlExtraction($sitemapState);
            $sitemapError = is_string($sitemapPreview['error'] ?? null)
                ? (string) $sitemapPreview['error']
                : null;
            $sitemapSampleCompleteCount = (int) ($sitemapPreview['sample_complete_count'] ?? 0);

            if ($sitemapError === null && $sitemapSampleCompleteCount > 0) {
                $result['crawler_type'] = 'sitemap';
                $result['sitemap_url'] = $sitemapUrl;
                $result['crawl_start_url'] = null;
                $result['analysis_method'] = $result['rss_url'] !== null ? 'rss+sitemap' : 'sitemap';
                $result['diagnostics'][] = "サイトマップ検証に成功しました（完全抽出 {$sitemapSampleCompleteCount} 件）。";

                return $result;
            }

            if ($sitemapError !== null) {
                $result['diagnostics'][] = 'サイトマップ検証でエラーが発生したため、一覧ページ抽出へフォールバックします: '.$sitemapError;
            } else {
                $result['diagnostics'][] = 'サイトマップは検出できましたが、記事メタデータ（タイトル・URL・画像・公開日）を確認できなかったため、一覧ページ抽出へフォールバックします。';
            }
        }

        try {
            if ($prefetchedHtmlAnalysis !== null) {
                $llmResult = $prefetchedHtmlAnalysis;
            } else {
                $feedSampleUrls = $this->collectFeedSampleUrls($result['rss_url']);
                $llmResult = $this->analyzeHtmlStructureWithLlm($normalizedUrl, $feedSampleUrls);
            }

            $llmResult['rss_url'] = $result['rss_url'];

            $result = array_merge($result, $llmResult, [
                'analysis_method' => $result['rss_url'] !== null ? 'rss+llm' : 'llm',
            ]);

            $result['diagnostics'][] = '一覧ページ抽出ルールを推論しました。';

            return $result;
        } catch (Throwable $e) {
            Log::warning('[SiteAnalyzerService] LLM解析に失敗しました。フォールバックします。', [
                'url' => $normalizedUrl,
                'message' => $e->getMessage(),
            ]);

            $result['crawler_type'] = 'html';
            $result['crawl_start_url'] = $normalizedUrl;
            $result['sitemap_url'] = null;
            $result['list_item_selector'] = 'article, .post, .entry, .list-item, li';
            $result['link_selector'] = 'a';
            $result['pagination_url_template'] = null;
            $result['analysis_method'] = $result['rss_url'] !== null ? 'rss+fallback' : 'fallback';
            $result['diagnostics'][] = 'サイトマップ未検出かつLLM解析に失敗したため、汎用HTML抽出ルールを設定しました。';

            return $result;
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{error: string|null, items: array<int, array{title: string, url: string, date: string, image: string}>}
     */
    public function previewRssFetch(array $state): array
    {
        $rssUrl = trim((string) ($state['rss_url'] ?? ''));

        if ($rssUrl === '') {
            return [
                'error' => 'RSS URLが設定されていません。',
                'items' => [],
            ];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'ja,en-US;q=0.9,en;q=0.8',
            ])->withOptions(['verify' => false])->timeout(10)->get($rssUrl);

            if (! $response->successful()) {
                return [
                    'error' => "HTTP通信に失敗しました (ステータスコード: {$response->status()})",
                    'items' => [],
                ];
            }

            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

            if ($xml === false) {
                return [
                    'error' => 'XMLのパースに失敗しました。フィードが正しい形式か確認してください。',
                    'items' => [],
                ];
            }

            $items = $xml->xpath('//*[local-name()="item"] | //*[local-name()="entry"]');

            if (! $items) {
                return [
                    'error' => '記事アイテム (<item> または <entry>) が見つかりませんでした。',
                    'items' => [],
                ];
            }

            $results = [];
            $scrapedCount = 0;
            $ngKeywords = $this->sanitizeStringArray($state['ng_url_keywords'] ?? []);
            $ngImageUrls = $this->sanitizeStringArray($state['ng_image_urls'] ?? []);

            foreach ($items as $item) {
                if (count($results) >= 10) {
                    break;
                }

                $titleRaw = (string) $item->title;
                $title = $titleRaw !== '' ? trim($titleRaw) : 'なし';

                $urlRaw = (string) $item->link;
                $url = $urlRaw !== '' ? trim($urlRaw) : '';

                if ($url === '' && isset($item->link['href'])) {
                    $url = trim((string) $item->link['href']);
                }

                if ($url === '') {
                    $guids = $item->xpath('.//*[local-name()="guid"] | .//*[local-name()="id"]') ?: [];

                    if (! empty($guids) && filter_var(trim((string) $guids[0]), FILTER_VALIDATE_URL)) {
                        $url = trim((string) $guids[0]);
                    }
                }

                $url = $url === '' ? 'なし' : $url;

                if ($url !== 'なし') {
                    foreach ($ngKeywords as $keyword) {
                        if ($keyword !== '' && str_contains($url, $keyword)) {
                            continue 2;
                        }
                    }
                }

                $publishedAtRaw = (string) $item->pubDate
                    ?: (string) $item->children('dc', true)->date
                    ?: (string) $item->updated
                    ?: (string) $item->published
                    ?: (string) $item->date;

                $date = 'なし';

                if (trim($publishedAtRaw) !== '') {
                    try {
                        $date = Carbon::parse(trim($publishedAtRaw))->toDateTimeString();
                    } catch (Throwable) {
                        $date = 'なし';
                    }
                }

                $imageUrl = 'なし';

                if (isset($item->enclosure) && isset($item->enclosure['url'])) {
                    $imageUrl = trim((string) $item->enclosure['url']);
                } elseif ($item->children('media', true)->content && isset($item->children('media', true)->content->attributes()->url)) {
                    $imageUrl = trim((string) $item->children('media', true)->content->attributes()->url);
                } elseif ($item->children('media', true)->thumbnail && isset($item->children('media', true)->thumbnail->attributes()->url)) {
                    $imageUrl = trim((string) $item->children('media', true)->thumbnail->attributes()->url);
                }

                if ($imageUrl === 'なし') {
                    $content = (string) $item->children('content', true)->encoded ?: (string) $item->description;

                    if (preg_match('/<img[^>]+src=[\'\"]([^\'\"]+)[\'\"]/i', $content, $matches)) {
                        $imageUrl = trim($matches[1]);
                    }
                }

                if ($imageUrl !== 'なし' && in_array($imageUrl, $ngImageUrls, true)) {
                    $imageUrl = 'なし';
                }

                if (($imageUrl === 'なし' || $date === 'なし') && $url !== 'なし' && $scrapedCount < 5) {
                    $scrapedCount++;
                    $scrapeResult = $this->articleScraperService->scrape($url);

                    if ($scrapeResult['success']) {
                        if ($date === 'なし') {
                            $date = $scrapeResult['data']['date'] ?? 'なし ('.($scrapeResult['error_message'] ?? '日付見つからず').')';
                        }

                        if ($imageUrl === 'なし') {
                            $scrapedImage = $scrapeResult['data']['image'] ?? null;

                            if (! empty($scrapedImage)) {
                                $imageUrl = in_array($scrapedImage, $ngImageUrls, true)
                                    ? 'なし (NGサムネイル画像として除外)'
                                    : $scrapedImage;
                            } else {
                                $imageUrl = 'なし ('.($scrapeResult['error_message'] ?? '画像見つからず').')';
                            }
                        }
                    } elseif (! empty($scrapeResult['error_message'])) {
                        if ($date === 'なし') {
                            $date = 'なし ('.$scrapeResult['error_message'].')';
                        }

                        if ($imageUrl === 'なし') {
                            $imageUrl = 'なし ('.$scrapeResult['error_message'].')';
                        }
                    }
                }

                if (is_string($imageUrl) && $imageUrl !== 'なし' && in_array($imageUrl, $ngImageUrls, true)) {
                    $imageUrl = 'なし (NGサムネイル画像として除外)';
                }

                $results[] = [
                    'title' => $title,
                    'url' => $url,
                    'date' => $date,
                    'image' => $imageUrl,
                ];
            }

            return [
                'error' => null,
                'items' => $results,
            ];
        } catch (Throwable $e) {
            return [
                'error' => '通信エラーが発生しました: '.$e->getMessage(),
                'items' => [],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{
     *     error: string|null,
     *     urls: array<int, string>,
     *     count: int,
     *     total_count: int,
     *     next_url: string|null,
     *     sample_items: array<int, array{title: string, url: string, image: string, date: string}>,
     *     sample_complete_count: int,
     *     sample_checked_count: int
     * }
     */
    public function previewCrawlExtraction(array $state): array
    {
        try {
            $crawlerType = (string) ($state['crawler_type'] ?? 'html');
            $urls = [];
            $nextUrl = null;
            $totalCount = 0;

            if ($crawlerType === 'sitemap') {
                $sitemapUrl = trim((string) ($state['sitemap_url'] ?? ''));

                if ($sitemapUrl === '') {
                    throw new RuntimeException('サイトマップURLが設定されていません。');
                }

                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                ])->timeout(10)->get($sitemapUrl);

                if (! $response->successful()) {
                    throw new RuntimeException('HTTP通信に失敗: '.$response->status());
                }

                $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

                if ($xml === false) {
                    throw new RuntimeException('XMLのパースに失敗しました。');
                }

                if (isset($xml->sitemap)) {
                    $sitemaps = $xml->sitemap;
                    $targetSitemapUrl = (string) $sitemaps[count($sitemaps) - 1]->loc;

                    $childResponse = Http::withHeaders([
                        'User-Agent' => self::USER_AGENT,
                    ])->timeout(10)->get($targetSitemapUrl);

                    if (! $childResponse->successful()) {
                        throw new RuntimeException('子サイトマップのHTTP通信に失敗: '.$childResponse->status());
                    }

                    $childXml = @simplexml_load_string($childResponse->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

                    if ($childXml === false) {
                        throw new RuntimeException('子サイトマップのXMLパースに失敗しました。');
                    }

                    $xml = $childXml;
                }

                foreach (($xml->url ?? []) as $urlEntry) {
                    $urls[] = (string) $urlEntry->loc;
                }

                $urls = $this->filterCandidateUrls($urls, $state, (string) ($state['link_selector'] ?? ''));
                $totalCount = count($urls);
            } else {
                $crawlStartUrl = trim((string) ($state['crawl_start_url'] ?? ''));

                if ($crawlStartUrl === '') {
                    throw new RuntimeException('クロール開始URLが設定されていません。');
                }

                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                ])->timeout(10)->get($crawlStartUrl);

                if (! $response->successful()) {
                    throw new RuntimeException('HTTP通信に失敗: '.$response->status());
                }

                $crawler = new Crawler($response->body(), $crawlStartUrl);
                $listSelector = trim((string) ($state['list_item_selector'] ?? ''));
                $linkSelector = trim((string) ($state['link_selector'] ?? ''));

                if ($listSelector === '' && $linkSelector === '') {
                    throw new RuntimeException('リストブロックまたは記事リンクのセレクタが未設定です。');
                }

                if ($listSelector === '') {
                    $items = $crawler->filter($linkSelector);
                    $items->each(function (Crawler $node) use (&$urls): void {
                        try {
                            $linkUrl = null;

                            if ($node->nodeName() === 'a') {
                                $linkUrl = $node->link()->getUri();
                            } elseif ($node->filter('a')->count() > 0) {
                                $linkUrl = $node->filter('a')->first()->link()->getUri();
                            }

                            if ($linkUrl !== null) {
                                $urls[] = $linkUrl;
                            }
                        } catch (Throwable) {
                            // ignore node parse error
                        }
                    });
                } else {
                    $items = $crawler->filter($listSelector);
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

                            if ($linkUrl !== null) {
                                $urls[] = $linkUrl;
                            }
                        } catch (Throwable) {
                            // ignore node parse error
                        }
                    });
                }

                $nextPageSelector = trim((string) ($state['next_page_selector'] ?? ''));
                if ($nextPageSelector !== '' && $crawler->filter($nextPageSelector)->count() > 0) {
                    $nextUrl = $crawler->filter($nextPageSelector)->first()->link()->getUri();
                }

                $totalCount = $items->count();
                $urls = $this->filterCandidateUrls($urls, $state, $linkSelector);
            }

            $sampleItems = [];
            $sampleCompleteCount = 0;
            $ngImageUrls = $this->sanitizeStringArray($state['ng_image_urls'] ?? []);

            foreach (array_slice($urls, 0, 3) as $sampleUrl) {
                $title = '未取得';
                $image = '未取得';
                $date = '未取得';

                $scrapeResult = $this->articleScraperService->scrape($sampleUrl);

                if ($scrapeResult['success']) {
                    $title = $scrapeResult['data']['title'] ?? '取得失敗(タイトル見つからず)';

                    $image = $scrapeResult['data']['image'] ?? '';
                    if ($image !== '' && in_array($image, $ngImageUrls, true)) {
                        $image = 'なし (NGサムネイル画像として除外)';
                    }
                    if ($image === '') {
                        $image = 'なし ('.($scrapeResult['error_message'] ?? '画像見つからず').')';
                    }

                    $date = $scrapeResult['data']['date'] ?? '';
                    if ($date === '' || $date === null) {
                        $date = 'なし ('.($scrapeResult['error_message'] ?? '日付見つからず').')';
                    }
                } else {
                    $title = '取得失敗('.($scrapeResult['error_message'] ?? '不明なエラー').')';
                }

                $sampleItems[] = [
                    'title' => $title,
                    'url' => $sampleUrl,
                    'image' => $image,
                    'date' => $date,
                ];

                if ($this->isSampleItemComplete($sampleItems[count($sampleItems) - 1])) {
                    $sampleCompleteCount++;
                }
            }

            return [
                'error' => null,
                'urls' => $urls,
                'count' => count($urls),
                'total_count' => $totalCount,
                'next_url' => $nextUrl,
                'sample_items' => $sampleItems,
                'sample_complete_count' => $sampleCompleteCount,
                'sample_checked_count' => count($sampleItems),
            ];
        } catch (Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'urls' => [],
                'count' => 0,
                'total_count' => 0,
                'next_url' => null,
                'sample_items' => [],
                'sample_complete_count' => 0,
                'sample_checked_count' => 0,
            ];
        }
    }

    /**
     * @return array{
     *     rss_url: string|null,
     *     crawler_type: string,
     *     sitemap_url: string|null,
     *     crawl_start_url: string,
     *     list_item_selector: string,
     *     link_selector: string,
     *     pagination_url_template: string|null,
     *     ng_image_urls: array<int, string>
     * }
     */
    private function analyzeHtmlStructureWithLlm(string $url, array $feedSampleUrls = []): array
    {
        $crawlResult = $this->crawl4AiService->crawl($url);
        $markdown = trim((string) ($crawlResult['markdown'] ?? ''));

        if ($markdown === '') {
            throw new RuntimeException('Crawl4AIの解析結果が空です。');
        }

        $response = agent(
            instructions: trim($this->siteAnalyzerPrompt())."\n\n【必須】PR記事や広告バナーを除外する場合は、必ず :not() 疑似クラスを使ってください。",
            schema: function (JsonSchema $schema): array {
                return [
                    'list_item_selector' => $schema->string()->required()->description('記事ブロックのCSSセレクタ。PRや広告除外には:not()を利用する。'),
                    'link_selector' => $schema->string()->required()->description('記事URLを抽出するためのaタグセレクタ。'),
                    'pagination_url_template' => $schema->string()->nullable()->description('次ページURLテンプレート。ページ番号部分は {page} を使う。'),
                    'ng_image_urls' => $schema->array()->items($schema->string())->required()->description('ロゴ等の除外画像URLリスト。'),
                ];
            },
        )->prompt(
            $this->buildAnalyzerPrompt($url, $markdown, $feedSampleUrls),
            provider: 'gemini',
            model: $this->geminiModel(),
            timeout: 120,
        );

        $structured = $response->toArray();

        $listItemSelector = trim((string) ($structured['list_item_selector'] ?? ''));
        $linkSelector = trim((string) ($structured['link_selector'] ?? ''));

        if ($listItemSelector === '' || $linkSelector === '') {
            throw new RuntimeException('LLMから必要なCSSセレクタを取得できませんでした。');
        }

        return [
            'rss_url' => null,
            'crawler_type' => 'html',
            'sitemap_url' => null,
            'crawl_start_url' => $url,
            'list_item_selector' => $listItemSelector,
            'link_selector' => $linkSelector,
            'pagination_url_template' => $this->sanitizeNullableString($structured['pagination_url_template'] ?? null),
            'ng_image_urls' => $this->sanitizeStringArray($structured['ng_image_urls'] ?? []),
        ];
    }

    private function detectRssFeedUrl(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ja,en-US;q=0.9,en;q=0.8',
            ])->timeout(10)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $crawler = new Crawler($response->body(), $url);
            $candidates = [];

            $crawler->filter('link[rel~="alternate"]')->each(function (Crawler $node) use (&$candidates, $url): void {
                $type = strtolower((string) $node->attr('type'));

                if (! str_contains($type, 'rss') && ! str_contains($type, 'atom') && ! str_contains($type, 'xml')) {
                    return;
                }

                $href = trim((string) $node->attr('href'));
                $resolved = $this->resolveUrl($url, $href);

                if ($resolved !== null) {
                    $candidates[] = $resolved;
                }
            });

            $crawler->filter('a[href*="feed"], a[href*="rss"], a[href*="atom"]')->each(function (Crawler $node) use (&$candidates, $url): void {
                $href = trim((string) $node->attr('href'));
                $resolved = $this->resolveUrl($url, $href);

                if ($resolved !== null) {
                    $candidates[] = $resolved;
                }
            });

            foreach (array_values(array_unique($candidates)) as $candidate) {
                if ($this->isFeedDocument($candidate)) {
                    return $candidate;
                }
            }

            return null;
        } catch (Throwable $e) {
            Log::warning('[SiteAnalyzerService] RSS検出時に例外が発生しました。', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function detectSitemapUrl(string $url): ?string
    {
        $candidates = $this->buildSitemapCandidates($url);

        foreach ($candidates as $candidate) {
            if ($this->sitemapContainsArticleUrls($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function detectMorssFeedUrl(string $url, ?string $listItemSelector = null): ?string
    {
        foreach ($this->buildMorssFeedCandidates($url, $listItemSelector) as $candidate) {
            if ($this->isFeedDocument($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function buildMorssFeedCandidates(string $url, ?string $listItemSelector = null): array
    {
        $candidates = [];
        $morssItemsSelector = $this->toMorssItemsSelector($listItemSelector);

        if ($morssItemsSelector !== null) {
            $candidates[] = $this->buildMorssPathStyleFeedUrl(
                $url,
                $this->encodeMorssOptionValue($morssItemsSelector)
            );
        }

        $candidates[] = 'https://morss.it/?url='.rawurlencode($url);
        $candidates[] = 'https://morss.it/'.rawurlencode($url);

        return array_values(array_unique($candidates));
    }

    private function buildMorssPathStyleFeedUrl(string $url, string $encodedItemsSelector): string
    {
        return "https://morss.it/:proxy:items={$encodedItemsSelector}/{$url}";
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

    private function detectSiteTitle(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ja,en-US;q=0.9,en;q=0.8',
            ])->timeout(10)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $crawler = new Crawler($response->body(), $url);
            $candidates = [];

            if ($crawler->filter('meta[property="og:site_name"]')->count() > 0) {
                $candidates[] = (string) $crawler->filter('meta[property="og:site_name"]')->first()->attr('content');
            }

            if ($crawler->filter('meta[name="application-name"]')->count() > 0) {
                $candidates[] = (string) $crawler->filter('meta[name="application-name"]')->first()->attr('content');
            }

            if ($crawler->filter('title')->count() > 0) {
                $candidates[] = (string) $crawler->filter('title')->first()->text();
            }

            foreach ($candidates as $candidate) {
                $normalizedTitle = $this->normalizeSiteTitle((string) $candidate);

                if ($normalizedTitle !== null) {
                    return $normalizedTitle;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeSiteTitle(string $title): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($title));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        $parts = preg_split('/\s*[|｜\-–—:：]\s*/u', $normalized);
        $primary = trim((string) ($parts[0] ?? $normalized));

        if ($primary === '') {
            $primary = $normalized;
        }

        return mb_substr($primary, 0, 255);
    }

    /**
     * @return array<int, string>
     */
    private function buildSitemapCandidates(string $url): array
    {
        $rootUrl = $this->rootUrl($url);
        $candidates = [];

        foreach (self::SITEMAP_CANDIDATE_PATHS as $path) {
            $candidates[] = $rootUrl.$path;
        }

        foreach ($this->extractSitemapsFromRobots($rootUrl) as $robotsSitemap) {
            $candidates[] = $robotsSitemap;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array<int, string>
     */
    private function extractSitemapsFromRobots(string $rootUrl): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/plain,*/*;q=0.8',
            ])->timeout(5)->get($rootUrl.'/robots.txt');

            if (! $response->successful()) {
                return [];
            }

            $sitemaps = [];

            foreach (preg_split('/\R/u', $response->body()) ?: [] as $line) {
                if (! preg_match('/^\s*Sitemap\s*:\s*(.+)\s*$/i', $line, $matches)) {
                    continue;
                }

                $candidate = $this->resolveUrl($rootUrl, trim($matches[1]));

                if ($candidate !== null) {
                    $sitemaps[] = $candidate;
                }
            }

            return array_values(array_unique($sitemaps));
        } catch (Throwable) {
            return [];
        }
    }

    private function isFeedDocument(string $url): bool
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.8',
            ])->timeout(8)->get($url);

            if (! $response->successful()) {
                return false;
            }

            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

            if ($xml === false) {
                return false;
            }

            $rootName = strtolower($xml->getName());

            if (in_array($rootName, ['rss', 'feed', 'rdf'], true)) {
                return true;
            }

            $entries = $xml->xpath('//*[local-name()="item"] | //*[local-name()="entry"]') ?: [];

            return $entries !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function isSitemapDocument(string $url): bool
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.8',
            ])->timeout(8)->get($url);

            if (! $response->successful()) {
                return false;
            }

            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

            if ($xml === false) {
                return false;
            }

            $rootName = strtolower($xml->getName());

            if (in_array($rootName, ['urlset', 'sitemapindex'], true)) {
                return true;
            }

            $locEntries = $xml->xpath('//*[local-name()="loc"]') ?: [];

            return $locEntries !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function siteAnalyzerPrompt(): string
    {
        return (string) Cache::get('site_analyzer_prompt', SystemSettings::getDefaultSiteAnalyzerPrompt());
    }

    private function geminiModel(): string
    {
        return (string) Cache::get(
            'gemini_model',
            (string) config('ai.providers.gemini.models.text.default', 'gemini-2.0-flash')
        );
    }

    private function buildAnalyzerPrompt(string $url, string $markdown, array $feedSampleUrls = []): string
    {
        $truncatedMarkdown = mb_substr($markdown, 0, 60000);
        $feedSampleText = $feedSampleUrls === []
            ? 'なし'
            : implode("\n", array_map(static fn (string $sampleUrl): string => '- '.$sampleUrl, $feedSampleUrls));

        return <<<PROMPT
対象サイトURL:
{$url}

RSS/morssから取得したサンプル記事URL（あれば参考にしてください）:
{$feedSampleText}

以下はCrawl4AIで抽出したMarkdownです。記事一覧を抽出するためのセレクタとページネーションURLテンプレートを推論してください。
PR記事・広告バナーを除外する場合は、必ず :not() 疑似クラスを使ってセレクタを構築してください。

--- START MARKDOWN ---
{$truncatedMarkdown}
--- END MARKDOWN ---
PROMPT;
    }

    /**
     * @return array<int, string>
     */
    private function collectFeedSampleUrls(?string $rssUrl): array
    {
        if ($rssUrl === null || $rssUrl === '') {
            return [];
        }

        $preview = $this->previewRssFetch([
            'rss_url' => $rssUrl,
            'ng_url_keywords' => [],
            'ng_image_urls' => [],
        ]);

        if (($preview['error'] ?? null) !== null) {
            return [];
        }

        return collect($preview['items'] ?? [])
            ->map(static fn ($item): string => is_array($item) ? (string) ($item['url'] ?? '') : '')
            ->filter(static fn (string $itemUrl): bool => $itemUrl !== '' && $itemUrl !== 'なし' && (bool) filter_var($itemUrl, FILTER_VALIDATE_URL))
            ->take(5)
            ->values()
            ->all();
    }

    private function sitemapContainsArticleUrls(string $url, int $depth = 0): bool
    {
        if ($depth > 1) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.8',
            ])->timeout(8)->get($url);

            if (! $response->successful()) {
                return false;
            }

            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

            if ($xml === false) {
                return false;
            }

            $locEntries = $xml->xpath('//*[local-name()="loc"]') ?: [];

            if ($locEntries === []) {
                return false;
            }

            $childSitemaps = [];

            foreach ($locEntries as $loc) {
                $locUrl = trim((string) $loc);

                if (! filter_var($locUrl, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $path = strtolower((string) parse_url($locUrl, PHP_URL_PATH));

                if ($path !== '' && str_ends_with($path, '.xml')) {
                    $childSitemaps[] = $locUrl;

                    continue;
                }

                if ($path !== '' && $path !== '/') {
                    return true;
                }
            }

            foreach (array_slice(array_values(array_unique($childSitemaps)), 0, 3) as $childSitemapUrl) {
                if ($this->sitemapContainsArticleUrls($childSitemapUrl, $depth + 1)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $urls
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    private function filterCandidateUrls(array $urls, array $state, string $linkSelector): array
    {
        $ngKeywords = $this->sanitizeStringArray($state['ng_url_keywords'] ?? []);
        $siteUrl = rtrim((string) ($state['url'] ?? ''), '/');
        $startUrl = rtrim((string) ($state['crawl_start_url'] ?? ''), '/');

        $requiredSubstring = null;
        if ($linkSelector !== '' && preg_match('/href\*?=[\'\"]([^\'\"]+)[\'\"]/u', $linkSelector, $matches)) {
            $requiredSubstring = $matches[1];
        }

        return collect($urls)
            ->filter(function ($candidateUrl) use ($requiredSubstring): bool {
                $candidateUrl = (string) $candidateUrl;

                if ($requiredSubstring !== null && ! str_contains($candidateUrl, $requiredSubstring)) {
                    return false;
                }

                return filter_var($candidateUrl, FILTER_VALIDATE_URL) !== false;
            })
            ->reject(function ($candidateUrl) use ($ngKeywords, $siteUrl, $startUrl): bool {
                $candidateUrl = (string) $candidateUrl;
                $cleanUrl = rtrim($candidateUrl, '/');

                if ($cleanUrl === $siteUrl || ($startUrl !== '' && $cleanUrl === $startUrl)) {
                    return true;
                }

                if (str_contains($candidateUrl, '/page/') || str_contains($candidateUrl, '?page=')) {
                    return true;
                }

                foreach ($ngKeywords as $keyword) {
                    if ($keyword !== '' && str_contains($candidateUrl, $keyword)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(static fn ($candidateUrl): string => (string) $candidateUrl)
            ->values()
            ->all();
    }

    private function isLivedoorBlogUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'blog.livedoor.jp';
    }

    private function normalizeUrl(string $url): string
    {
        $normalized = trim($url);

        if (! filter_var($normalized, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URLが不正です。');
        }

        return $normalized;
    }

    /**
     * @return array{
     *     site_title: string|null,
     *     rss_url: string|null,
     *     crawler_type: string,
     *     sitemap_url: string|null,
     *     crawl_start_url: string|null,
     *     list_item_selector: string|null,
     *     link_selector: string|null,
     *     pagination_url_template: string|null,
     *     ng_image_urls: array<int, string>,
     *     analysis_method: string,
     *     diagnostics: array<int, string>
     * }
     */
    private function baseAnalysisResult(string $url): array
    {
        return [
            'site_title' => null,
            'rss_url' => null,
            'crawler_type' => 'html',
            'sitemap_url' => null,
            'crawl_start_url' => $url,
            'list_item_selector' => null,
            'link_selector' => null,
            'pagination_url_template' => null,
            'ng_image_urls' => [],
            'analysis_method' => 'fallback',
            'diagnostics' => [],
        ];
    }

    /**
     * @param  array{title: string, url: string, image: string, date: string}  $sampleItem
     */
    private function isSampleItemComplete(array $sampleItem): bool
    {
        $url = (string) ($sampleItem['url'] ?? '');
        $title = (string) ($sampleItem['title'] ?? '');
        $image = (string) ($sampleItem['image'] ?? '');
        $date = (string) ($sampleItem['date'] ?? '');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return $this->isMeaningfulSampleValue($title)
            && $this->isMeaningfulSampleValue($image)
            && $this->isMeaningfulSampleValue($date);
    }

    private function isMeaningfulSampleValue(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || $trimmed === '未取得') {
            return false;
        }

        if (str_starts_with($trimmed, 'なし')) {
            return false;
        }

        if (str_starts_with($trimmed, '取得失敗')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function sanitizeStringArray(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    private function sanitizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function rootUrl(string $url): string
    {
        $parts = parse_url($url);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function resolveUrl(string $baseUrl, string $candidate): ?string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
            return $candidate;
        }

        $baseParts = parse_url($baseUrl);

        if ($baseParts === false || ! isset($baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';
        $origin = "{$scheme}://{$host}{$port}";

        if (str_starts_with($candidate, '//')) {
            return $scheme.':'.$candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return $origin.$candidate;
        }

        $basePath = $baseParts['path'] ?? '/';
        $dir = '/';

        if (str_contains($basePath, '/')) {
            $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        }

        return $origin.rtrim($dir, '/').'/'.ltrim($candidate, '/');
    }
}
