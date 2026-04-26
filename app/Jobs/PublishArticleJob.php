<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\AiAnalyzedData;
use App\DTOs\ScrapedArticleData;
use App\Models\Article;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $siteId,
        public readonly string $url,
        public readonly ?string $fetchSource = null
    ) {}

    public function handle(): void
    {
        $this->shareLogContext();

        try {
            $hash = md5($this->url);

            if (Cache::get("article_process_skip_{$hash}")) {
                $this->cleanupCaches($hash);

                return;
            }

            $site = Site::find($this->siteId);
            if (! $site) {
                $this->cleanupCaches($hash);

                return;
            }
            $this->shareLogContext($site);

            /** @var ScrapedArticleData|null $articleData */
            $articleData = Cache::get("article_scrape_data_{$hash}");
            /** @var string|null $originalTitle */
            $originalTitle = Cache::get("article_original_title_{$hash}");
            /** @var AiAnalyzedData|null $aiResult */
            $aiResult = Cache::get("article_ai_result_{$hash}");

            if (! $articleData || ! $aiResult || ! $originalTitle) {
                throw new \Exception("Required data missing in cache for URL: {$this->url}");
            }

            $this->saveArticle($site, $aiResult, $originalTitle, $articleData);

            $this->cleanupCaches($hash);

        } catch (Throwable $e) {
            report($e);

            Log::error('[PublishArticleJob] Job Error', [
                'site_id' => $this->siteId,
                'url' => $this->url,
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    private function saveArticle(Site $site, AiAnalyzedData $aiResult, string $originalTitle, ScrapedArticleData $metaData): void
    {
        Article::firstOrCreate(
            ['url' => $this->url],
            [
                'app_id' => $site->app_id,
                'site_id' => $site->id,
                'category_id' => $aiResult->categoryId,
                'title' => $aiResult->rewrittenTitle,
                'original_title' => $originalTitle,
                'thumbnail_url' => $metaData->image,
                'published_at' => $metaData->date,
                'fetch_source' => $this->fetchSource,
            ]
        );

        Log::info("[Process: {$this->url}] 記事の保存が完了しました (カテゴリID: {$aiResult->categoryId}, リライト後: {$aiResult->rewrittenTitle})");
    }

    private function shareLogContext(?Site $site = null): void
    {
        Log::withContext([
            'site_id' => $site?->getKey() ?? $this->siteId,
            'app_id' => $site?->app_id,
            'app_slug' => (string) data_get($site, 'app.api_slug'),
            'url' => $this->url,
        ]);
    }

    private function cleanupCaches(string $hash): void
    {
        Cache::forget("article_process_skip_{$hash}");
        Cache::forget("article_scrape_data_{$hash}");
        Cache::forget("article_original_title_{$hash}");
        Cache::forget("article_ai_result_{$hash}");
    }
}
