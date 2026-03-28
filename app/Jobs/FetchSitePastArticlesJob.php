<?php

namespace App\Jobs;

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
        Log::info("{$this->site->name} の過去記事一括取得処理を開始しました");

        try {
            $url = $this->site->url;
            if (empty($url)) {
                throw new Exception('サイトURLが設定されていません。');
            }

            $dispatchedCount = 0;

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // RSS / Atom / Sitemap モード
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            if ($this->site->crawler_type === 'sitemap') {
                $xmlUrl = $this->site->sitemap_url ?? $this->site->url;
                if (empty($xmlUrl)) {
                    throw new Exception('サイトマップ/RSSのURLが設定されていません。');
                }

                Log::info("{$this->site->name} - XML取得中: {$xmlUrl}");

                $xmlResponse = Http::withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                ])->timeout(15)->get($xmlUrl);

                if (!$xmlResponse->successful()) {
                    throw new Exception("XML取得に失敗しました (HTTP " . $xmlResponse->status() . "): {$xmlUrl}");
                }

                $xml = @simplexml_load_string($xmlResponse->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml === false) {
                    throw new Exception("{$this->site->name} - XMLのパースに失敗しました");
                }

                $extractedUrls = [];

                // RSS (item) と Atom (entry) の両方を名前空間に依存せず探索
                $entries = $xml->xpath('//item | //entry | //*:item | //*:entry');

                if (empty($entries)) {
                    // サイトマップ (<url><loc>) 形式のフォールバック
                    $locEntries = $xml->xpath('//loc | //*:loc');
                    foreach ($locEntries as $loc) {
                        $u = trim((string) $loc);
                        if (!empty($u) && filter_var($u, FILTER_VALIDATE_URL)) {
                            $extractedUrls[] = $u;
                        }
                    }
                } else {
                    foreach ($entries as $entry) {
                        $entryUrl = null;
                        $links = $entry->xpath('link | *:link');

                        if (!empty($links)) {
                            $linkObj = $links[0];
                            if ((string) $linkObj !== '') {
                                $entryUrl = trim((string) $linkObj); // パターンA: <link>URL</link>
                            } elseif (isset($linkObj['href'])) {
                                $entryUrl = trim((string) $linkObj['href']); // パターンB: <link href="URL" />
                            }
                        }

                        // フォールバック: guid や id
                        if (empty($entryUrl)) {
                            $guid = $entry->xpath('guid | id | *:guid | *:id');
                            if (!empty($guid) && filter_var((string) $guid[0], FILTER_VALIDATE_URL)) {
                                $entryUrl = trim((string) $guid[0]);
                            }
                        }

                        if (!empty($entryUrl)) {
                            $extractedUrls[] = $entryUrl;
                        }
                    }
                }

                Log::info("{$this->site->name} - XMLから " . count($extractedUrls) . " 件のURLを抽出しました");

                // NGワードフィルタリング
                $ngWords = $this->site->ng_url_keywords ?? [];
                if (!empty($ngWords)) {
                    $extractedUrls = array_values(array_filter($extractedUrls, function ($u) use ($ngWords) {
                        foreach ($ngWords as $word) {
                            if (!empty($word) && str_contains($u, $word)) {
                                Log::info("[Fetch: {$this->site->name}] NGワード({$word})を含むため除外: {$u}");
                                return false;
                            }
                        }
                        return true;
                    }));
                }

                // DB重複チェック
                $existingUrls = \App\Models\Article::whereIn('url', $extractedUrls)->pluck('url')->toArray();
                $newUrls = array_values(array_filter($extractedUrls, fn ($u) => !in_array($u, $existingUrls)));

                Log::info("[Fetch: {$this->site->name}] 新規URL: " . count($newUrls) . "件 / 重複スキップ: " . (count($extractedUrls) - count($newUrls)) . "件");

                foreach ($newUrls as $newUrl) {
                    ProcessArticleJob::dispatch($this->site, $newUrl, [], 'ollama', 'RSS一括');
                    $dispatchedCount++;

                    if ($this->limit > 0 && $dispatchedCount >= $this->limit) {
                        Log::info("[Fetch: {$this->site->name}] 指定された上限（{$this->limit}件）に到達したため終了します。");
                        break;
                    }
                }

            } else {
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                // HTML 解析モード（ページネーション対応）
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            // 安全のためページネーションの暴走を防ぐハードリミット
            $maxPages = 100;

            for ($page = 1; $page <= $maxPages; $page++) {
                // 管理画面でテンプレートが設定されている場合はそれを優先使用
                if (!empty($this->site->pagination_url_template)) {
                    $targetUrl = str_replace('{page}', $page, $this->site->pagination_url_template);
                } else {
                    // 未設定の場合は1ページ目はベースURL、2ページ目以降は /page/N を付与
                    $baseUrl = rtrim(preg_replace('/\/page\/\d+$/i', '', $this->site->url), '/');
                    $targetUrl = $page === 1 ? $baseUrl : $baseUrl . '/page/' . $page;
                }

                Log::info("{$this->site->name} - ページ {$page} をクロール中: {$targetUrl}");

                $response = Http::withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
                ])->timeout(10)->get($targetUrl);

                if (!$response->successful()) {
                    Log::warning("{$this->site->name} - ページ {$page} の取得に失敗しました (HTTP " . $response->status() . ")");
                    break;
                }

                $crawler = new Crawler($response->body(), $targetUrl);

                // 汎用的な記事リンクセレクタ
                $selectors = ['h2 a', 'article a', '.entry-title a', '.post-title a', 'main a', '.list-item a'];
                $pageUrls = [];

                foreach ($selectors as $selector) {
                    if ($crawler->filter($selector)->count() > 0) {
                        $crawler->filter($selector)->each(function (Crawler $node) use (&$pageUrls) {
                            try {
                                $link = $node->link()->getUri();
                                if (!empty($link) && !in_array($link, $pageUrls)) {
                                    $pageUrls[] = $link;
                                }
                            } catch (Exception $e) {
                                // リンクが取得できない場合は無視
                            }
                        });

                        if (!empty($pageUrls)) {
                            break;
                        }
                    }
                }

                // 1. ホストフィルタ & ハッシュリンク除去
                $siteHost = parse_url($this->site->url, PHP_URL_HOST);
                $cleanedPageUrls = [];

                foreach ($pageUrls as $articleUrl) {
                    $articleUrl = explode('#', $articleUrl)[0];
                    $articleHost = parse_url($articleUrl, PHP_URL_HOST);
                    if ($articleHost === $siteHost) {
                        $cleanedPageUrls[] = $articleUrl;
                    }
                }

                // 2. NGワードフィルタリング
                $ngWords = $this->site->ng_url_keywords ?? [];
                if (!empty($ngWords)) {
                    $filteredUrls = [];
                    foreach ($cleanedPageUrls as $url) {
                        $isNg = false;
                        foreach ($ngWords as $word) {
                            if (!empty($word) && str_contains($url, $word)) {
                                $isNg = true;
                                Log::info("[Fetch: {$this->site->name}] NGワード({$word})を含むため除外: {$url}");
                                break;
                            }
                        }
                        if (!$isNg) {
                            $filteredUrls[] = $url;
                        }
                    }
                    $cleanedPageUrls = $filteredUrls;
                }

                // 3. DB重複チェック（純粋な新規URLのみ抽出）
                $existingUrls = \App\Models\Article::whereIn('url', $cleanedPageUrls)->pluck('url')->toArray();
                $newUrls = array_values(array_filter($cleanedPageUrls, fn($u) => !in_array($u, $existingUrls)));
                $skippedCount = count($cleanedPageUrls) - count($newUrls);

                Log::info("[Fetch: {$this->site->name}] ページ {$page} を解析中... (新規: " . count($newUrls) . "件 / 重複スキップ: {$skippedCount}件)");

                // ページ上の全URLが既存DB済みなら過去をこれ以上辿っても無意味
                if (count($newUrls) === 0 && count($cleanedPageUrls) > 0) {
                    Log::info("[Fetch: {$this->site->name}] 全てのURLが重複だったためスキップしました（Page {$page} で探索終了）");
                    break;
                }

                // 4. 純粋な新規URLのみをそのままキューに投入し、純増カウント
                foreach ($newUrls as $newUrl) {
                    ProcessArticleJob::dispatch($this->site, $newUrl, [], 'ollama', '過去記事一括');
                    $dispatchedCount++;

                    if ($this->limit > 0 && $dispatchedCount >= $this->limit) {
                        Log::info("[Fetch: {$this->site->name}] 指定された上限（{$this->limit}件）の新規記事を獲得したため、探索を終了します。");
                        break 2; // foreach と外側のページループを一気に脱出
                    }
                }

                // 対象サイトへのサーバー負荷軽減
                sleep(1);
            }
            } // end HTML mode

            $limitLog = $this->limit === 0 ? 'なし' : $this->limit . '件';
            $successMessage = "[Scraper] {$this->site->name} から新しい記事を {$dispatchedCount} 件取得し、キューに投入しました（上限設定: {$limitLog}）";
            Log::info($successMessage);
            $this->output = $successMessage;

            return $successMessage;


        } catch (Exception $e) {
            $errorMessage = "失敗: {$this->site->name} の過去記事一括取得処理中にエラーが発生しました: " . $e->getMessage();
            Log::error($errorMessage . "\n" . $e->getTraceAsString());
            $this->output = $errorMessage;
            return $errorMessage;
        }
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
            $errors = array_map(fn(\LibXMLError $e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            Log::warning("{$this->site->name} - XMLのパースに失敗しました", ['errors' => $errors]);

            return [];
        }

        libxml_clear_errors();

        $urls = [];

        // RSS (item) / Atom (entry) 形式
        $entries = $xml->xpath('//item | //entry | //*:item | //*:entry') ?: [];

        if (!empty($entries)) {
            foreach ($entries as $entry) {
                $entryUrl = null;
                $links = $entry->xpath('link | *:link') ?: [];

                if (!empty($links)) {
                    $linkObj = $links[0];
                    if ((string) $linkObj !== '') {
                        $entryUrl = trim((string) $linkObj); // <link>URL</link>
                    } elseif (isset($linkObj['href'])) {
                        $entryUrl = trim((string) $linkObj['href']); // <link href="URL"/>
                    }
                }

                // フォールバック: <guid> / <id>
                if (empty($entryUrl)) {
                    $guid = $entry->xpath('guid | id | *:guid | *:id') ?: [];
                    if (!empty($guid) && filter_var((string) $guid[0], FILTER_VALIDATE_URL)) {
                        $entryUrl = trim((string) $guid[0]);
                    }
                }

                if (!empty($entryUrl) && filter_var($entryUrl, FILTER_VALIDATE_URL)) {
                    $urls[] = $entryUrl;
                }
            }

            return $urls;
        }

        // サイトマップ (<url><loc>) 形式へのフォールバック
        $locEntries = $xml->xpath('//loc | //*:loc') ?: [];
        foreach ($locEntries as $loc) {
            $u = trim((string) $loc);
            if (!empty($u) && filter_var($u, FILTER_VALIDATE_URL)) {
                $urls[] = $u;
            }
        }

        return $urls;
    }
}
