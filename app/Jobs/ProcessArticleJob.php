<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\CleanArticleTitleAction;
use App\Models\Article;
use App\Models\Site;
use App\Services\ArticleAiService;
use App\Services\ArticleScraperService;
use App\Support\DateParser;
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

    protected Site $site;

    public function __construct(
        public readonly int $siteId,
        public readonly string $url,
        public readonly array $metaData = [],
        public readonly string $aiDriver = 'gemini',
        public readonly ?string $fetchSource = null
    ) {}

    public function handle(
        ArticleAiService $aiService,
        ArticleScraperService $scraper,
        CleanArticleTitleAction $cleanTitleAction
    ): void {
        try {
            if (Cache::get('is_bulk_paused', false)) {
                $this->release(60);

                return;
            }

            $site = Site::with('app.categories')->find($this->siteId);
            if (! $site || ! $site->app) {
                Log::warning("ProcessArticleJob: Site ID {$this->siteId} or App not found.");

                return;
            }
            $this->site = $site;

            if (Article::where('url', $this->url)->exists()) {
                return;
            }

            $metaData = $this->resolveMetaData($scraper);

            $title = $cleanTitleAction->execute($metaData['title'], $this->site->name);
            if (empty($title)) {
                Log::warning("[Process: {$this->url}] タイトルが空のためスキップします");

                return;
            }

            Log::info("[Process: {$this->url}] タイトル洗浄: 【前】{$metaData['title']} -> 【後】{$title}");

            $aiResult = $this->classifyAndRewriteTitle($aiService, $title);
            $this->saveArticle($aiResult, $title, $metaData);

        } catch (\Throwable $e) {
            Log::error("[ProcessArticleJob] Job Error: [{$this->url}] ".$e->getMessage()."\n".$e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * @return array{title: string|null, image: string|null, date: string}
     */
    private function resolveMetaData(ArticleScraperService $scraper): array
    {
        $title = $this->metaData['raw_title'] ?? null;
        $thumbnailUrl = $this->metaData['thumbnail_url'] ?? null;
        $rawPublishedAt = $this->metaData['published_at'] ?? null;
        $publishedAt = $rawPublishedAt ? DateParser::parse($rawPublishedAt)?->toDateTimeString() : null;

        $needsScraping = empty($title) || empty($thumbnailUrl) || empty($publishedAt);

        if ($needsScraping) {
            try {
                Log::info("[Process: {$this->url}] 不足データ(title/thumbnail/date)の補完のためHTMLスクレイピングを開始します");
                $scrapeResult = $scraper->scrape($this->url, $this->site->date_selector ?? null);

                if ($scrapeResult['success']) {
                    $title = empty($title) && ! empty($scrapeResult['data']['title']) ? $scrapeResult['data']['title'] : $title;
                    $thumbnailUrl = empty($thumbnailUrl) && ! empty($scrapeResult['data']['image']) ? $scrapeResult['data']['image'] : $thumbnailUrl;
                    $publishedAt = empty($publishedAt) && ! empty($scrapeResult['data']['date']) ? $scrapeResult['data']['date'] : $publishedAt;
                } else {
                    Log::warning("[Process: {$this->url}] スクレイピング補完に失敗しました: ".($scrapeResult['error_message'] ?? '不明なエラー'));
                }
            } catch (Exception $e) {
                Log::error("ProcessArticleJob: Failed to fetch/parse metadata for URL {$this->url} - ".$e->getMessage());
            }
        }

        return [
            'title' => $title,
            'image' => $thumbnailUrl,
            'date' => $publishedAt ?: now()->toDateTimeString(),
        ];
    }

    /**
     * @return array{category_id: int, rewritten_title: string}
     *
     * @throws Exception
     */
    private function classifyAndRewriteTitle(ArticleAiService $aiService, string $title): array
    {
        $aiDriver = $this->aiDriver ?: ($this->site->app->ai_driver ?? config('ai.default', 'gemini'));

        $categories = $this->site->app->categories->map(fn ($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
        ])->toArray();

        Log::info("[Process: {$this->url}] AI({$aiDriver})へタイトルリライトとカテゴリ推論をリクエスト中...");
        $aiResult = $aiService->classifyAndRewrite($title, $categories, $aiDriver, $this->site->app);

        if (empty($aiResult['rewritten_title'])) {
            throw new Exception('AI returned empty rewritten_title');
        }

        return $aiResult;
    }

    /**
     * @param  array{category_id: int, rewritten_title: string}  $aiResult
     * @param  array{title: string|null, image: string|null, date: string}  $metaData
     */
    private function saveArticle(array $aiResult, string $originalTitle, array $metaData): void
    {
        Article::firstOrCreate(
            ['url' => $this->url],
            [
                'app_id' => $this->site->app_id,
                'site_id' => $this->site->id,
                'category_id' => $aiResult['category_id'],
                'title' => $aiResult['rewritten_title'],
                'original_title' => $originalTitle,
                'thumbnail_url' => $metaData['image'],
                'published_at' => $metaData['date'],
                'fetch_source' => $this->fetchSource,
            ]
        );

        Log::info("[Process: {$this->url}] 記事の保存が完了しました (カテゴリID: {$aiResult['category_id']})");
        $this->output = "AI Processing completed successfully. Mapped to category_id: {$aiResult['category_id']}";
    }
}
