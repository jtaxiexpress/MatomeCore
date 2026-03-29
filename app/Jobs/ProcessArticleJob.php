<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Site;
use App\Services\ArticleAiService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ProcessArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    public ?string $output = null;

    /** handle() 内で設定されるサイトモデル */
    protected Site $site;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $siteId,
        public string $url,
        public array $metaData = [],
        public string $aiDriver = 'gemini',
        public ?string $fetchSource = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ArticleAiService $aiService): void
    {
        try {
            // キルスイッチ: 一時停止フラグが立っている場合はジョブを保留して60秒後に再試行
            if (Cache::get('is_bulk_paused', false)) {
                $this->release(60);

                return;
            }

            // ID からモデルを再取得（シリアライズ問題を回避）
            $this->site = Site::with('app.categories')->find($this->siteId);
            if (! $this->site) {
                Log::warning("ProcessArticleJob: Site ID {$this->siteId} が見つかりません。");

                return;
            }

            if (! $this->site->app) {
                Log::warning("ProcessArticleJob: App not found for Site ID {$this->siteId}");

                return;
            }

            // 1. 記事の重複チェック
            if (Article::where('url', $this->url)->exists()) {
                return;
            }

            // AIドライバー決定: 呼び出し元の明示指定 → App設定 → グローバルデフォルト の優先順
            $aiDriver = $this->aiDriver
                ?: ($this->site->app->ai_driver ?? config('ai.default', 'gemini'));

            $title = $this->metaData['raw_title'] ?? null;
            $thumbnailUrl = $this->metaData['thumbnail_url'] ?? null;
            $publishedAt = $this->metaData['published_at'] ?? now()->toDateTimeString();

            // raw_title が渡されていればHTMLスクレイピングはスキップ
            $needsScraping = empty($title) || empty($thumbnailUrl) || empty($this->metaData['published_at']);

            if ($needsScraping && ! empty($title)) {
                // タイトルはあるが thumbnail / published_at だけが欠けているケース:
                // スクレイピングはせず、不足分はデフォルト値で補う
                $publishedAt = $this->metaData['published_at']
                    ? $this->parseDateString($this->metaData['published_at'])->toDateTimeString()
                    : now()->toDateTimeString();
                $needsScraping = false;
            }

            if ($needsScraping) {
                // タイトルそのものが取れていない場合のみHTMLスクレイピングを実行
                try {
                    Log::info("[Process: {$this->url}] HTMLスクレイピングを開始します");
                    $response = Http::withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    ])->timeout(10)->get($this->url);

                    if ($response->successful()) {
                        $crawler = new Crawler($response->body(), $this->url);

                        // 1. Title fallback: og:title を最優先、次に <title> タグ
                        if (empty($title)) {
                            if ($crawler->filter('meta[property="og:title"]')->count() > 0) {
                                $title = $crawler->filter('meta[property="og:title"]')->attr('content');
                            } elseif ($crawler->filter('title')->count() > 0) {
                                $title = $crawler->filter('title')->text();
                            }
                            $title = trim($title);
                        }

                        // 2. Thumbnail fallback
                        if (empty($thumbnailUrl)) {
                            $imgSelectors = [
                                ['selector' => 'meta[property="og:image"]', 'attr' => 'content'],
                                ['selector' => 'meta[name="twitter:image"]', 'attr' => 'content'],
                                ['selector' => 'article img', 'attr' => 'src'],
                                ['selector' => '.entry-content img', 'attr' => 'src'],
                                ['selector' => 'img', 'attr' => 'src'],
                            ];
                            foreach ($imgSelectors as $img) {
                                if ($crawler->filter($img['selector'])->count() > 0) {
                                    $src = $crawler->filter($img['selector'])->first()->attr($img['attr']);
                                    if (! empty($src)) {
                                        $thumbnailUrl = trim($src);
                                        break;
                                    }
                                }
                            }
                        }

                        // 3. Published_at fallback (override if missing)
                        if (empty($this->metaData['published_at'])) {
                            $dateSelectors = [];

                            // DB設定の優先セレクタがある場合最優先で追加
                            if (! empty($this->site->date_selector)) {
                                $dateSelectors[] = ['selector' => $this->site->date_selector, 'attr' => '_text'];
                            }

                            // デフォルトのフォールバックセレクタ
                            $dateSelectors = array_merge($dateSelectors, [
                                ['selector' => 'meta[property="article:published_time"]', 'attr' => 'content'],
                                ['selector' => 'time', 'attr' => 'datetime'],
                                ['selector' => 'time', 'attr' => '_text'],
                                ['selector' => '.date', 'attr' => '_text'],
                                ['selector' => '.time', 'attr' => '_text'],
                            ]);

                            $extractedVal = null;

                            foreach ($dateSelectors as $dateInfo) {
                                if ($crawler->filter($dateInfo['selector'])->count() > 0) {
                                    $val = $dateInfo['attr'] === '_text'
                                        ? $crawler->filter($dateInfo['selector'])->first()->text()
                                        : $crawler->filter($dateInfo['selector'])->first()->attr($dateInfo['attr']);
                                    if (! empty(trim($val))) {
                                        $extractedVal = trim($val);
                                        break;
                                    }
                                }
                            }

                            // セレクタで見つからなかった場合、ページ全体のテキストから正規表現で抽出
                            if (empty($extractedVal) && $crawler->filter('body')->count() > 0) {
                                $bodyText = $crawler->filter('body')->text();
                                if (preg_match('/(20\d{2})[年\/\-\s]+(\d{1,2})[月\/\-\s]+(\d{1,2})日?\s*(?:[（\(][日月火水木金土祝][）\)])?\s*(?:(\d{1,2})[:時](\d{1,2})(?::(\d{1,2}))?)?/', $bodyText, $matches)) {
                                    $extractedVal = $matches[0];
                                }
                            }

                            if (! empty($extractedVal)) {
                                Log::info("[Process: {$this->url}] 日付文字列 [{$extractedVal}] のパース処理中...");
                                $publishedAt = $this->parseDateString($extractedVal)->toDateTimeString();
                            } else {
                                $publishedAt = now()->toDateTimeString();
                            }
                        } else {
                            Log::info("[Process: {$this->url}] 日付文字列 [{$this->metaData['published_at']}] のパース処理中...");
                            $publishedAt = $this->parseDateString($this->metaData['published_at'])->toDateTimeString();
                        }
                    }
                } catch (Exception $e) {
                    Log::error("ProcessArticleJob: Failed to fetch metadata for URL {$this->url} - ".$e->getMessage());
                    $publishedAt = now()->toDateTimeString();
                }
            } else {
                // メタデータが揃っている場合は日付パースのみ実行
                if (! empty($this->metaData['published_at'])) {
                    Log::info("[Process: {$this->url}] 日付文字列 [{$this->metaData['published_at']}] のパース処理中...");
                    $publishedAt = $this->parseDateString($this->metaData['published_at'])->toDateTimeString();
                } else {
                    $publishedAt = now()->toDateTimeString();
                }
            }

            // 2.5 タイトル洗浄
            $cleanTitle = $title;
            $cleanTitle = str_replace(trim($this->site->name), '', $cleanTitle);
            $cleanTitle = str_replace(['まとめ', '速報', 'アンテナ'], '', $cleanTitle);
            $cleanTitle = preg_replace('/[\s\-:|：｜！\!\?？]+$/u', '', $cleanTitle);
            $cleanTitle = preg_replace('/^[\s\-:|：｜！\!\?？]+/u', '', $cleanTitle);
            $cleanTitle = trim($cleanTitle);

            Log::info("[Process: {$this->url}] タイトル洗浄: 【前】{$title} -> 【後】{$cleanTitle}");

            $title = ! empty($cleanTitle) ? $cleanTitle : $title;

            if (empty($title)) {
                Log::warning("[Process: {$this->url}] タイトルが空のためスキップします");

                return;
            }

            // 3. AI カテゴリ分類・タイトルリライト
            $categories = $this->site->app->categories->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
            ])->toArray();

            Log::info("[Process: {$this->url}] AI({$aiDriver})へタイトルリライトとカテゴリ推論をリクエスト中...");
            $aiResult = app(ArticleAiService::class)->classifyAndRewrite($title, $categories, $aiDriver, $this->site->app);

            if (empty($aiResult['rewritten_title'])) {
                throw new Exception('AI returned empty rewritten_title');
            }

            // 4. DB保存
            Article::firstOrCreate(
                ['url' => $this->url],
                [
                    'app_id' => $this->site->app_id,
                    'site_id' => $this->site->id,
                    'category_id' => $aiResult['category_id'],
                    'title' => $aiResult['rewritten_title'],
                    'original_title' => $title,
                    'thumbnail_url' => $thumbnailUrl,
                    'published_at' => $publishedAt,
                    'fetch_source' => $this->fetchSource,
                ]
            );

            Log::info("[Process: {$this->url}] 記事の保存が完了しました (カテゴリID: {$aiResult['category_id']})");

            $this->output = "AI Processing completed successfully. Mapped to category_id: {$aiResult['category_id']}";

        } catch (\Throwable $e) {
            Log::error("[ProcessArticleJob] Job Error: [{$this->url}] ".$e->getMessage()."\n".$e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * あらゆる形式の文字列から日時をパースし、安全にCarbonインスタンスを返します。
     */
    private function parseDateString(?string $rawDate): Carbon
    {
        if (empty($rawDate)) {
            return now();
        }

        $cleanedDate = preg_replace('/[（\(][日月火水木金土祝][）\)]/u', '', $rawDate);
        $cleanedDate = trim($cleanedDate);

        try {
            return Carbon::parse($cleanedDate);
        } catch (Exception $e) {
            if (preg_match('/(20\d{2})[年\/\-\s]+(\d{1,2})[月\/\-\s]+(\d{1,2})日?\s*(?:(\d{1,2})[:時](\d{1,2})(?::(\d{1,2}))?)?/', $cleanedDate, $matches)) {
                try {
                    return Carbon::create(
                        $matches[1],
                        $matches[2],
                        $matches[3],
                        $matches[4] ?? '00',
                        $matches[5] ?? '00',
                        $matches[6] ?? '00'
                    );
                } catch (Exception $e2) {
                    Log::warning('ProcessArticleJob: Regex date creation failed. Raw text: '.$rawDate);
                }
            } else {
                Log::warning('ProcessArticleJob: 日付のパースに失敗しました: '.$rawDate);
            }
        }

        return now();
    }
}
