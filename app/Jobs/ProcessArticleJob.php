<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\CleanArticleTitleAction;
use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use App\Services\ArticleAiService;
use App\Services\ArticleMetadataResolverService;
use App\Services\ArticleScraperService;
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

class ProcessArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 600;

    public ?string $output = null;

    protected Site $site;

    public function __construct(
        public readonly int $siteId,
        public readonly string $url,
        public readonly array $metaData = [],
        public readonly ?string $fetchSource = null
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(md5($this->url)))
                ->releaseAfter(60)
                ->expireAfter(900),
        ];
    }

    public function handle(
        ArticleAiService $aiService,
        ArticleScraperService $scraper,
        CleanArticleTitleAction $cleanTitleAction,
        ArticleMetadataResolverService $metadataResolver,
    ): void {
        $this->shareLogContext();

        try {
            if (Cache::get('is_bulk_paused', false)) {
                $this->release(60);

                return;
            }

            $site = Site::with('app.categories')->find($this->siteId);
            if (! $site || ! $site->app instanceof AppModel) {
                Log::warning("ProcessArticleJob: Site ID {$this->siteId} or App not found.");

                return;
            }

            $app = $site->app;
            $this->site = $site;
            $this->shareLogContext($site);

            if (Article::where('url', $this->url)->exists()) {
                return;
            }

            $metaData = $metadataResolver->resolve(
                scraper: $scraper,
                url: $this->url,
                rawMetaData: $this->metaData,
                site: $this->site,
                logPrefix: "[Process: {$this->url}]",
            );

            $title = $cleanTitleAction->execute($metaData['title'], $this->site->name);

            // ② AI APIの無駄打ち防止: タイトルが短すぎる場合はAI呼び出し自体をスキップ
            if (empty($title) || mb_strlen($title) < 5) {
                Log::warning("[Process: {$this->url}] タイトルが空または5文字未満のためAI呼び出しをスキップします");

                return;
            }

            Log::info("[Process: {$this->url}] タイトル洗浄: 》前「{$metaData['title']} -> 」後「{$title}");

            $aiResult = $this->classifyAndRewriteTitle($aiService, $title, $app);
            $this->saveArticle($aiResult, $title, $metaData);

        } catch (Throwable $e) {
            report($e);

            Log::error('[ProcessArticleJob] Job Error', [
                'site_id' => $this->siteId,
                'url' => $this->url,
                'message' => $e->getMessage(),
            ]);

            if ($this->isTransientException($e) && $this->attempts() < $this->tries) {
                Log::warning('[ProcessArticleJob] 一時的な通信エラーのため再試行します', [
                    'site_id' => $this->siteId,
                    'url' => $this->url,
                    'attempt' => $this->attempts(),
                    'max_attempts' => $this->tries,
                ]);
                $this->release(60);

                return;
            }

            $this->fail($e);
        }
    }

    /**
     * @return array{category_id: int, rewritten_title: string}
     *
     * @throws Exception
     */
    private function classifyAndRewriteTitle(ArticleAiService $aiService, string $title, AppModel $app): array
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
        $aiResult = $aiService->classifyAndRewrite($title, $categories, $app);

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

        Log::info("[Process: {$this->url}] 記事の保存が完了しました (カテゴリID: {$aiResult['category_id']}, リライト後: {$aiResult['rewritten_title']})");
        $this->output = "AI Processing completed successfully. Mapped to category_id: {$aiResult['category_id']}";
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
