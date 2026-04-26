<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\AiAnalyzedData;
use App\DTOs\ScrapedArticleData;
use App\Models\App as AppModel;
use App\Models\Category;
use App\Models\Site;
use App\Services\ArticleAiService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeArticleAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 5;

    public int $timeout = 600;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300]; // バックオフ制御: 30秒, 1分, 2分, 5分
    }

    public function __construct(
        public readonly int $siteId,
        public readonly string $url,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(md5($this->url.'_ai')))
                ->releaseAfter(600)
                ->expireAfter(3600),
        ];
    }

    public function handle(ArticleAiService $aiService): void
    {
        $this->shareLogContext();

        try {
            $hash = md5($this->url);
            if (Cache::get("article_process_skip_{$hash}")) {
                return; // 前のジョブでスキップフラグが立っている場合は即終了
            }

            if (Cache::get('is_bulk_paused', false)) {
                $this->release(60);

                return;
            }

            $site = Site::with('app')->find($this->siteId);
            if (! $site || ! $site->app instanceof AppModel) {
                return;
            }

            $app = $site->app;
            $this->shareLogContext($site);

            /** @var ScrapedArticleData|null $articleData */
            $articleData = Cache::get("article_scrape_data_{$hash}");

            if (! $articleData) {
                throw new Exception("Scraped data missing in cache for URL: {$this->url}");
            }

            $aiResult = $this->classifyAndRewriteTitle($aiService, $articleData, $app);

            Cache::put("article_ai_result_{$hash}", $aiResult, now()->addHours(2));

        } catch (Throwable $e) {
            report($e);

            Log::error('[AnalyzeArticleAiJob] Job Error', [
                'site_id' => $this->siteId,
                'url' => $this->url,
                'message' => $e->getMessage(),
            ]);

            if ($this->isTransientException($e) && $this->attempts() < $this->tries) {
                Log::warning('[AnalyzeArticleAiJob] 一時的な通信エラー/タイムアウトのため再試行します', [
                    'url' => $this->url,
                    'attempt' => $this->attempts(),
                    'max_attempts' => $this->tries,
                ]);
                // throw することで Laravel Queue の backoff ロジックに乗せる
                throw $e;
            }

            $this->fail($e);
        }
    }

    /**
     * @throws Exception
     */
    private function classifyAndRewriteTitle(ArticleAiService $aiService, ScrapedArticleData $articleData, AppModel $app): AiAnalyzedData
    {
        $categories = Category::query()
            ->select(['id', 'name'])
            ->where('app_id', $app->id)
            ->get()
            ->map(static fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all();

        Log::info("[Process: {$this->url}] AI(Ollama)へタイトルリライトとカテゴリ推論をリクエスト中...");
        $aiResult = $aiService->classifyAndRewrite($articleData, $categories, $app);

        if (empty($aiResult->rewrittenTitle)) {
            throw new Exception('AI returned empty rewritten_title');
        }

        return $aiResult;
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

    private function isTransientException(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException || $exception instanceof RequestException;
    }
}
