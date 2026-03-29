<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Site;
use App\Services\ArticleAiService;
use App\Services\ArticleScraperService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
            $rawPublishedAt = $this->metaData['published_at'] ?? null;
            $publishedAt = $rawPublishedAt ? $this->parseDateString($rawPublishedAt)->toDateTimeString() : null;

            // タイトル、サムネイル、公開日時のいずれかが欠損している場合はスクレイピングを実行
            $needsScraping = empty($title) || empty($thumbnailUrl) || empty($publishedAt);

            if ($needsScraping) {
                try {
                    Log::info("[Process: {$this->url}] 不足データ(title/thumbnail/date)の補完のためHTMLスクレイピングを開始します");
                    $scraper = app(ArticleScraperService::class);
                    $scrapeResult = $scraper->scrape($this->url, $this->site->date_selector ?? null);

                    if ($scrapeResult['success']) {
                        if (empty($title) && ! empty($scrapeResult['data']['title'])) {
                            $title = $scrapeResult['data']['title'];
                            Log::info("[Process: {$this->url}] スクレイピングでタイトルを補完しました");
                        }
                        if (empty($thumbnailUrl) && ! empty($scrapeResult['data']['image'])) {
                            $thumbnailUrl = $scrapeResult['data']['image'];
                            Log::info("[Process: {$this->url}] スクレイピングで画像を補完しました");
                        }
                        if (empty($publishedAt) && ! empty($scrapeResult['data']['date'])) {
                            $publishedAt = $scrapeResult['data']['date'];
                            Log::info("[Process: {$this->url}] スクレイピングで日付を補完しました [{$publishedAt}]");
                        }
                    } else {
                        Log::warning("[Process: {$this->url}] スクレイピング補完に失敗しました: ".($scrapeResult['error_message'] ?? '不明なエラー'));
                    }
                } catch (Exception $e) {
                    Log::error("ProcessArticleJob: Failed to fetch/parse metadata for URL {$this->url} - ".$e->getMessage());
                }
            }

            // 最終的に publishedAt が空なら現在時刻をセット
            if (empty($publishedAt)) {
                $publishedAt = now()->toDateTimeString();
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
