<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\CleanArticleTitleAction;
use App\Actions\SendArticleFetchResultNotificationAction;
use App\Models\Article;
use App\Models\Site;
use App\Services\ArticleAiService;
use App\Services\ArticleMetadataResolverService;
use App\Services\ArticleScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 複数の記事URLをまとめてAIバッチ処理するジョブ。
 *
 * 最大10件程度の記事を1回のAIリクエストでカテゴリ分類・タイトルリライトします。
 * ProcessArticleJob（1件ずつ処理）とは並存する設計で、既存ジョブは変更しません。
 */
class ProcessArticleBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, array{url: string, metaData: array<string, mixed>}>  $articles  処理対象の記事リスト
     */
    public function __construct(
        public readonly int $siteId,
        public readonly array $articles,
        public readonly ?string $fetchSource = null,
    ) {}

    public function handle(
        ArticleAiService $aiService,
        ArticleScraperService $scraper,
        CleanArticleTitleAction $cleanTitleAction,
        ArticleMetadataResolverService $metadataResolver,
    ): void {
        $this->shareLogContext();

        if (Cache::get('is_bulk_paused', false)) {
            $this->release(60);

            return;
        }

        $site = Site::with('app.categories')->find($this->siteId);

        if (! $site || ! $site->app) {
            Log::warning("[ProcessArticleBatchJob] Site ID {$this->siteId} またはAppが見つかりません。");

            return;
        }

        $this->shareLogContext($site);

        $categories = $site->app->categories->map(fn ($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
        ])->toArray();

        if (empty($categories)) {
            Log::warning("[ProcessArticleBatchJob] Site ID {$this->siteId} のAppにカテゴリが登録されていません。");

            return;
        }

        try {
            // ① メタデータ解決と前処理
            $validArticles = $this->resolveValidArticles($metadataResolver, $scraper, $cleanTitleAction, $site);

            if (empty($validArticles)) {
                Log::info("[ProcessArticleBatchJob] Site ID {$this->siteId}: 処理対象の有効な記事が0件のため終了します。");

                $this->notifyFetchResult(
                    site: $site,
                    savedCount: 0,
                    missedCount: 0,
                    detail: '処理対象の記事がありませんでした。',
                );

                return;
            }

            Log::info("[ProcessArticleBatchJob] Site ID {$this->siteId}: Ollamaに".count($validArticles).'件の記事をバッチ送信します。');

            // ② AIバッチ推論
            $aiResults = $aiService->classifyAndRewriteBatch($validArticles, $categories, $site->app);

            // ③ 結果の保存と漏れ検出
            $summary = $this->persistResults($validArticles, $aiResults, $site);

            $this->notifyFetchResult(
                site: $site,
                savedCount: $summary['savedCount'],
                missedCount: $summary['missedCount'],
            );

        } catch (\Throwable $e) {
            report($e);

            Log::error('[ProcessArticleBatchJob] Job Error', [
                'site_id' => $this->siteId,
                'message' => $e->getMessage(),
            ]);

            if (isset($site) && $site instanceof Site && $site->app) {
                $this->notifyFetchResult(
                    site: $site,
                    savedCount: 0,
                    missedCount: 0,
                    detail: '記事取得の処理中にエラーが発生しました: '.$e->getMessage(),
                    failed: true,
                );
            }

            $this->fail($e);
        }
    }

    /**
     * 各URLのメタデータを解決し、AI処理に進む有効な記事リストを返します。
     * DBに既存のURL・タイトルが短すぎる記事はスキップします。
     *
     * @return array<int, array{id: int, url: string, title: string, metaData: array{title: string|null, image: string|null, date: string}}>
     */
    private function resolveValidArticles(
        ArticleMetadataResolverService $metadataResolver,
        ArticleScraperService $scraper,
        CleanArticleTitleAction $cleanTitleAction,
        Site $site,
    ): array {
        // DB重複チェックを1クエリで一括実行
        $urls = array_column($this->articles, 'url');
        $existingUrls = Article::whereIn('url', $urls)->pluck('url')->flip()->all();

        $valid = [];
        $tempId = 1;

        foreach ($this->articles as $articleInput) {
            $url = $articleInput['url'];
            $rawMetaData = $articleInput['metaData'] ?? [];

            if (isset($existingUrls[$url])) {
                Log::info("[ProcessArticleBatchJob] スキップ（DB重複）: {$url}");

                continue;
            }

            $metaData = $metadataResolver->resolve(
                scraper: $scraper,
                url: $url,
                rawMetaData: $rawMetaData,
                site: $site,
                logPrefix: "[ProcessArticleBatchJob] {$url}",
            );
            $cleanedTitle = $cleanTitleAction->execute((string) ($metaData['title'] ?? ''), $site->name);

            if (empty($cleanedTitle) || mb_strlen($cleanedTitle) < 5) {
                Log::warning("[ProcessArticleBatchJob] タイトルが空または5文字未満のためスキップ: {$url}");

                continue;
            }

            $valid[] = [
                'id' => $tempId++,
                'url' => $url,
                'title' => $cleanedTitle,
                'metaData' => $metaData,
            ];
        }

        return $valid;
    }

    /**
     * AIバッチ結果を記事テーブルに保存します。
     * AI返答に含まれなかった記事はLog::warningで記録します。
     *
     * @param  array<int, array{id: int, url: string, title: string, metaData: array<string, mixed>}>  $validArticles
     * @param  array<int, array{category_id: int, rewritten_title: string}>  $aiResults
     * @return array{savedCount: int, missedCount: int}
     */
    private function persistResults(array $validArticles, array $aiResults, Site $site): array
    {
        $savedCount = 0;
        $missedCount = 0;
        $categoryNames = $site->app->categories->pluck('name', 'id');

        foreach ($validArticles as $article) {
            $tempId = $article['id'];
            $url = $article['url'];

            if (! isset($aiResults[$tempId])) {
                Log::warning('[ProcessArticleBatchJob] AI結果に含まれていなかった記事をスキップしました。', [
                    'url' => $url,
                    'temp_id' => $tempId,
                    'title' => $article['title'],
                ]);
                $missedCount++;

                continue;
            }

            $aiResult = $aiResults[$tempId];

            if (empty($aiResult['rewritten_title'])) {
                Log::warning("[ProcessArticleBatchJob] rewritten_titleが空のためスキップ: {$url}");
                $missedCount++;

                continue;
            }

            Article::firstOrCreate(
                ['url' => $url],
                [
                    'app_id' => $site->app_id,
                    'site_id' => $site->id,
                    'category_id' => $aiResult['category_id'],
                    'title' => $aiResult['rewritten_title'],
                    'original_title' => $article['title'],
                    'thumbnail_url' => $article['metaData']['image'],
                    'published_at' => $article['metaData']['date'],
                    'fetch_source' => $this->fetchSource,
                ]
            );

            $categoryName = $categoryNames->get($aiResult['category_id'], '不明');
            $originalTitle = $article['title'];

            Log::info(sprintf(
                '保存完了:| リライト前 %s | リライト後: %s | カテゴリID: %d(%s) | %s |',
                $originalTitle,
                $aiResult['rewritten_title'],
                $aiResult['category_id'],
                $categoryName,
                $url,
            ));
            $savedCount++;
        }

        Log::info("[ProcessArticleBatchJob] Site ID {$site->id}: 保存={$savedCount}件 / AI漏れ={$missedCount}件");

        return [
            'savedCount' => $savedCount,
            'missedCount' => $missedCount,
        ];
    }

    private function shareLogContext(?Site $site = null): void
    {
        Log::withContext([
            'site_id' => $site?->getKey() ?? $this->siteId,
            'app_id' => $site?->app_id,
            'app_slug' => (string) data_get($site, 'app.api_slug'),
            'batch_size' => count($this->articles),
        ]);
    }

    private function notifyFetchResult(
        Site $site,
        int $savedCount,
        int $missedCount,
        ?string $detail = null,
        bool $failed = false,
    ): void {
        app(SendArticleFetchResultNotificationAction::class)->execute(
            site: $site,
            fetchSource: $this->fetchSource ?? 'rss',
            savedCount: $savedCount,
            missedCount: $missedCount,
            detail: $detail,
            failed: $failed,
        );
    }
}
