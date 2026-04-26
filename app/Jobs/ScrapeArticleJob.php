<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\CleanArticleTitleAction;
use App\DTOs\ScrapedArticleData;
use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Site;
use App\Services\ArticleMetadataResolverService;
use App\Services\ArticleScraperService;
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

class ScrapeArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    protected Site $site;

    public function __construct(
        public readonly int $siteId,
        public readonly string $url,
        public readonly array $metaData = []
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(md5($this->url.'_scrape')))
                ->releaseAfter(60)
                ->expireAfter(900),
        ];
    }

    public function handle(
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
                Log::warning("ScrapeArticleJob: Site ID {$this->siteId} or App not found.");
                $this->markAsSkip();

                return;
            }

            $this->site = $site;
            $this->shareLogContext($site);

            if (Article::where('url', $this->url)->exists()) {
                $this->markAsSkip();

                return;
            }

            $metaData = $metadataResolver->resolve(
                scraper: $scraper,
                url: $this->url,
                rawMetaData: $this->metaData,
                site: $this->site,
                logPrefix: "[Process: {$this->url}]",
            );

            $title = $cleanTitleAction->execute((string) $metaData->title, $this->site->name);

            // ② AI APIの無駄打ち防止: タイトルが短すぎる場合はAI呼び出し自体をスキップ
            if (empty($title) || mb_strlen($title) < 5) {
                Log::warning("[Process: {$this->url}] タイトルが空または5文字未満のためAI呼び出しをスキップします");
                $this->markAsSkip();

                return;
            }

            // ③ NGキーワードのチェック
            if ($this->containsNgKeyword($title) || $this->containsNgKeyword($metaData->title)) {
                Log::warning("[Process: {$this->url}] NGキーワードが含まれているため保存をスキップします");
                $this->markAsSkip();

                return;
            }

            Log::info("[Process: {$this->url}] タイトル洗浄: 》前「{$metaData->title} -> 」後「{$title}");

            $aiData = new ScrapedArticleData(
                url: $metaData->url,
                title: $title,
                image: $metaData->image,
                date: $metaData->date,
                success: $metaData->success,
                errorMessage: $metaData->errorMessage,
            );

            // チェーン後続処理のためにキャッシュにデータを保存 (有効期限2時間)
            $hash = md5($this->url);
            Cache::put("article_scrape_data_{$hash}", $aiData, now()->addHours(2));
            Cache::put("article_original_title_{$hash}", $title, now()->addHours(2));

        } catch (Throwable $e) {
            report($e);

            Log::error('[ScrapeArticleJob] Job Error', [
                'site_id' => $this->siteId,
                'url' => $this->url,
                'message' => $e->getMessage(),
            ]);

            if ($this->isTransientException($e) && $this->attempts() < $this->tries) {
                Log::warning('[ScrapeArticleJob] 一時的な通信エラーのため再試行します', [
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

    private function markAsSkip(): void
    {
        Cache::put('article_process_skip_'.md5($this->url), true, now()->addHours(2));
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

    private function containsNgKeyword(?string $title): bool
    {
        if (empty($title)) {
            return false;
        }

        $ngKeywordsStr = Cache::get('ng_keywords', 'PR,AD,スポンサーリンク');
        $ngKeywords = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $ngKeywordsStr)));

        foreach ($ngKeywords as $keyword) {
            if (mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
