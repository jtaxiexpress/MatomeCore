<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Site;
use App\Models\Article;
use App\Services\ArticleAiService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Exception;
use Illuminate\Support\Facades\Log;

class ProcessArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public ?string $output = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Site $site,
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
        // キルスイッチ: 一時停止フラグが立っている場合はジョブを保留して60秒後に再試行
        if (\Illuminate\Support\Facades\Cache::get('is_bulk_paused', false)) {
            $this->release(60);
            return;
        }

        $this->site->loadMissing('app.categories');
        if (!$this->site->app) {
            Log::warning("ProcessArticleJob: App not found for Site ID {$this->site->id}");
            return;
        }
        
        $site = $this->site;

        // 1. Check if article already exists
        if (Article::where('url', $this->url)->exists()) {
            return;
        }

        $title = $this->metaData['raw_title'] ?? null;
        $thumbnailUrl = $this->metaData['thumbnail_url'] ?? null;
        $publishedAt = $this->metaData['published_at'] ?? now()->toDateTimeString();

        // 2. Fetch basic OG metadata if title or thumbnail
        $needsScraping = empty($title) || empty($thumbnailUrl) || empty($this->metaData['published_at']);

        if ($needsScraping) {
            try {
                \Illuminate\Support\Facades\Log::info("[Process: {$this->url}] HTMLスクレイピングを開始します");
                $response = \Illuminate\Support\Facades\Http::withHeaders([
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
                                if (!empty($src)) {
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
                        if (!empty($site->date_selector)) {
                            $dateSelectors[] = ['selector' => $site->date_selector, 'attr' => '_text'];
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
                                if (!empty(trim($val))) {
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

                        if (!empty($extractedVal)) {
                            \Illuminate\Support\Facades\Log::info("[Process: {$this->url}] 日付文字列 [{$extractedVal}] のパース処理中...");
                            $publishedAt = $this->parseDateString($extractedVal)->toDateTimeString();
                        } else {
                            $publishedAt = now()->toDateTimeString();
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::info("[Process: {$this->url}] 日付文字列 [{$this->metaData['published_at']}] のパース処理中...");
                        $publishedAt = $this->parseDateString($this->metaData['published_at'])->toDateTimeString();
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("ProcessArticleJob: Failed to fetch metadata for URL {$this->url} - " . $e->getMessage());
                // Fallback in case of absolute failure
                $publishedAt = now()->toDateTimeString();
            }
        } else {
            // metaDataが存在する場合もパースを確実に行う
            if (!empty($this->metaData['published_at'])) {
                \Illuminate\Support\Facades\Log::info("[Process: {$this->url}] 日付文字列 [{$this->metaData['published_at']}] のパース処理中...");
                $publishedAt = $this->parseDateString($this->metaData['published_at'])->toDateTimeString();
            } else {
                $publishedAt = now()->toDateTimeString();
            }
        }

        // 2.5 Title Cleaning
        $cleanTitle = $title;

        // 1. サイト名を問答無用で空文字に置換（一番確実）
        $cleanTitle = str_replace(trim($site->name), '', $cleanTitle);

        // 2. サイト名に付随しがちな「まとめ」「速報」などの頻出ノイズも念のため消去
        $cleanTitle = str_replace(['まとめ', '速報', 'アンテナ'], '', $cleanTitle);

        // 3. サイト名が抜けたことで取り残された末尾の記号（ : | - ！ ? 空白 など）を根こそぎ削除
        $cleanTitle = preg_replace('/[\s\-:|：｜！\!\?？]+$/u', '', $cleanTitle);

        // 4. 先頭に取り残された記号も削除
        $cleanTitle = preg_replace('/^[\s\-:|：｜！\!\?？]+/u', '', $cleanTitle);

        // 5. 仕上げのトリム
        $cleanTitle = trim($cleanTitle);

        Log::info("[Process: {$this->url}] タイトル洗浄: 【前】{$title} -> 【後】{$cleanTitle}");

        $title = !empty($cleanTitle) ? $cleanTitle : $title;

        if (empty($title)) {
            Log::warning("ProcessArticleJob: Could not determine title for URL {$this->url}. Skipping.");
            return;
        }

        // 3. AI Categorization and Rewriting (Lightweight)
        $categories = $site->app->categories->map(function ($cat) {
            return ['id' => $cat->id, 'name' => $cat->name];
        })->toArray();

        $categoryId = null;
        // 3. AI Processing
        \Illuminate\Support\Facades\Log::info("[Process: {$this->url}] AI({$this->aiDriver})へタイトルリライトとカテゴリ推論をリクエスト中...");
        $aiService = app(ArticleAiService::class);
        $aiResult = $aiService->classifyAndRewrite($title, $categories, $this->aiDriver, $site->app);
            
        // Ensure output is valid, otherwise throw exception to trigger explicit failure & logging
        if (empty($aiResult['rewritten_title'])) {
            throw new Exception("AI returned empty rewritten_title");
        }
        
        $categoryId = $aiResult['category_id'] ?? null;
        $rewrittenTitle = $aiResult['rewritten_title'];

        // 4. Save to Database (Skip heavy content / markdown fields)
        Article::firstOrCreate(
            ['url' => $this->url],
            [
                'app_id'       => $site->app_id,
                'site_id'      => $site->id,
                'category_id'  => $aiResult['category_id'],
                'title'        => $aiResult['rewritten_title'],
                'original_title' => $title,
                'thumbnail_url'=> $thumbnailUrl,
                'published_at' => $publishedAt,
                'fetch_source' => $this->fetchSource,
            ]
        );

        \Illuminate\Support\Facades\Log::info("[Process: {$this->url}] 記事の保存が完了しました (カテゴリID: {$aiResult['category_id']})");

        $this->output = "AI Processing completed successfully. Mapped to category_id: {$aiResult['category_id']}";
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new \Illuminate\Queue\Middleware\WithoutOverlapping($this->url)];
    }

    /**
     * あらゆる形式の文字列から日時をパースし、安全にCarbonインスタンスを返します。
     *
     * @param string|null $rawDate
     * @return \Carbon\Carbon
     */
    private function parseDateString(?string $rawDate): \Carbon\Carbon
    {
        if (empty($rawDate)) {
            return now();
        }

        // ノイズの除去（全角半角の空白や、曜日の削除）
        $cleanedDate = preg_replace('/[（\(][日月火水木金土祝][）\)]/u', '', $rawDate);
        $cleanedDate = trim($cleanedDate);

        try {
            return \Carbon\Carbon::parse($cleanedDate);
        } catch (\Exception $e) {
            // ISO 8601等標準フォーマットでパース失敗した場合、正規表現で日時を強制抽出
            if (preg_match('/(20\d{2})[年\/\-\s]+(\d{1,2})[月\/\-\s]+(\d{1,2})日?\s*(?:(\d{1,2})[:時](\d{1,2})(?::(\d{1,2}))?)?/', $cleanedDate, $matches)) {
                try {
                    $hour = $matches[4] ?? '00';
                    $minute = $matches[5] ?? '00';
                    $second = $matches[6] ?? '00';
                    return \Carbon\Carbon::create($matches[1], $matches[2], $matches[3], $hour, $minute, $second);
                } catch (\Exception $e2) {
                    \Illuminate\Support\Facades\Log::warning('ProcessArticleJob: Regex date creation failed. Raw text: ' . $rawDate);
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('ProcessArticleJob: 日付のパースに失敗しました: ' . $rawDate);
            }
        }

        return now();
    }
}
